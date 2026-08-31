<?php
/**
 * **************************************************************************
 *    @package    plg_system_techspuur                                     **
 *    @author     Manuel Häusler <tech.spuur@quickline.ch>                 **
 *    @copyright  2026 Manuel Haeusler                                     **
 *    @license    GNU General Public License version 3 or later            **
 * **************************************************************************
 */

namespace Elfangor93\Plugin\System\Techspuur\Extension;

// No direct access
\defined('_JEXEC') || die;

use Joomla\CMS\Application\CMSWebApplicationInterface;
use Joomla\CMS\Date\Date;
use Joomla\CMS\Factory;
use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\ParameterType;
use Joomla\Event\DispatcherInterface;
use Joomla\Event\Event;
use Joomla\Event\Priority;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;

/**
 * Plugin class for the Tech.Spuur Extensions Framework
 *
 * @since  1.0.0
 */
class TechSpuur extends CMSPlugin implements SubscriberInterface
{
  private const EXTENSIONS_URL = 'https://updates.spuur.ch/extensions.xml';

  private const LICENSE_PATH = '/index.php?option=com_sesamepayforaccess&view=licensevalidate&format=json';

  /**
   * Refresh interval in seconds.
   * How long to wait until we chek the license the next time.
   *
   * @var    integer
   * @since  1.0.0
   */
  public $refresh_rate = 43200;

  /**
   * Load plugin language files automatically
   *
   * @var    boolean
   * @since  1.0.0
   */
  protected $autoloadLanguage = true;

  /**
   * Global database object
   *
   * @var   \Joomla\Database\DatabaseDriver
   * @since  1.0.0
   */
  protected $db = null;

  /**
   * Context
   *
   * @var   string
   * @since  1.0.3
   */
  protected static $context = '';

  /**
   * Extension id
   *
   * @var   int
   */
  protected $id = 0;

  /**
   * Storage for extensions XML
   *
   * @var    \SimpleXMLElement
   * @since  1.0.0
   */
  protected static $extensions = null;

  /**
   * Source of the currently loaded extensions XML: downloaded, cache or bundled.
   *
   * @var string
   */
  protected static $extensionsSource = '';

  /**
   * Whether the insecure local-development TLS warning has already been shown.
   *
   * @var bool
   */
  protected static $tlsWarningShown = false;

  /**
   * Storage for extension data
   * [<extension_id> => Registry, ...]
   *
   * @var    array
   * @since  1.0.0
   */
  protected static $data = [];

  /**
   * Storage for extension data
   * ['com_joomgallery' => [id1, id2, ...], ...]
   *
   * @var    array
   * @since  1.0.0
   */
  protected static $dependencies = [];

  /**
   * Constructor
   *
   * @param   DispatcherInterface  $dispatcher  The event dispatcher
   * @param   array                $config      An optional associative array of configuration settings.
   *
   * @return  void
   * @since   1.0.0
   */
  function __construct($dispatcher, $config)
  {
    parent::__construct($dispatcher, $config);

    $this->id = $config['id'];

    if(key_exists('params', $config))
    {
      $params    = json_decode($config['params']);
      $frequency = \intval($params->frequency);

      if($frequency && $frequency > 10800 && $frequency < 10510000)
      {
        $this->refresh_rate = $params->frequency;
      }
    }

    Log::addLogger(['text_file' => 'techspuur.php'], Log::ALL, ['techspuur']);
  }

  /**
   * Returns an array of events this subscriber will listen to.
   *
   * @return  array
   *
   * @since   1.0.0
   */
  public static function getSubscribedEvents(): array
  {
    return [
      'onAfterRoute'          => ['onAfterRoute', Priority::HIGH],
      'onExtensionBeforeSave' => ['onExtensionBeforeSave', Priority::HIGH],
      'onContentPrepareForm'  => ['onContentPrepareForm', Priority::HIGH],
    ];
  }

  /**
   * Adds the license validation to plugin form
   *
   * @return  void
   */
  public function onAfterRoute()
  {
    $app = $this->getApplication();

    // This feature only applies in the site and administrator applications
    if(
      !($app instanceof CMSWebApplicationInterface) ||
      (!$app->isClient('site') && !$app->isClient('administrator'))
    )
    {
      return;
    }

    // Load language
    $lang = Factory::getApplication()->getLanguage();
    $lang->load('plg_system_techspuur', JPATH_SITE . '/plugins/system/techspuur');

    // Update list of extensions
    $ids = $this->getExtensions();

    // Check licenses if needed
    foreach($ids as $id)
    {
      // Get extension data
      $ext = $this->getExtension($id);

      try
      {
        // Check the license of the extension
        $this->checkLicenseData($id, $ext->get('element'), $ext->get('name'));
      }
      catch(\Throwable $th)
      {
        // Checking license not possible, probably network problems
        $this->licenceCheckError($th);
      }

      // Compose list of dependent components
      $tmp        = new Registry();
      $dependence = $ext->get('custom_data', $tmp)->get('dependence', '');

      if($dependence)
      {
        if(strpos($dependence, 'com') !== false && !key_exists($dependence, self::$dependencies))
        {
          // Add new dependency to list
          self::$dependencies[$dependence] = [];
        }

        array_push(self::$dependencies[$dependence], $id);
      }
    }

    // Show license messages in dependent component backend
    $option = $app->getInput()->get('option');

    if( $app->isClient('administrator') &&
        $option && key_exists($option, self::$dependencies)
    )
    {
      foreach(self::$dependencies[$option] as $ext_id)
      {
        // Show message if we have an invalid license
        $extension = self::$data[$ext_id];

        if($app->getUserState($extension->get('element') . '.license.state', -1) !== 1)
        {
          $msg_type = $app->getUserState($extension->get('element') . '.license.msg-type', 'message');
          $msg_text = $app->getUserState($extension->get('element') . '.license.msg-text', '');
          $app->enqueueMessage($msg_text, $msg_type);
        }
        // Check if we have a compatible component version
        $version_com   = (string) $this->loadXMLFile($option)->version;
        $compatibility = (string) $this->loadXMLFile($extension->get('extension_id'))->compatibility;

        if($version_com && $compatibility && !preg_match('/^' . $compatibility . '/', $version_com))
        {
          // Incompatible component version
          $lang_prefix = strtoupper($extension->get('name'));
          $app->enqueueMessage(\sprintf(Text::_($lang_prefix . '_MSG_VERSION_HINT'), $version_com), 'error');
        }
      }
    }
  }

