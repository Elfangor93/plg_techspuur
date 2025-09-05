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
use Joomla\CMS\Layout\LayoutHelper;
use \Elfangor93\Plugin\System\Techspuur\Extension\TechSpuur;
use \Joomla\CMS\Language\Text;
use \Joomla\CMS\Uri\Uri;
use \Joomla\Filesystem\Path;

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

		$layoutPath = JPATH_PLUGINS . '/system/techspuur/layouts';
		$extensions = $plugin->fetchXML('https://updates.spuur.ch/extensions.xml');

		return LayoutHelper::render('extensions', $extensions, $layoutPath);
	}

	protected function getLabel()
	{
		return '';
	}
}
