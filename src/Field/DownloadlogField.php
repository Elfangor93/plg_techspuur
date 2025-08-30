<?php
/**
****************************************************************************
**   @package    plg_system_joomplupro                                    **
**   @author     Manuel Häusler <tech.spuur@quickline.ch>                 **
**   @copyright  2025 Manuel Haeusler                                     **
**   @license    GNU General Public License version 3 or later            **
****************************************************************************/

namespace Elfangor93\Plugin\System\Techspuur\Field;

defined('_JEXEC') or die();

use \Joomla\CMS\Factory;
use \Joomla\CMS\Form\FormField;
use \Joomla\CMS\Language\Text;
use \Joomla\CMS\Uri\Uri;
use \Joomla\Filesystem\Path;

class DownloadlogField extends FormField
{
	/**
	 * The form field type.
	 *
	 * @var    string
	 * @since  1.0.0
	 */
	protected $type = 'downloadlog';

	/**
	 * Hide the label when rendering the form field.
	 *
	 * @var    boolean
	 * @since  1.0.0
	 */
	protected $hiddenLabel = false;

	/**
	 * Hide the description when rendering the form field.
	 *
	 * @var    boolean
	 * @since  1.0.0
	 */
	protected $hiddenDescription = false;

	/**
	 * Method to get the field input markup.
	 *
	 * @return  string  The field input markup.
	 *
	 * @since   1.0.0
	 */
	protected function getInput()
	{
    // Define inline Script
		$log_folder     = Factory::getApplication()->get('tmp_path') . '/techspuur/';
		$log_folder_uri = Uri::root() . \str_replace(JPATH_ROOT.'/', '', $log_folder);
		$log_folder     = Path::clean($log_folder);
		$log_file       = $this->getLatestLogFile($log_folder);

		$icon = '';
		if($this->element['icon'])
		{
			$icon = '<span class="icon icon-' . $this->element['icon'] . '"></span> ';
		}

		$text = '';
		if($this->element['text'])
		{
			$text = $this->element['text'];
		}

		// Create output
		$html  = '<div>';
		$html .= '<p><strong>Log-File path:</strong><br>'.$log_folder.'</p>';
		if($log_file)
		{
			// There is a log file to be downloaded
			$html .= '<a class="btn btn-secondary" href="'.$log_folder_uri.\basename($log_file).'">' . $icon . Text::_($text) . '</a>';
		}
		else
		{
			// No current logfile found
			$html .= '<p>No current log file available. Create a request first.</p>';
		}
		$html .= '</div>';

		return $html;
	}

	protected function getLabel()
	{
		return '';
	}

	/**
	 * Method to get the latest available log file
	 * 
	 * @param   string    $folderPath     Folder path of the log files
	 * @param   int       $maxAge         Max age of the file to be returned (older files are not returned)
	 * @param   string    $prefix         Part of the filename before the unix time string
	 * @param   string    $suffix         Part of the filename after the unix time string
	 *
	 * @return  string  The field input markup.
	 *
	 * @since   1.0.0
	 */
	protected function getLatestLogFile($folderPath, $maxAge = 600, $prefix = 'requestServer_log_', $suffix = '.txt')
	{
    $latestFile = false;
    $latestTimestamp = 0;
    $now = time();

    // Ensure folder exists
    if(!\is_dir($folderPath))
		{
			if(!\is_dir(\dirname($folderPath)))
			{
				// Parent doesn't exist
				return false;
			}

        // Create the folder
        if(!\mkdir($folderPath, 0777, false))
				{
          return false;
        }
    }

    // Read files in folder
    $files = \scandir($folderPath);
    foreach($files as $file)
		{
			// Match files with correct pattern
			if(\preg_match("/^" . \preg_quote($prefix, '/') . "(\d+)" . \preg_quote($suffix, '/') . "$/", $file, $matches))
			{
				$timestamp = (int) $matches[1];

				// Check if timestamp is within the last 10 minutes
				if(($now - $timestamp) <= $maxAge && $timestamp > $latestTimestamp)
				{
					$latestTimestamp = $timestamp;
					$latestFile = $file;
				}
			}
    }

    // Return full path or just file name
    return $latestFile ? $folderPath . DIRECTORY_SEPARATOR . $latestFile : false;
	}
}