  /**
   * Event triggered before an item gets saved into the db.
   * Check if we want to force a license request.
   *
   * @param   Event   $event   Event instance
   *
   * @return  void
   */
  public function onExtensionBeforeSave(Event $event)
  {
    if(version_compare(JVERSION, '5.0.0', '<'))
    {
      // Joomla 4
      [$context, &$table, $isNew, $data] = $event->getArguments();
    }
    else
    {
      // Joomla 5 or newer
      $table = $event->getItem();
      $data  = $event->getData();
    }

    $extensionName = $table->name ?? $table->module ?? '';

    // Set context
    $this->guessContext($extensionName);

    if($extensionName == 'plg_system_techspuur')
    {
      // We are saving this plugin form
      $array  = ['create_log' => 'int', 'check_server' => 'int', 'refresh_list' => 'int', 'register_offline' => 'int'];
      $params = $this->getApplication()->getInput()->getArray(['jform' => ['params' => $array]]);
      $params = $params['jform']['params'];

      if($params['check_server'])
      {
        // Check the server availability
        $create_log = \boolval($params['create_log']);
        $this->checkLicenseServer($create_log);
      }
      elseif($params['refresh_list'])
      {
        // Refresh the list of extensions
        try
        {
          $proExtensions = $this->requestExtensionData(self::EXTENSIONS_URL, true);

          if($proExtensions)
          {
            // Update custom data
            $table->custom_data = $proExtensions->toString('json');
            $event->setArgument('subject', $table);

            $this->getApplication()->enqueueMessage(Text::_('PLG_SYSTEM_TECHSPUUR_SUCCESS_EXTENSION_LIST'), 'success');
          }
        }
        catch(\Exception $e)
        {
          $this->getApplication()->enqueueMessage(Text::sprintf('PLG_SYSTEM_TECHSPUUR_ERROR_XML', $e->getMessage()), 'error');
        }
      }
      elseif($params['register_offline'])
      {
        // Register an extension as offline

        // Parse the offlineuse file
        $file = \dirname(__FILE__) . '/offlineuse.txt';

        if(file_exists($file))
        {
          $arr_offlineuse = $this->parseOfflineuseTxt($file);
        }
        else
        {
          $arr_offlineuse = [];
        }

        // Remove "PRO" suffix (case-insensitive)
        // Only if it's at the end, with optional space before it
        // Convert to lowercase
        $clean = preg_replace('/\s* pro$/i', 'pro', $data['params']['offline']);
        $clean = strtolower($clean);

        // Add extension if not yet in array
        if(!\in_array($clean, $arr_offlineuse))
        {
          array_push($arr_offlineuse, $clean);
        }

        // Store it to $file. Overwrite existing content if any.
        file_put_contents($file, implode(',', $arr_offlineuse));
      }

      // Remove offline string if available
      $tmp_params = json_decode($table->params);

      if(!empty($tmp_params->offline))
      {
        $tmp_params->offline = '';
        $table->params       = json_encode($tmp_params);
      }

      return;
    }

    if(!$extensionName || !\in_array($extensionName, $this->getExtensions('names', false)))
    {
      return;
    }

    // Module configuration is stored per module instance. Keep the license
    // credentials in the module extension params so scheduled checks can use
    // them independently of any individual module instance.
    $extension = $this->getExtension($extensionName);

    if($extension->get('type') === 'module' && isset($data['params']))
    {
      $submittedParams = $data['params'] instanceof Registry ? $data['params']->toArray() : (array) $data['params'];
      $licenseParams   = [];

      foreach(['username', 'dlid'] as $key)
      {
        if(\array_key_exists($key, $submittedParams))
        {
          $licenseParams[$key] = $submittedParams[$key];
        }
      }

      if(!empty($licenseParams))
      {
        $this->setParamsData((int) $extension->get('extension_id'), $licenseParams);
      }
    }

    $params = $this->getApplication()->getInput()->getArray(['jform' => ['params' => ['force_update' => 'int']]]);

    if(key_exists('force_update', $params['jform']['params']))
    {
      $app = $this->getApplication();

      if(!($app instanceof CMSWebApplicationInterface) || !$app->isClient('administrator'))
      {
        return;
      }

      $forceUpdate = \boolval($params['jform']['params']['force_update']);
      $app->setUserState(strtolower($extensionName) . '.license.force_update', $forceUpdate);

      // Module publication is stored in #__modules, whereas Techspuur disables
      // the extension record. Re-enable that record before checking again.
      if($forceUpdate && $extension->get('type') === 'module')
      {
        $this->enable((int) $extension->get('extension_id'));
      }
    }
  }


  /**
   * Adds the license validation to plugin form
   *
   * @param   Event   $event   Event instance
   *
   * @return  void
   */
  public function onContentPrepareForm(Event $event)
  {
    $app = $this->getApplication();

    // Run this plugin only on the backend website
    if(!($app instanceof CMSWebApplicationInterface) || !$app->isClient('administrator'))
    {
      return;
    }

    if(version_compare(JVERSION, '5.0.0', '<'))
    {
      // Joomla 4
      [$form, $data] = array_values($event->getArguments());
    }
    else
    {
      // Joomla 5
      $form = $event->getForm();
      $data = $event->getData();
    }

    if(!$data || \is_array($data))
    {
      return;
    }

    // Plugins and components expose their identifier as "name", while module
    // forms expose it as "module".
    $extensionName = $data->name ?? $data->module ?? '';

    // Run this plugin only for tech.spuur extension forms
    if(!$extensionName || !\in_array($extensionName, $this->getExtensions('names')))
    {
      return;
    }

    // Set context
    $this->guessContext($extensionName);

    // Get the extension object
    $extension = $this->getExtension($extensionName);

    // A module form contains instance params. Display and validate with the
    // extension-global credentials used by automatic license checks.
    if($extension->get('type') === 'module')
    {
      $extensionParams = $this->getParamsData((int) $extension->get('extension_id'));

      foreach(['username', 'dlid'] as $key)
      {
        if($extensionParams->exists($key))
        {
          $data->params[$key] = $extensionParams->get($key);
        }
      }
    }

    // Reset all state variables
    $app->setUserState($extension->get('element') . '.fractions', null);
    $app->setUserState($extension->get('element') . '.license.msg-type', 'message');
    $app->setUserState($extension->get('element') . '.license.msg-text', '');
    $app->setUserState($extension->get('element') . '.request.date', null);
    $app->setUserState($extension->get('element') . '.license.state', null);

    if( key_exists('username', $data->params) && $data->params['username'] &&
        key_exists('dlid', $data->params) && $data->params['dlid'] &&
        $app->getUserState(strtolower($extension->get('name')) . '.license.force_update', false)
    )
    {
      $this->loadLanguageFile($extension->get('extension_id'));

      // Force a new license validation
      $data_params    = new Registry($data->params);
      $ressource_name = Text::_(strtoupper($extension->get('name')) . '_SPFA_RESSOURCE_NAME'); // Name of the SPFA ressource
      $this->requestLicenseData($extension->get('extension_id'), $data_params, $extension->get('element'), $ressource_name, true);
      $app->setUserState(strtolower($extension->get('name')) . '.license.force_update', false);
    }

    try
    {
      // Check the license of the extension
      $this->checkLicenseData($extension->get('extension_id'), $extension->get('element'), $extension->get('name'));
    }
    catch(\Exception $e)
    {
      // Checking license not possible, probably network problems
      $this->licenceCheckError($e);
    }

    // Display the license message
    $msg_type = $app->getUserState($extension->get('element') . '.license.msg-type', 'message');
    $msg_text = $app->getUserState($extension->get('element') . '.license.msg-text', '');
    $app->enqueueMessage($msg_text, $msg_type);

    return;
  }

  /**
   * Try to guess context
   *
   * @param   string   $form   Name of the form
   *
   * @return  string   Context
   *
   * @since   1.0.3
   */
  private function guessContext($form = ''): string
  {
    if(self::$context != '')
    {
      return self::$context;
    }

    // Read query variables
    $cont_arr = [];

    if($option = $this->getApplication()->getInput()->get('option', false))
    {
      array_push($cont_arr, $option);
    }

    if($view = $this->getApplication()->getInput()->get('view', false))
    {
      array_push($cont_arr, $view);
    }

    if($task = $this->getApplication()->getInput()->get('task', false))
    {
      array_push($cont_arr, $task);
    }

    if($form)
    {
      array_push($cont_arr, $form);
    }

    // Use application name instead
    if(empty($cont_arr))
    {
      array_push($cont_arr, $this->getApplication()->getName() . '.default');
    }

    self::$context = implode('.', $cont_arr);

    return self::$context;
  }

