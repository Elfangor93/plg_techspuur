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
   * Storage for extension data
   *
   * @var    array
   * @since  1.0.0
   */
  protected static $data = [];

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
      'onAfterRoute'			   => ['onAfterRoute', Priority::HIGH],
      'onContentPrepareForm' => ['onContentPrepareForm', Priority::HIGH],
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
      $ext = $this->getExtension($id);
      $this->checkLicenseData($id, $ext->get('element'), $ext->get('name'));
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

    // Run this plugin only for valid extension forms
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
        \key_exists('dlid', $data->params) && $data->params['dlid']
      )
    {
      $this->loadLanguageFile($extension->get('extension_id'));

      // Force a new license validation
      $data_params    = new Registry($data->params);
      $ressource_name = Text::_(\strtoupper($extension->get('name')) . '_SPFA_RESSOURCE_NAME'); // Name of the SPFA ressource
      $this->requestLicenseData($extension->get('extension_id'), $data_params, $extension->get('element'), $ressource_name, true);
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
   * @return  array  List of extension ids
   * 
   * @since   1.0.0
   */
  private function getExtensions($mode = 'ids'): array
  {
    if($this->id > 0 && empty($data))
    {
      $ids = $this->getCustomData($this->id, false);
      $ids = $ids->toArray();

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
          Log::add('Error fetching tech.spuur extensions: ' . $e->getMessage(), Log::ERROR, 'techspuur');
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
        Log::add('Error fetching extension: ' . $e->getMessage(), Log::ERROR, 'techspuur');
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
        Log::add('Error fetching extension: ' . $e->getMessage(), Log::ERROR, 'techspuur');
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
   * @param   int        $id    Extension id
   * @param   Registry   $data  The new custom data
   * 
   * @return  void
   * 
   * @since   1.0.0
   */
  private function setCustomData(int $id, Registry $data)
  {
    $this->getExtension($id);

    if($data->count() < 2 || !$data->exists('state'))
    {
      // There is no valid license data to be set
      Log::add('Error storing custom data: There is no valid license data to be set.', Log::ERROR, 'techspuur');

      return;
    }

    self::$data[$id]->set('custom_data', $data);

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
      Log::add('Error storing custom data: ' . $e->getMessage(), Log::ERROR, 'techspuur');
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
      Log::add('Error disabling extension: ' . $e->getMessage(), Log::ERROR, 'techspuur');
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

    if(!$force_update && ($time_diff < 43200 || \file_exists(dirname(__FILE__) . '/offlineuse.txt')))
    {
      // Validation should happen only once every 12 hours or when its enforced
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
      $license_data->set('expiration_date', '');
      $license_data->set('request_date', Factory::getDate()->toSql());

      if($response->code === 200)
      {
        // Decode JSON response
        $response_body = \json_decode($response->body, true);
        if($response_body === null)
        {
          Log::add('Error decoding response JSON: ' . json_last_error_msg(), Log::ERROR, 'techspuur');
          Log::add($response->body, Log::ERROR, 'techspuur');

          return;
        }

        // Decode JSON response body data
        $license_data_array = \json_decode($response_body['data'], true);
        if($license_data_array === null)
        {
          Log::add('Error decoding response body data JSON: ' . json_last_error_msg(), Log::ERROR, 'techspuur');
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

        // Log data if state < 1
        if($license_data->get('state') < 1)
        {
          // State definition
          $license_state    = ['-1'=>'unknown', '0'=>'disabled', '1'=>'active', '2'=>'expired'];

          // Prepare log data
          $logdata_sent     = '[username: '.$formData['username'].', license key: '.$formData['dlid'].', referer: '.$headers['Referer'].']';
          $logdata_received = '[license state: '.$license_state[$license_data->get('state', '-1')].', domain: '.$license_data->get('domain', '-').', expiration date: '.$license_data->get('expiration_date').']';

          // Logging
          Log::add('No valid license information received', Log::WARNING, 'techspuur');
          Log::add('Data sent to server: ' . $logdata_sent, Log::WARNING, 'techspuur');
          Log::add('Data received from server: ' . $logdata_received, Log::WARNING, 'techspuur');
        }

        $this->getApplication()->setUserState($element.'.request.date', $license_data->get('request_date'));
      }
      elseif($response->code < 500)
      {
        // Access denied
        Log::add('Failed requesting license data: Response code:' . $response->code . ', Response body:' . $response->body, Log::WARNING, 'techspuur');
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

        Log::add('Error requesting license data: Response code:' . $response->code . ', Response body:' . $response_body, Log::ERROR, 'techspuur');
      }
    }
    catch(\Exception $e)
    {
      // Application Error
      Log::add('Error requesting license data: ' . $e->getMessage(), Log::ERROR, 'techspuur');
    }

    $this->setCustomData($id, $license_data);
  }

  /**
   * Gets the last license validate request date
   * 
   * @param   int      $id        Extension id
   * @param   string   $element   Extension element
   * 
   * @return  Date
   * 
   * @since   1.1.0
   */
  private function getLastRequest(int $id, string $element)
  {
    $date = $this->getApplication()->getUserState($element.'.request.date', null);

    if(empty($date))
    {
      $customData = $this->getCustomData($id);
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
}
