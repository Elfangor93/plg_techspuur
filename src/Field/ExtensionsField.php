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
use \Joomla\CMS\Layout\LayoutHelper;
use \Elfangor93\Plugin\System\Techspuur\Extension\TechSpuur;

class ExtensionsField extends FormField
{
	/**
	 * The form field type.
	 *
	 * @var    string
	 * @since  1.0.0
	 */
	protected $type = 'tsextensions';

	/**
	 * Hide the label when rendering the form field.
	 *
	 * @var    boolean
	 * @since  1.0.0
	 */
	protected $hiddenLabel = true;

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
		$dispatcher = Factory::getApplication()->getDispatcher();
		$plugin     = new TechSpuur($dispatcher, ['id' => 0]);
		$url        = 'https://updates.spuur.ch/extensions.xml';

		$layoutPath = JPATH_PLUGINS . '/system/techspuur/layouts';
		$local_xml  = \dirname(\dirname(__FILE__)) . '/Extension/' . \basename($url);

		try
		{
			$extensions = $plugin->fetchXML($url);
		}
		catch(\Exception $e)
		{
			if(\is_file($local_xml))
			{
				// Load local xml instead
				$extensions = \simplexml_load_file($local_xml);
			}
			else
			{
				return false;
			}
		}

		return LayoutHelper::render('extensions', $extensions, $layoutPath);
	}

	protected function getLabel()
	{
		return '';
	}
}