  /**
   * Handle an error during license check
   *
   * @param   string   $error   The error
   *
   * @since   1.0.3
   */
  private function licenceCheckError($error): void
  {
    $app     = $this->getApplication();
    $context = $this->guessContext();
    $ids     = $this->getExtensions();

    // In case license data can not be validated, we deactive the PRO extensions
    foreach($ids as $id)
    {
      $this->disable($id);
    }

    // Handle the error depending on application
    if(($app->isClient('site') || $app->isClient('administrator')))
    {
      if($app->getIdentity()->authorise('core.admin'))
      {
        // If we have admin permissions, we will show a warning with more information
        $app->enqueueMessage(Text::sprintf('PLG_SYSTEM_TECHSPUUR_ERROR_REQUEST_LICENSE_DATA', Text::_('PLG_SYSTEM_TECHSPUUR_ERROR_NO_INTERNET')), 'error');
      }
      elseif($app->getIdentity()->authorise('core.manage'))
      {
        // If we have admin permissions, we will show a warning
        $app->enqueueMessage(Text::sprintf('PLG_SYSTEM_TECHSPUUR_ERROR_REQUEST_LICENSE_DATA', Text::_('PLG_SYSTEM_TECHSPUUR_ERROR_NO_INTERNET_INFO')), 'warning');
      }
    }
  }

  /**
   * Reads out all activated Tech.Spuur extensions
   *
   * @param   string  $mode   ids: return IDs | names: return extension names
   *
   * @return  array   List of extension ids or names
   *
   * @since   1.0.0
   */
  private function getExtensions($mode = 'ids', $onlyEnabled = true): array
  {
    $cdata = $this->requestExtensionData(self::EXTENSIONS_URL);

    if($this->id > 0 && empty(self::$data))
    {
      if(!$cdata)
      {
        $cdata = $this->getCustomData($this->id, false);
      }
      $cdata = $cdata->toArray();

      $ids = [];

      if(key_exists('extensions', $cdata))
      {
        $ids = $cdata['extensions'];
      }

      if(!empty($ids))
      {
        $query = $this->db->getQuery(true);

        $query->select($this->db->quoteName(['extension_id', 'name', 'type', 'element', 'folder', 'params', 'custom_data']))
          ->from('#__extensions')
          ->where($this->db->quoteName('extension_id') . ' IN (' . implode(',', array_map('intval', $ids)) . ')');

        if($onlyEnabled)
        {
          $query->where($this->db->quoteName('enabled') . ' = ' . '1');
        }

        $this->db->setQuery($query);

        $extensions = [];

        try
        {
          $extensions = $this->db->loadObjectList('extension_id') ?: [];
        }
        catch(\Exception $e)
        {
          Log::add(Text::_('PLG_SYSTEM_TECHSPUUR_ERROR_EXTENSIONS', $e->getMessage()), Log::ERROR, 'techspuur');
        }

        foreach($extensions as $key => $extension)
        {
          $extensions[$key] = new Registry($extension);
        }

        self::$data = array_replace(self::$data, $extensions);
      }
    }

    // Return names
    if($mode == 'names')
    {
      $names = [];

      foreach(self::$data as $id => $extension)
      {
        $name = $extension->get('name');

        if(!key_exists($name, $names))
        {
          array_push($names, $name);
        }
      }

      return $names;
    }

    return array_keys(self::$data);
  }

  /**
   * Reads out one specific extension
   *
   * @param   string|int   $id      Extension recognition (id or name like 'plg_content_joomplupro')
   * @param   bool         $store   True to store the result in the data storage
   *
   * @return  Registry  custom data
   *
   * @since   1.0.0
   */
  private function getExtension($id, bool $store = true): Registry
  {
    if(\is_string($id))
    {
      $query = $this->db->getQuery(true);

      $query->select($this->db->quoteName('extension_id'))
        ->from('#__extensions')
        ->where($this->db->quoteName('name') . ' = :name')
        ->orWhere($this->db->quoteName('element') . ' = :element')
        ->bind(':name', $id, ParameterType::STRING)
        ->bind(':element', $id, ParameterType::STRING);

      $this->db->setQuery($query);

      try
      {
        $id = $this->db->loadResult();
      }
      catch(\Exception $e)
      {
        Log::add(Text::sprintf('PLG_SYSTEM_TECHSPUUR_ERROR_EXTENSION', $e->getMessage()), Log::ERROR, 'techspuur');
      }
    }

    if(!\is_int($id))
    {
      throw new \Exception('Either the ID or a unique name of the extension has to be provided.', 1);
    }

    if($id > 0 && !isset(self::$data[$id]))
    {
      $query = $this->db->getQuery(true);

      $query->select($this->db->quoteName(['extension_id', 'name', 'type', 'element', 'folder', 'client_id', 'params', 'custom_data']))
        ->from('#__extensions')
        ->where($this->db->quoteName('extension_id') . ' = :extension_id')
        ->bind(':extension_id', $id, ParameterType::INTEGER);

      $this->db->setQuery($query);

      try
      {
        $result = $this->db->loadObject();

        if($result === null)
        {
          throw new \RuntimeException('Extension with ID ' . $id . ' was not found.');
        }

        $extension = new Registry($result);
      }
      catch(\Throwable $e)
      {
        Log::add(Text::sprintf('PLG_SYSTEM_TECHSPUUR_ERROR_EXTENSION', $e->getMessage()), Log::ERROR, 'techspuur');

        throw $e;
      }

      if($store)
      {
        self::$data[$id] = $extension;
      }
    }
    else
    {
      $extension = self::$data[$id];
    }

    return $extension;
  }

  /**
   * Reads out the params of an extension from db
   *
   * @param   int    $id      Extension id
   * @param   bool   $store   True to store the result in the data storage
   *
   * @return  Registry  params data
   *
   * @since   1.0.0
   */
  private function getParamsData(int $id, bool $store = true): Registry
  {
    $extension = $this->getExtension($id, $store);
    $params    = $extension->get('params', '');

    if(\is_string($params))
    {
      $params = new Registry($params);

      if($store)
      {
        self::$data[$id]->set('params', $params);
      }
    }

    return $params;
  }

  /**
   * Stores extension-global parameters without replacing unrelated values.
   *
   * @param   int    $id      Extension ID
   * @param   array  $values  Parameter values to store
   *
   * @return  void
   *
   * @since   __DEPLOY_VERSION__
   */
  private function setParamsData(int $id, array $values): void
  {
    $params = $this->getParamsData($id);

    foreach($values as $key => $value)
    {
      $params->set($key, $value);
    }

    $query = $this->db->getQuery(true)
      ->update($this->db->quoteName('#__extensions'))
      ->set($this->db->quoteName('params') . ' = ' . $this->db->quote($params->toString('json')))
      ->where($this->db->quoteName('extension_id') . ' = :extension_id')
      ->bind(':extension_id', $id, ParameterType::INTEGER);

    $this->db->setQuery($query);

    try
    {
      $this->db->execute();
    }
    catch(\Exception $e)
    {
      Log::add(Text::sprintf('PLG_SYSTEM_TECHSPUUR_ERROR_EXTENSION', $e->getMessage()), Log::ERROR, 'techspuur');
    }
  }

