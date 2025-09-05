<?php
/**
****************************************************************************
**   @package    plg_system_techspuur                                     **
**   @author     Manuel Häusler <tech.spuur@quickline.ch>                 **
**   @copyright  2025 Manuel Haeusler                                     **
**   @license    GNU General Public License version 3 or later            **
****************************************************************************/

namespace Elfangor93\Plugin\System\Techspuur\Extension;

// No direct access
\defined('_JEXEC') or die;

use Joomla\CMS\Date\Date;
use Joomla\CMS\Factory;
use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\ParameterType;
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
   * @var   \JDatabaseDriver
   * @since  1.0.0
   */
  protected $db = null;

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
    $lang = Factory::getLanguage();
    $lang->load('plg_system_techspuur', JPATH_SITE . '/plugins/system/techspuur');

    Log::addLogger(array('text_file' => 'techspuur.php'), Log::ALL, array('techspuur'));
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
    // This feature only applies in the site and administrator applications
    if( !$this->getApplication()->isClient('site') &&
			  !$this->getApplication()->isClient('administrator')
      )
		{
      return;
    }

    // Check licenses if needed
    $ids = $this->getExtensions();
    foreach($ids as $id)
    {
      // Get extension data
      $ext = $this->getExtension($id);

      // Check the license of the extension
      $this->checkLicenseData($id, $ext->get('element'), $ext->get('name'));

      // Compose list of dependent components
      $tmp = new Registry;
      $dependence = $ext->get('custom_data', $tmp)->get('dependence', '');
      if($dependence)
      {
        if(\strpos($dependence, 'com') !== false && !\key_exists($dependence, self::$dependencies))
        {
          // Add new dependency to list
          self::$dependencies[$dependence] = [];
        }

        \array_push(self::$dependencies[$dependence], $id);        
      }
    }

    // Show license messages in dependent component backend
    $option = $this->getApplication()->input->get('option');
    if( $this->getApplication()->isClient('administrator') &&
        $option && \key_exists($option, self::$dependencies)
      )
    {
      foreach(self::$dependencies[$option] as $ext_id)
      {
        // Show message if we have an invalid license
        $extension = self::$data[$ext_id];
        if($this->getApplication()->getUserState($extension->get('element').'.license.state', -1) !== 1)
        {
          $msg_type = $this->getApplication()->getUserState($extension->get('element').'.license.msg-type', 'message');
          $msg_text = $this->getApplication()->getUserState($extension->get('element').'.license.msg-text', '');
          $this->getApplication()->enqueueMessage($msg_text, $msg_type);
        }
      }

      // Check if we have a compatible component version
      $version_com   = (string) $this->loadXMLFile($option)->version;
      $compatibility = (string) $this->loadXMLFile($extension->get('extension_id'))->compatibility;
      if($version_com && $compatibility && !\preg_match('/^' . $compatibility . '/', $version_com))
      {
        // Incompatible component version
        $lang_prefix = \strtoupper($extension->get('name'));
        $this->getApplication()->enqueueMessage(sprintf(Text::_($lang_prefix.'_MSG_VERSION_HINT'), $version_com), 'error');
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
    if(\version_compare(JVERSION, '5.0.0', '<'))
    {
      // Joomla 4
      [$context, &$table, $isNew, $data] = $event->getArguments();
    }
    else
    {
      // Joomla 5 or newer
      $table = $event->getItem();
    }

    if($table->name == 'plg_system_techspuur')
    {
      // We are saving this plugin form
      $array = ['create_log' => 'int', 'check_server' => 'int', 'refresh_list' => 'int'];
      $params = $this->getApplication()->input->getArray(['jform' => ['params' => $array]]);
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
          $proExtensions = $this->requestExtensionData('https://updates.spuur.ch/extensions.xml', true);

          if($proExtensions)
          {
            // Update custom data
            $table->custom_data = $proExtensions->toString('json');
            $event->setArgument('subject', $table);
          }
        }
        catch(\Exception $e)
        {
          $this->getApplication()->enqueueMessage(Text::sprintf('PLG_SYSTEM_TECHSPUUR_ERROR_XML', $e->getMessage()), 'error');
        }

        $this->getApplication()->enqueueMessage(Text::_('PLG_SYSTEM_TECHSPUUR_SUCCESS_EXTENSION_LIST'), 'success');
      }

      return;
    }

    if(!\in_array($table->name, $this->getExtensions('names')))
    {
      return;
    }

    $params = $this->getApplication()->input->getArray(['jform' => ['params' => ['force_update' => 'int']]]);

    if(\key_exists('force_update', $params['jform']['params']))
    {
      $this->getApplication()->setUserState(\strtolower($table->name).'.license.force_update', \boolval($params['jform']['params']['force_update']));
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
    // Run this plugin only on the backend website
    if(!$this->getApplication()->isClient('administrator'))
    {
      return;
    }

    if(\version_compare(JVERSION, '5.0.0', '<'))
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

    // Run this plugin only for tech.spuur extension forms
    if(!$data || \is_array($data) || !\property_exists($data, 'name') || !\in_array($data->name, $this->getExtensions('names')))
    {
      return;
    }

    // Get the extension object
    $extension = $this->getExtension($data->name);

    // Reset all state variables
    $this->getApplication()->setUserState($extension->get('element').'.fractions', null);
    $this->getApplication()->setUserState($extension->get('element').'.license.msg-type', 'message');
    $this->getApplication()->setUserState($extension->get('element').'.license.msg-text', '');
    $this->getApplication()->setUserState($extension->get('element').'.request.date', null);
    $this->getApplication()->setUserState($extension->get('element').'.license.state', null);

    if( \key_exists('username', $data->params) && $data->params['username'] &&
        \key_exists('dlid', $data->params) && $data->params['dlid'] &&
        $this->getApplication()->getUserState(\strtolower($extension->get('name')).'.license.force_update', false)
      )
    {
      $this->loadLanguageFile($extension->get('extension_id'));

      // Force a new license validation
      $data_params    = new Registry($data->params);
      $ressource_name = Text::_(\strtoupper($extension->get('name')) . '_SPFA_RESSOURCE_NAME'); // Name of the SPFA ressource
      $this->requestLicenseData($extension->get('extension_id'), $data_params, $extension->get('element'), $ressource_name, true);
      $this->getApplication()->setUserState(\strtolower($extension->get('name')).'.license.force_update', false);
    }

    $this->checkLicenseData($extension->get('extension_id'), $extension->get('element'), $extension->get('name'));

    // Display the license message
    $msg_type = $this->getApplication()->getUserState($extension->get('element').'.license.msg-type', 'message');
    $msg_text = $this->getApplication()->getUserState($extension->get('element').'.license.msg-text', '');
    $this->getApplication()->enqueueMessage($msg_text, $msg_type);

    return;
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
  private function getExtensions($mode = 'ids'): array
  {
    $cdata = $this->requestExtensionData('https://updates.spuur.ch/extensions.xml');

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
              ->where($this->db->quoteName('enabled') . ' = ' . '1')
              ->where($this->db->quoteName('extension_id') . ' IN (' . \implode(',', \array_map('intval', $ids)) . ')');
          
        $this->db->setQuery($query);

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
        
        self::$data = \array_replace(self::$data, $extensions);
      }
    }

    // Return names
    if($mode == 'names')
    {
      $names = [];
      foreach(self::$data as $id => $extension)
      {
        $name = $extension->get('name');
        if(!\key_exists($name, $names))
        {
          \array_push($names, $name);
        }
      }

      return $names;
    }

    return \array_keys(self::$data);
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

    if($id > 0 && (!\key_exists($id, self::$data) && empty(self::$data[$id])))
    {
      $query = $this->db->getQuery(true);

      $query->select($this->db->quoteName(['extension_id', 'name', 'type', 'element', 'folder', 'client_id', 'params', 'custom_data']))
            ->from('#__extensions')
            ->where($this->db->quoteName('extension_id') . ' = :extension_id')
            ->bind(':extension_id', $id, ParameterType::INTEGER);
        
      $this->db->setQuery($query);

      try
      {
        $extension = new Registry($this->db->loadObject());
      }
      catch(\Exception $e)
      {
        Log::add(Text::sprintf('PLG_SYSTEM_TECHSPUUR_ERROR_EXTENSION', $e->getMessage()), Log::ERROR, 'techspuur');
      }

      if($store && $extension)
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
    $extension  = $this->getExtension($id, $store);
    $params = $extension->get('params', '');

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

    $lang = Factory::getLanguage();
    $lang->load($extension->get('name'), \strtolower($path));
  }

  /**
   * Load the XML manifest file of a specific extension
   * 
   * @param   int|string     $id      Extension id
   * 
   * @return  SimpleXMLElement
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

    $file = $path . '/' . \str_replace($prefix, '', \strtolower($extension->get('element'))) . '.xml';
    if(\file_exists($file))
    {
      $xml = \simplexml_load_file($file);
      return $xml;
    }

    return new \SimpleXMLElement('');
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
    $now          = Factory::getDate();
    $last_request = $this->getLastRequest($id, $element);
    $time_diff    = $now->getTimestamp() - $last_request->getTimestamp();

    if(!$force_update && ($time_diff < $this->refresh_rate || \file_exists(dirname(__FILE__) . '/offlineuse.txt')))
    {
      // Validation should happen only once every xx seconds or when its enforced
      return;
    }

    // Create request options
    $options = new Registry;
    $options->set('timeout', 15);

    // Create the HTTP client
    $http = HttpFactory::getHttp($options);

    // URL to send request to
    $url = 'https://tech.spuur.ch/index.php?option=com_sesamepayforaccess&view=licensevalidate&format=json';

    // Form data to send
    $formData = [
      'username' => $params->get('username'),
      'dlid' => $params->get('dlid'),
      'resource' => $name
    ];

    // Generate signature
    $secret    = 'tech.$puur_valid_@Elfangor93';
    $payload   = \http_build_query($formData); // same encoding as body
    $signature = \hash_hmac('sha256', $payload, $secret);

    // Set headers
    $headers = [
      'X-Signature' => $signature,
      'Content-Type' => 'application/x-www-form-urlencoded',
      'Referer' => Uri::root()
    ];

    try
    {
      // Send POST request with form data
      $response = $http->post($url, $formData, $headers);

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
        $response_body = \json_decode($response->body, true);
        if($response_body === null)
        {
          Log::add(Text::sprintf('PLG_SYSTEM_TECHSPUUR_ERROR_JSON_DECODE', json_last_error_msg()), Log::ERROR, 'techspuur');
          Log::add($response->body, Log::ERROR, 'techspuur');

          return;
        }

        // Decode JSON response body data
        $license_data_array = \json_decode($response_body['data'], true);
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
        $this->requestExtensionData('https://updates.spuur.ch/extensions.xml', true);
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
          $license_state    = ['-1'=>'unknown', '0'=>'disabled', '1'=>'active', '2'=>'expired'];

          // Prepare log data
          $logdata_sent     = '[username: '.$formData['username'].', license key: '.$formData['dlid'].', referer: '.$headers['Referer'].']';
          $logdata_received = '[license state: '.$license_state[$license_data->get('state', '-1')].', domain: '.$license_data->get('domain', '-').', expiration date: '.$license_data->get('expiration_date').']';

          // Logging
          Log::add(Text::_('PLG_SYSTEM_TECHSPUUR_ERROR_LICENSE_MISSING_INFO'), Log::WARNING, 'techspuur');
          Log::add(Text::sprintf('PLG_SYSTEM_TECHSPUUR_ERROR_LICENSE_SENT_DATA', $logdata_sent), Log::WARNING, 'techspuur');
          Log::add(Text::sprintf('PLG_SYSTEM_TECHSPUUR_ERROR_LICENSE_RECEIVED_DATA', $logdata_received), Log::WARNING, 'techspuur');
        }

        $this->getApplication()->setUserState($element.'.request.date', $license_data->get('request_date'));
      }
      elseif($response->code < 500)
      {
        // Access denied
        Log::add(Text::sprintf('PLG_SYSTEM_TECHSPUUR_ERROR_REQUEST_LICENSE_DATA', 'Response code:' . $response->code . ', Response body:' . $response->body), Log::WARNING, 'techspuur');
        $this->getApplication()->setUserState($element.'.request.date', $license_data->get('request_date'));
      }
      else
      {
        // Server Error
        // Try to decode json
        $response_body = \json_decode($response->body, true);
        if($response_body === null)
        {
          $response_body = $response->body;
        }

        Log::add(Text::sprintf('PLG_SYSTEM_TECHSPUUR_ERROR_REQUEST_LICENSE_DATA', 'Response code:' . $response->code . ', Response body:' . $response_body), Log::ERROR, 'techspuur');
      }
    }
    catch(\Exception $e)
    {
      // Application Error
      Log::add(Text::sprintf('PLG_SYSTEM_TECHSPUUR_ERROR_REQUEST_LICENSE_DATA', $e->getMessage()), Log::ERROR, 'techspuur');
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
    // URL to send request to
    $url = 'https://tech.spuur.ch/index.php?option=com_sesamepayforaccess&view=licensevalidate&format=json';

    // Log file
    $tmp_folder  = $this->getApplication()->get('tmp_path');
    $logFilePath = $tmp_folder . '/techspuur/requestServer_log_' . time() . '.txt';

    // Form data to send
    $formData = [
      'username' => 'Example',
      'dlid' => 'xxx',
      'resource' => 'Test'
    ];

    // Generate signature
    $secret    = 'tech.$puur_valid_@Elfangor93';
    $payload   = \http_build_query($formData); // same encoding as body
    $signature = \hash_hmac('sha256', $payload, $secret);

    // Set headers
    $headers = [
      'X-Signature' => $signature,
      'Content-Type' => 'application/x-www-form-urlencoded',
      'Referer' => Uri::root()
    ];

    // Format the headers for cURL
    $curl_headers = [];
    foreach ($headers as $key => $value) {
      $curl_headers[] = "$key: $value";
    }

    // Create a raw cURL request for debugging
    try
    {
      $ch = \curl_init($url);
      $options = [
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_POST           => true,
          CURLOPT_POSTFIELDS     => $payload,
          CURLOPT_HTTPHEADER     => $curl_headers,
          CURLOPT_HEADER         => true,
          CURLOPT_SSL_VERIFYHOST => 0,
          CURLOPT_SSL_VERIFYPEER => 0
      ];

      $verboseFile = null;
      if($createLog)
      {
        $verboseFile = \fopen($logFilePath, 'w');
        $options[CURLOPT_VERBOSE] = true;
        $options[CURLOPT_STDERR]  = $verboseFile;
      }

      \curl_setopt_array($ch, $options);

      $ch_res  = \curl_exec($ch);
      $ch_info = \curl_getinfo($ch);

      if($createLog)
      {
        \fclose($verboseFile);
      }

      \curl_close($ch);

      if($createLog)
      {
        \file_put_contents($logFilePath, "\n\n=== RESPONSE BODY START ===\n" . $ch_res, FILE_APPEND);
      }
    }
    catch(\Exception $e)
    {
      $this->getApplication()->enqueueMessage(Text::sprintf('PLG_SYSTEM_TECHSPUUR_ERROR_REQUEST', $e->getMessage()), 'error');
    }

    // Print message
    $response = $this->parseResponse($ch_res);
    if($ch_info['http_code'] == 200 || ($ch_info['http_code'] == 403 && $ch_info['primary_ip'] == '194.150.248.215'  && strtolower($response['server']) == 'litespeed'))
    {
      // Response coming from correct ip and correct server
      $this->getApplication()->enqueueMessage(Text::_('PLG_SYSTEM_TECHSPUUR_SUCCESS_REQUEST') . '<br><br>Status code: ' . $ch_info['http_code'] . '<br>Body: '.$response['body'], 'success');
    }
    else
    {
      // Something is blocking the request
      $this->getApplication()->enqueueMessage(Text::_('PLG_SYSTEM_TECHSPUUR_FAILED_REQUEST') . '<br><br>Status code ' . $ch_info['http_code'], 'error');
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
    $response = \str_replace("\r", "\n", $response);

    // Split headers by line
    $lines = \explode("\n", $response);

    foreach($lines as $line)
    {
        $line = trim($line);
        if($line === '') continue;

        // First line (status line), e.g. HTTP/2 403
        if(\stripos($line, 'HTTP/') === 0)
        {
          $headers['status'] = $line;
          continue;
        }

        // Key: Value headers
        if(\strpos($line, ':') !== false)
        {
          list($key, $value) = \explode(':', $line, 2);
          $key   = \strtolower(\trim($key));
          $value = \trim($value);

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
    $date = $this->getApplication()->getUserState($element.'.request.date', null);

    if(empty($date))
    {
      $customData = $this->getCustomData($id, $license);
      $date = $customData->get('request_date', '1900-02-02 10:00:00');
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
    $this->loadLanguageFile($id);

    // Get license data
    if(\is_null($data))
    {
      $params = $this->getParamsData($id);
      $data   = $this->getCustomData($id);

      $extension      = self::$data[$id];
      $ressource_name = Text::_(\strtoupper($extension->get('name')) . '_SPFA_RESSOURCE_NAME'); // Name of the SPFA ressource
      $this->requestLicenseData($extension->get('extension_id'), $extension->get('params'), $extension->get('element'), $ressource_name);
    }
    $lang_prefix = \strtoupper($name);

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
        $this->getApplication()->setUserState($element.'.license.state', 0);
        $this->getApplication()->setUserState($element.'.license.msg-type', 'error');
        $this->getApplication()->setUserState($element.'.license.msg-text', Text::_($lang_prefix.'_MSG_LICENSE_DISABLED'));
      }
      else
      {
        $this->getApplication()->setUserState($element.'.license.state', -1);
        $this->getApplication()->setUserState($element.'.license.msg-type', 'error');
        $this->getApplication()->setUserState($element.'.license.msg-text', Text::_($lang_prefix.'_MSG_LICENSE_UNKNOWN'));
      }      
    }
    elseif((int) $data->get('state', 0) > 1)
    {
      // Plugin stays active, but show message that license has expired
      $this->getApplication()->setUserState($element.'.license.state', 2);
      $this->getApplication()->setUserState($element.'.license.msg-type', 'warning');
      $this->getApplication()->setUserState($element.'.license.msg-text', Text::_($lang_prefix.'_MSG_LICENSE_EXPIRED'));
    }
    else
    {
      $this->getApplication()->setUserState($element.'.license.state', 1);
      $this->getApplication()->setUserState($element.'.license.msg-type', 'success');
      $this->getApplication()->setUserState($element.'.license.msg-text', Text::_($lang_prefix.'_MSG_LICENSE_ACTIVE'));
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
    // Only request once a day or if no custom data is available
    $now          = Factory::getDate();
    $last_request = $this->getLastRequest($this->id, 'techspuur', false);
    $time_diff    = $now->getTimestamp() - $last_request->getTimestamp();

    if(!$force_update && ($time_diff < $this->refresh_rate || \file_exists(dirname(__FILE__) . '/offlineuse.txt')))
    {
      // Validation should happen only once every xx seconds or when its enforced
      return false;
    }

    if(is_null(self::$extensions) || empty(self::$extensions))
    {
      try
      {
        self::$extensions = $this->fetchXML($url);
      }
      catch(\Exception $e)
      {
        Log::add(Text::sprintf('PLG_SYSTEM_TECHSPUUR_ERROR_XML_EXTENSIONS', $e->getMessage()), Log::ERROR, 'techspuur');
        return false;
      }
    }

    // Collect all extensions of license=pro
    $date  = Factory::getDate()->toSql();
    $proExtensions = new Registry(['request_date' => $date]);
    $i = 0;
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
    $this->getApplication()->setUserState('techspuur.request.date', $date);

    return $proExtensions;
  }

  /**
   * Method to load an XML from the web.
   *
   * @param   string  $uri  The URI of the feed to load. Idn uris must be passed already converted to punycode.
   *
   * @return  SimpleXMLElement
   *
   * @since   1.0.0
   * @throws  \InvalidArgumentException
   * @throws  \RuntimeException
   */
  private function fetchXML(string $uri): \SimpleXMLElement
  {
    // Create the XMLReader object.
    $reader = new \XMLReader();

    // Enable internal error handling for better debugging
    \libxml_use_internal_errors(true);

    // Open the URI within the stream reader.
    if(!$reader->open($uri, null, LIBXML_NOWARNING | LIBXML_NOERROR))
    {
      // Handle errors and retry using an HTTP client fallback
      $options = new Registry();
      $options->set('userAgent', 'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:41.0) Gecko/20100101 Firefox/41.0');

      try
      {
        $response = HttpFactory::getHttp($options)->get($uri);
      }
      catch(\RuntimeException $e)
      {
        throw new \RuntimeException('Unable to open the feed.', $e->getCode(), $e);
      }

      if($response->code != 200)
      {
        throw new \RuntimeException('Unable to open the feed.');
      }

      // Set the value to the XMLReader parser
      if(!$reader->XML($response->body, null, LIBXML_NOWARNING | LIBXML_NOERROR))
      {
        throw new \RuntimeException('Unable to parse the feed.');
      }
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
          throw new \RuntimeException("Exceeded maximum attempts to find the root element.");
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