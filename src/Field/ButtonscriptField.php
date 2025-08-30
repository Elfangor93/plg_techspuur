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

class ButtonscriptField extends FormField
{
	/**
	 * The form field type.
	 *
	 * @var    string
	 * @since  1.0.0
	 */
	protected $type = 'buttonscript';

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
		$js  = 'function performScript(script) {';
		$js .=     'const form = document.querySelector(\'main form[name="adminForm"]\');';
		$js .=     'if (form) {';
		$js .=        'const hiddenInput = document.createElement("input");';
		$js .=        'hiddenInput.type = "hidden";';
		$js .=        'hiddenInput.name = "jform[params]["+script+"]";';
		$js .=        'hiddenInput.value = "1";';
		$js .=        'form.appendChild(hiddenInput);';
		$js .=     '}';
		$js .=     'const toolbarApply = document.getElementById("toolbar-apply");';
		$js .=     'if (toolbarApply) {';
		$js .=        'const button = toolbarApply.querySelector("button");';
		$js .=        'if (button) {';
		$js .=           'button.click();';
		$js .=        '}';
		$js .=     '}';
		$js .= '};';

		/** @var Joomla\CMS\WebAsset\WebAssetManager $wa */
		$wa = Factory::getApplication()->getDocument()->getWebAssetManager();
		$wa->addInlineScript($js, ['name' => 'techspuur.button.script']);

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

		$html = '<button class="btn btn-primary" onclick="performScript(\''.$this->element['script'].'\');">' . $icon . Text::_($text) . '</button>';

		return $html;
	}
}