  /**
   * Reads out the custom data of an extension from db
   *
   * @param   int    $id      Extension id
   * @param   bool   $store   True to store the result in the data storage
   *
   * @return  Registry  custom data
   *
   * @since   1.0.0
   */
  private function getCustomData(int $id, bool $store = true): Registry
  {
    $extension  = $this->getExtension($id, $store);
    $customData = $extension->get('custom_data', '');

    if(\is_string($customData))
    {
      $customData = new Registry($customData);

      if($store)
      {
        self::$data[$id]->set('custom_data', $customData);
      }
    }

    return $customData;
  }

  /**
   * Writes custom data of an extension to the db
   *
   * @param   int        $id       Extension id
   * @param   Registry   $data     The new custom data
   * @param   bool       $license  True if it is license data
   *
   * @return  void
   *
   * @since   1.0.0
   */
  private function setCustomData(int $id, Registry $data, $license = true)
  {
    $this->getExtension($id, $license);

    if($license && ($data->count() < 2 || !$data->exists('state')))
    {
      // There is no valid license data to be set
      Log::add(Text::sprintf('PLG_SYSTEM_TECHSPUUR_ERROR_CUSTOM_DATA', Text::_('PLG_SYSTEM_TECHSPUUR_ERROR_LICENSE_DATA')), Log::ERROR, 'techspuur');

      return;
    }

    if($license)
    {
      self::$data[$id]->set('custom_data', $data);
    }

    $query = $this->db->getQuery(true);

    $query->update($this->db->quoteName('#__extensions'))
      ->set($this->db->quoteName('custom_data') . ' = ' . $this->db->quote($data->toString('json')))
      ->where($this->db->quoteName('extension_id') . ' = :extension_id')
      ->bind(':extension_id', $id, ParameterType::INTEGER);

    $this->db->setQuery($query);

    try
    {
      $this->db->execute();
    }
    catch(\Exception $e)
    {
      Log::add(Text::sprintf('PLG_SYSTEM_TECHSPUUR_ERROR_CUSTOM_DATA', $e->getMessage()), Log::ERROR, 'techspuur');
    }
  }

  /**
   * Load language file of a specific extension
   *
   * @param   int       $id      Extension id
   *
   * @return  void
   *
   * @since   1.0.0
   */
  private function loadLanguageFile(int $id)
  {
    $this->getExtension($id);

    $extension = self::$data[$id];

    $base = JPATH_SITE;

    if((int) $extension->get('client_id', 0))
    {
      $base = JPATH_ADMINISTRATOR;
    }

    switch($extension->get('type'))
    {
      case 'plugin':
        $path = JPATH_SITE . '/plugins/' . $extension->get('folder') . '/' . $extension->get('element');
        break;

      case 'module':
        $path = $base . '/modules/' . $extension->get('name');
        break;

      case 'component':
        $path = JPATH_ADMINISTRATOR . '/components/' . $extension->get('name');
        break;

      default:
        $path = $base;
        break;
    }

    $lang = $this->getApplication()->getLanguage();
    $lang->load($extension->get('name'), strtolower($path));
  }

  /**
   * Load the XML manifest file of a specific extension
   *
   * @param   int|string     $id      Extension id
   *
   * @return  \SimpleXMLElement
   *
   * @since   1.0.0
   */
  private function loadXMLFile($id): \SimpleXMLElement
  {
    $extension = $this->getExtension($id, false);

    $base = JPATH_SITE;

    if((int) $extension->get('client_id', 0))
    {
      $base = JPATH_ADMINISTRATOR;
    }

    switch($extension->get('type'))
    {
      case 'plugin':
        $path   = JPATH_SITE . '/plugins/' . $extension->get('folder') . '/' . $extension->get('element');
        $prefix = 'plg_';
        break;

      case 'module':
        $path   = $base . '/modules/' . $extension->get('name');
        $prefix = 'mod_';
        break;

      case 'component':
        $path   = JPATH_ADMINISTRATOR . '/components/' . $extension->get('name');
        $prefix = 'com_';
        break;

      default:
        $path   = $base;
        $prefix = '';
        break;
    }

    // XML without prefix
    $file_1 = $path . '/' . str_replace($prefix, '', strtolower($extension->get('element'))) . '.xml';
    $file_2 = $path . '/' . strtolower($extension->get('element')) . '.xml';

    if(file_exists($file_1))
    {
      $xml = simplexml_load_file($file_1);

      return $xml;
    }
    elseif(file_exists($file_2))
    {
      // XML with prefix
      $xml = simplexml_load_file($file_2);

      return $xml;
    }


    throw new \Exception('XML of extension ' . $extension->get('name') . ' could not be loaded.', 1);
  }

  /**
   * Disable an extension
   *
   * @param   int      $id    Extension id
   * @param   string   $type  Extension type
   *
   * @return  void
   *
   * @since   1.0.0
   */
  private function disable(int $id, $type = null)
  {
    $query = $this->db->getQuery(true);

    $query->update($this->db->quoteName('#__extensions'))
      ->set($this->db->quoteName('enabled') . ' = 0')
      ->where($this->db->quoteName('extension_id') . ' = :extension_id')
      ->bind(':extension_id', $id, ParameterType::INTEGER);

    if($type)
    {
      $query->where($this->db->quoteName('type') . ' = :type')
        ->bind(':type', $type, ParameterType::STRING);
    }

    $this->db->setQuery($query);

    try
    {
      $this->db->execute();
    }
    catch(\Exception $e)
    {
      Log::add(Text::sprintf('PLG_SYSTEM_TECHSPUUR_ERROR_DISABLE', $e->getMessage()), Log::ERROR, 'techspuur');
    }
  }

  /**
   * Enables an extension.
   *
   * @param   int  $id  Extension ID
   *
   * @return  void
   *
   * @since   __DEPLOY_VERSION__
   */
  private function enable(int $id): void
  {
    $query = $this->db->getQuery(true)
      ->update($this->db->quoteName('#__extensions'))
      ->set($this->db->quoteName('enabled') . ' = 1')
      ->where($this->db->quoteName('extension_id') . ' = :extension_id')
      ->bind(':extension_id', $id, ParameterType::INTEGER);

    $this->db->setQuery($query);
    $this->db->execute();
  }

  /**
   * Read the offlineuse.txt file into an array
   *
   * @param   string  $file  Filepath
   *
   * @return  array   Array containing the extension names
   *
   * @since   1.0.2
   */
  private function parseOfflineuseTxt(string $file): array
  {
    $names = [];

    // Read file into array of lines
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach($lines as $line)
    {
      // Split each line by comma, but allow whole line if no commas
      $parts = array_map('trim', explode(',', $line));

      foreach($parts as $name)
      {
        if($name === '') continue;

        // Remove "PRO" suffix (case-insensitive)
        // Only if it's at the end, with optional space before it
        $clean = preg_replace('/\s* pro$/i', 'pro', $name);

        // Convert to lowercase
        $clean = strtolower($clean);

        $names[] = $clean;
      }
    }

    return $names;
  }

  /**
   * Sends to license data from extension params to endpoint for validation
   *
   * @param   int       $id             Extension id
   * @param   Registry  $params         Extension params
   * @param   string    $element        Extension element
   * @param   string    $name           Ressource name
   * @param   bool      $force_update   Force the validation
   *
   * @return  void
   *
   * @since   1.0.0
   */
  private function requestLicenseData(int $id, Registry $params, string $element, string $name, bool $force_update = false)
  {
    // Only if license form is correctly filled out
    if(!$params->get('username', '') || !$params->get('dlid', ''))
    {
      return;
    }

    // Only request once a day or if no custom data is available
    $app          = $this->getApplication();
    $now          = Factory::getDate();
    $last_request = $this->getLastRequest($id, $element);
    $time_diff    = $now->getTimestamp() - $last_request->getTimestamp();
    $context      = $this->guessContext();
    $offlineuse   = false;

    // Check for offline statement
    $file = \dirname(__FILE__) . '/offlineuse.txt';

    if(file_exists($file))
    {
      $arr_offlineuse = $this->parseOfflineuseTxt($file);

      if(\in_array(strtolower($element), $arr_offlineuse))
      {
        $offlineuse = true;
      }
    }

    if(!$force_update && ($time_diff < $this->refresh_rate || $offlineuse))
    {
      // Validation should happen only once every xx seconds or when its enforced
      return;
    }

    // This feature only applies in the site and administrator applications
    if( !($app instanceof CMSWebApplicationInterface) ||
        (!$app->isClient('site') && !$app->isClient('administrator'))
      )
    {
      return;
    }

    // Form data to send
    $formData = [
      'username' => $params->get('username'),
      'dlid' => $params->get('dlid'),
      'resource' => $name,
    ];

    // Generate signature
    $secret    = 'tech.$puur_valid_@Elfangor93';
    $payload   = http_build_query($formData); // same encoding as body
    $signature = hash_hmac('sha256', $payload, $secret);

    // Set headers
    $headers = [
      'X-Signature' => $signature,
      'Content-Type' => 'application/x-www-form-urlencoded',
      'Referer' => Uri::root(),
    ];

    try
    {
      $serverConfig = $this->getLicenseServerConfig();
      $url          = 'https://' . $serverConfig['host'] . self::LICENSE_PATH;
      $curlHeaders  = [];

      foreach($headers as $key => $value)
      {
        $curlHeaders[] = $key . ': ' . $value;
      }

      $curlResponse = $this->curlRequest($url, $payload, $curlHeaders);
      $this->verifyLicenseServerIdentity($url, $curlResponse['info'], $curlResponse['headers']);
      $response = (object) [
        'code' => (int) $curlResponse['info']['http_code'],
        'body' => $curlResponse['body'],
      ];

      // Define default response license data
      $license_data = new Registry();
      $license_data->set('state', -1, 'int');
      $license_data->set('domain', '', 'string');
      $license_data->set('num_licenses', 0, 'int');
      $license_data->set('dependence', '', 'string');
      $license_data->set('expiration_date', '');
      $license_data->set('request_date', Factory::getDate()->toSql());

      if($response->code === 200)
      {
        // Decode JSON response
        $response_body = json_decode($response->body, true);

        if($response_body === null)
        {
          Log::add(Text::sprintf('PLG_SYSTEM_TECHSPUUR_ERROR_JSON_DECODE', json_last_error_msg()), Log::ERROR, 'techspuur');
          Log::add($response->body, Log::ERROR, 'techspuur');

          return;
        }

        // Decode JSON response body data
        $license_data_array = json_decode($response_body['data'], true);

        if($license_data_array === null)
        {
          Log::add(Text::sprintf('PLG_SYSTEM_TECHSPUUR_ERROR_JSON_DECODE', json_last_error_msg()), Log::ERROR, 'techspuur');
          Log::add($response_body['data'], Log::ERROR, 'techspuur');

          return;
        }

        // Create filter instance
        $filter = InputFilter::getInstance();

        $expiration_date = new Date($license_data_array['expiration_date']);

        $license_data->set('state', $filter->clean($license_data_array['state']), 'int');
        $license_data->set('domain', $filter->clean($license_data_array['domain']), 'string');
        $license_data->set('num_licenses', $filter->clean($license_data_array['num_licenses']), 'int');
        $license_data->set('expiration_date', $expiration_date->toSql());
        $license_data->set('request_date', Factory::getDate()->toSql());

        // Get list of extensions
        $this->requestExtensionData(self::EXTENSIONS_URL, true);

        foreach(self::$extensions->extension as $ext)
        {
          if((string) $ext['name'] === $name)
          {
            $dependence = '';

            if($ext['dependence'])
            {
              $dependence = (string) $ext['dependence'];
            }

            $license_data->set('dependence', $dependence, 'string');
            break;
          }
        }

        // Log data if state < 1
        if($license_data->get('state') < 1)
        {
          // State definition
          $license_state = ['-1' => 'unknown', '0' => 'disabled', '1' => 'active', '2' => 'expired'];

          // Prepare log data
          $logdata_sent     = '[username: ' . $formData['username'] . ', license key: ' . $formData['dlid'] . ', referer: ' . $headers['Referer'] . ']';
          $logdata_received = '[license state: ' . $license_state[$license_data->get('state', '-1')] . ', domain: ' . $license_data->get('domain', '-') . ', expiration date: ' . $license_data->get('expiration_date') . ']';

          // Logging
          Log::add(Text::_('PLG_SYSTEM_TECHSPUUR_ERROR_LICENSE_MISSING_INFO'), Log::WARNING, 'techspuur');
          Log::add(Text::sprintf('PLG_SYSTEM_TECHSPUUR_ERROR_LICENSE_SENT_DATA', $logdata_sent), Log::WARNING, 'techspuur');
          Log::add(Text::sprintf('PLG_SYSTEM_TECHSPUUR_ERROR_LICENSE_RECEIVED_DATA', $logdata_received), Log::WARNING, 'techspuur');
        }

        $app->setUserState($element . '.request.date', $license_data->get('request_date'));
      }
      elseif($response->code < 500)
      {
        // Access denied
        Log::add(Text::sprintf('PLG_SYSTEM_TECHSPUUR_ERROR_REQUEST_LICENSE_DATA', 'Response code:' . $response->code . ', Response body:' . $response->body), Log::WARNING, 'techspuur');
        $app->setUserState($element . '.request.date', $license_data->get('request_date'));
      }
      else
      {
        // Server Error
        // Try to decode json
        $response_body = json_decode($response->body, true);

        if($response_body === null)
        {
          $response_body = $response->body;
        }

        Log::add(Text::sprintf('PLG_SYSTEM_TECHSPUUR_ERROR_REQUEST_LICENSE_DATA', 'Response code:' . $response->code . ', Response body:' . $response_body), Log::ERROR, 'techspuur');
      }
    }
    catch(\Throwable $e)
    {
      // Application Error
      Log::add(Text::sprintf('PLG_SYSTEM_TECHSPUUR_ERROR_REQUEST_LICENSE_DATA', $e->getMessage()), Log::ERROR, 'techspuur');

      return;
    }

    $this->setCustomData($id, $license_data);
  }

  /**
   * Sends a request to the license server for debugging reasons
   *
   * @param   bool   $createLog   True, if a log file should be created
   *
   * @return  void
   *
   * @since   1.0.0
   */
  private function checkLicenseServer($createLog)
  {
    // Log file
    $tmp_folder  = $this->getApplication()->get('tmp_path');
    $logFilePath = $tmp_folder . '/techspuur/requestServer_log_' . time() . '.txt';

    // Form data to send
    $formData = [
      'username' => 'Example',
      'dlid' => 'xxx',
      'resource' => 'Test',
    ];

    // Generate signature
    $secret    = 'tech.$puur_valid_@Elfangor93';
    $payload   = http_build_query($formData); // same encoding as body
    $signature = hash_hmac('sha256', $payload, $secret);

    // Set headers
    $headers = [
      'X-Signature' => $signature,
      'Content-Type' => 'application/x-www-form-urlencoded',
      'Referer' => Uri::root(),
    ];

    // Format the headers for cURL
    $curl_headers = [];

    foreach($headers as $key => $value)
    {
      $curl_headers[] = "$key: $value";
    }

    $verboseFile = null;

    // Create a raw cURL request for debugging
    try
    {
      $serverConfig = $this->getLicenseServerConfig();
      $url          = 'https://' . $serverConfig['host'] . self::LICENSE_PATH;

      if($createLog)
      {
        $verboseFile = fopen($logFilePath, 'w');

        if($verboseFile === false)
        {
          throw new \RuntimeException('Unable to create the cURL log file.');
        }
      }

      $curlResponse = $this->curlRequest($url, $payload, $curl_headers, $verboseFile);
      $this->verifyLicenseServerIdentity($url, $curlResponse['info'], $curlResponse['headers']);
    }
    catch(\Throwable $e)
    {
      $this->getApplication()->enqueueMessage(Text::sprintf('PLG_SYSTEM_TECHSPUUR_ERROR_REQUEST', $e->getMessage()), 'error');

      return;
    }
    finally
    {
      if(is_resource($verboseFile))
      {
        fclose($verboseFile);
      }
    }

    if($createLog)
    {
      file_put_contents(
        $logFilePath,
        "\n\n=== RESPONSE HEADERS ===\n" . $curlResponse['headers']
          . "\n=== RESPONSE BODY ===\n" . $curlResponse['body'],
        FILE_APPEND
      );
    }

    // Print a short description for the most common HTTP response codes
    $httpCode   = (int) $curlResponse['info']['http_code'];
    $statusText = match($httpCode) {
      200 => 'The license server responded successfully.',
      400 => 'The license server rejected the request as invalid.',
      401 => 'Authentication is required.',
      403 => 'The license server is available, but access was denied for this test request. Thats expected behavior.',
      404 => 'The license server endpoint was not found.',
      408 => 'The request timed out.',
      429 => 'Too many requests were sent. Please try again later.',
      500 => 'The license server encountered an internal error.',
      502 => 'The license server received an invalid upstream response.',
      503 => 'The license server is temporarily unavailable.',
      504 => 'The license server did not receive an upstream response in time.',
      default => 'The license server returned an unexpected response.',
    };

    if($httpCode === 200 || $httpCode === 403)
    {
      // Correct response
      $this->getApplication()->enqueueMessage(
        Text::_('PLG_SYSTEM_TECHSPUUR_SUCCESS_REQUEST') . '&nbsp;&nbsp;' .
        '<a data-bs-toggle="collapse" href="#collapseBody" role="button" aria-expanded="false" aria-controls="collapseBody"> ' . Text::_('PLG_SYSTEM_TECHSPUUR_SHOW_MORE') . '</a>' .
        '<div class="collapse" id="collapseBody"><hr><strong>Status code:</strong> ' . $httpCode . '<br><strong>Body:</strong> ' . $curlResponse['body'] . '<br><strong>Explanation:</strong> ' . $statusText . '</div>',
        'success'
      );
    }
    elseif($httpCode === 0)
    {
      // Network unavailable
      $this->getApplication()->enqueueMessage(Text::_('PLG_SYSTEM_TECHSPUUR_FAILED_REQUEST') . '<br><br><strong>Status code:</strong> ' . $httpCode . ' ; ' . $statusText, 'error');
      $this->getApplication()->enqueueMessage(Text::_('Network unavailable.'), 'error');
    }
    else
    {
      // Fallback: Something wrong
      $this->getApplication()->enqueueMessage(Text::_('PLG_SYSTEM_TECHSPUUR_FAILED_REQUEST') . '<br><br><strong>Status code:</strong> ' . $httpCode . '<br><strong>Body:</strong> ' . $curlResponse['body'] . '<br><strong>Explanation:</strong> ' . $statusText, 'error');
      $this->getApplication()->enqueueMessage('<br>' . Text::_('PLG_SYSTEM_TECHSPUUR_ERROR_CHECK_SERVER'), 'error');
    }
  }

  /**
   * Parses the response of a cURL response body string
   *
   * @param   string   $response   The cURL response body string
   *
   * @return  array    The parsed response
   *
   * @since   1.0.0
   */
  protected function parseResponse(string $response): array
  {
    $headers = [];

    // Normalize newlines
    $response = str_replace("\r", "\n", $response);

    // Split headers by line
    $lines = explode("\n", $response);

    foreach($lines as $line)
    {
      $line = trim($line);

      if($line === '') continue;

      // First line (status line), e.g. HTTP/2 403
      if(stripos($line, 'HTTP/') === 0)
      {
        $headers['status'] = $line;
        continue;
      }

      // Key: Value headers
      if(strpos($line, ':') !== false)
      {
        list($key, $value) = explode(':', $line, 2);
        $key               = strtolower(trim($key));
        $value             = trim($value);

        // If key already exists, append value into single string
        if(isset($headers[$key]))
        {
          $headers[$key] .= ', ' . $value;
        }
        else
        {
          $headers[$key] = $value;
        }
      }
      else
      {
        if(isset($headers['body']))
        {
          $headers['body'] .= '; ' . $line;
        }
        else
        {
          $headers['body'] = $line;
        }
      }
    }

    return $headers;
  }

  /**
   * Gets the last license validate request date
   *
   * @param   int      $id        Extension id
   * @param   string   $element   Extension element
   * @param   bool     $license   True if it is license data
   *
   * @return  Date
   *
   * @since   1.1.0
   */
  private function getLastRequest(int $id, string $element, bool $license = true)
  {
    $app = $this->getApplication();

    // This feature only applies in the site and administrator applications
    if( !($app instanceof CMSWebApplicationInterface) ||
        (!$app->isClient('site') && !$app->isClient('administrator'))
      )
    {
      return new Date('1900-02-02 10:00:00');
    }

    $date = $app->getUserState($element . '.request.date', null);

    if(empty($date))
    {
      $customData = $this->getCustomData($id, $license);
      $date       = $customData->get('request_date', '1900-02-02 10:00:00');
    }

    return new Date($date);
  }

  /**
   * Sends to license data from plugin params to endpoint for validation
   *
   * @param   int        $id        Extension id
   * @param   string     $element   Extension element
   * @param   string     $name      Extension name
   * @param   Registry   $data      Requested license data
   *
   * @return  void
   *
   * @since   1.0.0
   */
  private function checkLicenseData(int $id, string $element, string $name, $data = null)
  {
    $app = $this->getApplication();

    // This feature only applies in the site and administrator applications
    if( !($app instanceof CMSWebApplicationInterface) ||
        (!$app->isClient('site') && !$app->isClient('administrator'))
      )
    {
      return;
    }

    $this->loadLanguageFile($id);

    // Get license data
    if(\is_null($data))
    {
      $params = $this->getParamsData($id);
      $data   = $this->getCustomData($id);

      $extension      = self::$data[$id];
      $ressource_name = Text::_(strtoupper($extension->get('name')) . '_SPFA_RESSOURCE_NAME'); // Name of the SPFA ressource
      $this->requestLicenseData($extension->get('extension_id'), $extension->get('params'), $extension->get('element'), $ressource_name);
    }
    $lang_prefix = strtoupper($name);

    /** state definition
     *  -1: no license found, wrong data provided (username, dlid, domain)
     *   0: license disabled, user blocked
     *   1: active
     *   2: expired
     */
    if((int) $data->get('state', 0) < 1)
    {
      // Turn plugin off, license data not correct
      $this->disable($id);

      if((int) $data->get('state', 0) == 0)
      {
        $app->setUserState($element . '.license.state', 0);
        $app->setUserState($element . '.license.msg-type', 'error');
        $app->setUserState($element . '.license.msg-text', Text::_($lang_prefix . '_MSG_LICENSE_DISABLED'));
      }
      else
      {
        $app->setUserState($element . '.license.state', -1);
        $app->setUserState($element . '.license.msg-type', 'error');
        $app->setUserState($element . '.license.msg-text', Text::_($lang_prefix . '_MSG_LICENSE_UNKNOWN'));
      }
    }
    elseif((int) $data->get('state', 0) > 1)
    {
      // Plugin stays active, but show message that license has expired
      $app->setUserState($element . '.license.state', 2);
      $app->setUserState($element . '.license.msg-type', 'warning');
      $app->setUserState($element . '.license.msg-text', Text::_($lang_prefix . '_MSG_LICENSE_EXPIRED'));
    }
    else
    {
      $app->setUserState($element . '.license.state', 1);
      $app->setUserState($element . '.license.msg-type', 'success');
      $app->setUserState($element . '.license.msg-text', Text::_($lang_prefix . '_MSG_LICENSE_ACTIVE'));
    }
  }

  /**
   * Sends to license data from extension params to endpoint for validation
   *
   * @param   string    $url            URL to the extensions xml
   * @param   bool      $force_update   Force to update the list
   *
   * @return  bool|Registry
   *
   * @since   1.0.0
   */
  private function requestExtensionData(string $url, bool $force_update = false)
  {
    $app = $this->getApplication();

    // This feature only applies in the site and administrator applications
    if( !($app instanceof CMSWebApplicationInterface) ||
        (!$app->isClient('site') && !$app->isClient('administrator'))
      )
    {
      return false;
    }

    // Only request once a day or if no custom data is available
    $now          = Factory::getDate();
    $last_request = $this->getLastRequest($this->id, 'techspuur', false);
    $time_diff    = $now->getTimestamp() - $last_request->getTimestamp();
    $context      = $this->guessContext();

    if(!$force_update && $time_diff < $this->refresh_rate && self::$extensions instanceof \SimpleXMLElement)
    {
      // Validation should happen only once every xx seconds or when its enforced
      return false;
    }

    if(\is_null(self::$extensions) || empty(self::$extensions) || $force_update)
    {
      try
      {
        if($url !== self::EXTENSIONS_URL)
        {
          throw new \InvalidArgumentException('The extensions metadata URL is not allowed.');
        }

        self::$extensions = $this->loadExtensionsXml();
      }
      catch(\Throwable $e)
      {
        Log::add(Text::sprintf('PLG_SYSTEM_TECHSPUUR_ERROR_XML_EXTENSIONS', $e->getMessage()), Log::ERROR, 'techspuur');
        $app->enqueueMessage(Text::sprintf('PLG_SYSTEM_TECHSPUUR_ERROR_XML_EXTENSIONS', $e->getMessage()), 'error');

        return false;
      }
    }

    // Collect all extensions of license=pro
    $date          = Factory::getDate()->toSql();
    $proExtensions = new Registry(['request_date' => $date]);
    $i             = 0;

    foreach(self::$extensions->extension as $ext)
    {
      if((string) $ext['license'] === 'pro')
      {
        // Get ID of extension
        $query = $this->db->getQuery(true);

        $type    = (string) $ext['type'];
        $element = (string) $ext['element'];
        $folder  = (string) $ext['folder'];

        $query->select($this->db->quoteName('extension_id'))
          ->from('#__extensions')
          ->where($this->db->quoteName('type') . ' = :type')
          ->where($this->db->quoteName('element') . ' = :element')
          ->where($this->db->quoteName('folder') . ' = :folder')
          ->bind(':type', $type, ParameterType::STRING)
          ->bind(':element', $element, ParameterType::STRING)
          ->bind(':folder', $folder, ParameterType::STRING);

        try
        {
          $this->db->setQuery($query);
          $ext_id = $this->db->loadResult();
        }
        catch(\Exception $e)
        {
          $ext_id = false;
        }

        if($ext_id)
        {
          $proExtensions->set('extensions.' . (string) $i, $ext_id);
          $i++;
        }
      }
    }

    $this->setCustomData($this->id, $proExtensions, false);
    $app->setUserState('techspuur.request.date', $date);

    return $proExtensions;
  }

  /**
   * Load validated extensions metadata from the network, cache or bundled fallback.
   */
  private function loadExtensionsXml(): \SimpleXMLElement
  {
    $cacheDir = JPATH_CACHE . DIRECTORY_SEPARATOR . 'plg_system_techspuur';
    $cacheXml = $cacheDir . DIRECTORY_SEPARATOR . 'extensions.xml';
    $localXml = __DIR__ . DIRECTORY_SEPARATOR . 'extensions.xml';

    try
    {
      $downloaded = $this->downloadHttps(self::EXTENSIONS_URL);
      $xml        = $this->validateExtensionsXml($downloaded);

      if(!(is_dir($cacheDir) || mkdir($cacheDir, 0755, true))
        || file_put_contents($cacheXml, $downloaded, LOCK_EX) === false)
      {
        Log::add('Unable to cache the validated extensions metadata.', Log::WARNING, 'techspuur');
      }

      self::$extensionsSource = 'downloaded';

      return $xml;
    }
    catch(\Throwable $e)
    {
      Log::add('Unable to download extensions metadata: ' . $e->getMessage(), Log::WARNING, 'techspuur');
    }

    if(is_file($cacheXml))
    {
      try
      {
        $cached = file_get_contents($cacheXml);

        if($cached === false)
        {
          throw new \RuntimeException('Unable to read the cached extensions metadata.');
        }

        $xml = $this->validateExtensionsXml($cached);
        self::$extensionsSource = 'cache';

        return $xml;
      }
      catch(\Throwable $e)
      {
        Log::add('Unable to use cached extensions metadata: ' . $e->getMessage(), Log::WARNING, 'techspuur');
      }
    }

    $bundled = file_get_contents($localXml);

    if($bundled === false)
    {
      throw new \RuntimeException('The bundled extensions metadata is unavailable.');
    }

    $xml = $this->validateExtensionsXml($bundled);
    self::$extensionsSource = 'bundled';

    return $xml;
  }

  /**
   * Validate the extensions XML and its license-server identity data.
   */
  private function validateExtensionsXml(string $xmlBody): \SimpleXMLElement
  {
    $previous = libxml_use_internal_errors(true);
    $xml      = simplexml_load_string($xmlBody, \SimpleXMLElement::class, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if(!$xml instanceof \SimpleXMLElement || $xml->getName() !== 'extensionset')
    {
      throw new \RuntimeException('The extensions metadata XML structure is invalid.');
    }

    $this->readLicenseServerConfig($xml);

    return $xml;
  }

  /**
   * Read and validate the license-server identity from extensions metadata.
   *
   * @return array{host: string, server: string, addresses: array<int, string>}
   */
  private function readLicenseServerConfig(\SimpleXMLElement $xml): array
  {
    $host      = strtolower(trim((string) $xml->licenseServer['host']));
    $server    = strtolower(trim((string) $xml->licenseServer['server']));
    $addresses = [];

    if(count($xml->licenseServer) !== 1
      || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
      || $server === '')
    {
      throw new \RuntimeException('The license-server host or server name is invalid.');
    }

    foreach($xml->licenseServer->address as $addressNode)
    {
      $address = trim((string) $addressNode);
      $family  = strtolower(trim((string) $addressNode['family']));
      $flag    = $family === 'ipv4' ? FILTER_FLAG_IPV4 : ($family === 'ipv6' ? FILTER_FLAG_IPV6 : 0);

      if($flag === 0 || filter_var($address, FILTER_VALIDATE_IP, $flag) === false)
      {
        throw new \RuntimeException('The license-server IP address list is invalid.');
      }

      $addresses[] = $address;
    }

    if($addresses === [])
    {
      throw new \RuntimeException('The license-server IP address list is empty.');
    }

    return ['host' => $host, 'server' => $server, 'addresses' => $addresses];
  }

  /**
   * Return the currently configured license server, loading metadata if needed.
   *
   * @return array{host: string, server: string, addresses: array<int, string>}
   */
  private function getLicenseServerConfig(): array
  {
    if(!(self::$extensions instanceof \SimpleXMLElement))
    {
      self::$extensions = $this->loadExtensionsXml();
    }

    return $this->readLicenseServerConfig(self::$extensions);
  }

  /**
   * Download an HTTPS resource with TLS peer and hostname verification.
   */
  private function downloadHttps(string $url): string
  {
    $result = $this->curlRequest($url);

    if((int) $result['info']['http_code'] !== 200)
    {
      throw new \RuntimeException('Download failed with HTTP status ' . (int) $result['info']['http_code'] . '.');
    }

    return $result['body'];
  }

  /**
   * Determine whether TLS certificates must be verified.
   * Verification can only be disabled explicitly while Joomla debug mode is active.
   */
  private function shouldVerifyTls(): bool
  {
    $disabled = defined('TECHSPUUR_DISABLE_TLS_VERIFICATION') && constant('TECHSPUUR_DISABLE_TLS_VERIFICATION') === true;
    $debug    = defined('JDEBUG') && (bool) constant('JDEBUG');

    if(!$disabled || !$debug)
    {
      return true;
    }

    if(!self::$tlsWarningShown)
    {
      $warning = Text::_('PLG_SYSTEM_TECHSPUUR_WARNING_TLS_DISABLED');
      Factory::getApplication()->enqueueMessage($warning, 'warning');
      Log::add($warning, Log::WARNING, 'techspuur');
      self::$tlsWarningShown = true;
    }

    return false;
  }

  /**
   * Execute a TLS-verified cURL request.
   *
   * @param array<int, string> $headers
   * @param resource|null      $verboseFile
   *
   * @return array{body: string, headers: string, info: array<string, mixed>}
   */
  private function curlRequest(string $url, ?string $payload = null, array $headers = [], $verboseFile = null): array
  {
    if(strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https')
    {
      throw new \InvalidArgumentException('Only HTTPS requests are allowed.');
    }

    $ch = curl_init($url);

    if($ch === false)
    {
      throw new \RuntimeException('Unable to initialize cURL.');
    }

    $verifyTls = $this->shouldVerifyTls();
    $options   = [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HEADER         => true,
      CURLOPT_FOLLOWLOCATION => false,
      CURLOPT_TIMEOUT        => 15,
      CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
      CURLOPT_SSL_VERIFYPEER => $verifyTls,
    ];

    if($payload !== null)
    {
      $options[CURLOPT_POST]       = true;
      $options[CURLOPT_POSTFIELDS] = $payload;
    }

    if($headers !== [])
    {
      $options[CURLOPT_HTTPHEADER] = $headers;
    }

    if(is_resource($verboseFile))
    {
      $options[CURLOPT_VERBOSE] = true;
      $options[CURLOPT_STDERR]  = $verboseFile;
    }

    try
    {
      if(!curl_setopt_array($ch, $options))
      {
        throw new \RuntimeException('Unable to configure cURL.');
      }

      $raw = curl_exec($ch);

      if($raw === false)
      {
        throw new \RuntimeException(curl_error($ch));
      }

      $info       = curl_getinfo($ch);
      $headerSize = (int) ($info['header_size'] ?? 0);

      return [
        'headers' => substr($raw, 0, $headerSize),
        'body'    => substr($raw, $headerSize),
        'info'    => $info,
      ];
    }
    finally
    {
      curl_close($ch);
    }
  }

  /**
   * Verify the connected license-server host, IP address and Server header.
   *
   * @param array<string, mixed> $info
   *
   * @return array{host: string, server: string, addresses: array<int, string>, primary_ip: string, response_server: string}
   */
  private function verifyLicenseServerIdentity(string $url, array $info, string $headers): array
  {
    $config         = $this->getLicenseServerConfig();
    $requestHost    = strtolower((string) parse_url($url, PHP_URL_HOST));
    $primaryIp      = (string) ($info['primary_ip'] ?? '');
    $packedPrimary  = inet_pton($primaryIp);
    $response       = $this->parseResponse($headers);
    $responseServer = strtolower((string) ($response['server'] ?? ''));
    $trustedIp      = false;

    foreach($config['addresses'] as $address)
    {
      $packedAddress = inet_pton($address);

      if($packedPrimary !== false && $packedAddress !== false && hash_equals($packedAddress, $packedPrimary))
      {
        $trustedIp = true;
        break;
      }
    }

    if($requestHost !== $config['host'])
    {
      throw new \RuntimeException('The license request hostname does not match the configured hostname.');
    }

    if(!$trustedIp)
    {
      throw new \RuntimeException('The connected license-server IP address is not trusted: ' . $primaryIp);
    }

    if($responseServer !== $config['server'])
    {
      throw new \RuntimeException('The license-server response name is not trusted: ' . $responseServer);
    }

    return $config + ['primary_ip' => $primaryIp, 'response_server' => $responseServer];
  }

  /**
   * Method to load an XML from the web.
   *
   * @param   string  $uri  The URI of the feed to load. Idn uris must be passed already converted to punycode.
   *
   * @return  \SimpleXMLElement
   *
   * @since   1.0.0
   * @throws  \InvalidArgumentException
   * @throws  \RuntimeException
   */
  public function fetchXML(string $uri): \SimpleXMLElement
  {
    // Create the XMLReader object.
    $reader = new \XMLReader();

    // Enable internal error handling for better debugging
    libxml_use_internal_errors(true);

    try
    {
      $body = $this->downloadHttps($uri);
    }
    catch(\Throwable $e)
    {
      throw new \RuntimeException('Unable to open the feed.', $e->getCode(), $e);
    }

    if(!$reader->XML($body, null, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET))
    {
      throw new \RuntimeException('Unable to parse the feed.');
    }

    try
    {
      // Skip to the first root element
      $maxAttempts = 100;
      $attempts    = 0;

      while($reader->read())
      {
        if($reader->nodeType == \XMLReader::ELEMENT)
        {
          break;
        }

        if(++$attempts > $maxAttempts)
        {
          throw new \RuntimeException('Exceeded maximum attempts to find the root element.');
        }
      }

      // Retrieve the xml string
      $xmlString = $reader->readOuterXml();
    }
    catch(\Exception $e)
    {
      throw new \RuntimeException('Error reading feed.', $e->getCode(), $e);
    }

    return new \SimpleXMLElement($xmlString);
  }
}
