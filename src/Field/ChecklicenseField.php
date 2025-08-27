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
use \Joomla\Database\DatabaseInterface;
use \Joomla\CMS\HTML\HTMLHelper;

class ChecklicenseField extends FormField
{
	/**
	 * The form field type.
	 *
	 * @var    string
	 * @since  1.0.0
	 */
	protected $type = 'checklicense';

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
	 * Method to get the field label markup.
	 *
	 * @return  string  The field label markup.
	 *
	 * @since   1.0.0
	 */
	protected function getLabel()
	{
		// Define inline Script
		$js  = 'function checkLicense() {';
		$js .=     'const selectElement = document.getElementById("jform_enabled");';
		$js .=     'if (selectElement) {';
		$js .=        'selectElement.value = "1";';
		$js .=        'const changeEvent = new Event("change", { bubbles: true });';
		$js .=        'selectElement.dispatchEvent(changeEvent);';
		$js .=     '}';
		$js .=     'const form = document.querySelector(\'main form[name="adminForm"]\');';
		$js .=     'if (form) {';
		$js .=        'const hiddenInput = document.createElement("input");';
		$js .=        'hiddenInput.type = "hidden";';
		$js .=        'hiddenInput.name = "jform[params][force_update]";';
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
		$wa->addInlineScript($js);

		$html = '<button class="btn btn-primary" onclick="checkLicense();"><span class="icon icon-tag"></span> ' . Text::_($this->element['label']) . '</button>';

		return $html;
	}

	/**
	 * Method to get the field input markup.
	 *
	 * @return  string  The field input markup.
	 *
	 * @since   1.0.0
	 */
	protected function getInput()
	{
    try {
      $data = $this->getLicenseData();
    } catch (\Exception $e) {
      $data = null;
    }

    // Define inline CSS
    $css  = '.license-icon {display: flex; padding-top: 0.4rem;}';
    $css .= '.license-icon [class^="icon-"], .license-icon [class*=" icon-"], .license-icon [class^="fa-"], .license-icon [class*=" fa-"] {';
    $css .= 'float: left; color: #cdcdcd; border: 2px solid var(--border); border-radius: 50%; width: 35px; height: 35px; font-size: 1.7rem; line-height: 32px;';
    $css .= '}';
    $css .= '.license-icon .icon-publish::before {padding-left: 3px;}';
    $css .= '.license-icon .icon-unpublish::before {padding-left: 5px;}';
    $css .= '.license-icon .icon-unfeatured::before {padding-left: 2px;}';
    $css .= '.license-icon .icon-publish {color: var(--success);border-color: var(--success);}';
    $css .= '.license-icon .icon-unpublish {color: var(--danger);border-color: var(--danger);}';
    $css .= '.license-icon .label {padding-left: 1rem; display: inline-block;}';
    $css .= '.license-icon + div {margin-top: 0.4rem;}';

    /** @var Joomla\CMS\WebAsset\WebAssetManager $wa */
    $wa = Factory::getApplication()->getDocument()->getWebAssetManager();
    $wa->addInlineStyle($css);

    $icon_class = 'icon-unfeatured';
    $state_lbl  = Text::_('PLG_CONTENT_JOOMPLUPRO_LICENSE_STATE');
    $state_txt  = Text::_('PLG_CONTENT_JOOMPLUPRO_UNKNOWN');

    if($data)
    {
      switch((int) $data->state)
      {
        case 1:
          // License active
          $icon_class = 'icon-publish';
          $exp_date   = HTMLHelper::_('date', $data->expiration_date, Text::_('DATE_FORMAT_LC4'));
          $state_txt  = Text::_('PLG_CONTENT_JOOMPLUPRO_ACTIVE');
          $state_txt  .= '<br><small>(' . Text::sprintf('PLG_CONTENT_JOOMPLUPRO_EXPIRATION_LABEL', $exp_date) . ', ' . Text::sprintf('PLG_CONTENT_JOOMPLUPRO_NUMLICENSES_LABEL', $data->num_licenses) . ')</small>';
          break;

        case 2:
          // License expired
          $icon_class = 'icon-unpublish';
          $exp_date   = HTMLHelper::_('date', $data->expiration_date, Text::_('DATE_FORMAT_LC4'));
          $state_txt  = Text::_('PLG_CONTENT_JOOMPLUPRO_EXPIRED');
          $state_txt  .= '<br><small>(' . Text::sprintf('PLG_CONTENT_JOOMPLUPRO_EXPIRATION_LABEL', $exp_date) . ', ' . Text::sprintf('PLG_CONTENT_JOOMPLUPRO_NUMLICENSES_LABEL', $data->num_licenses) . ')</small>';
          break;

        case 0:
          // License disabled / User blocked
          $icon_class = 'icon-unpublish';
          $state_txt  = Text::_('PLG_CONTENT_JOOMPLUPRO_DISABLED');
          break;
        
        default:
          break;
      }      
    }

    return '<div class="license-icon"><span class="icon '.$icon_class.'" aria-hidden="true"></span><span class="label"><strong>'.$state_lbl.':</strong> '.$state_txt.'</span></div>';
	}

  /**
	 * Method to get the license data.
	 *
	 * @return  object
	 *
	 * @since   1.0.0
	 */
	protected function getLicenseData()
	{
    $db = Factory::getContainer()->get(DatabaseInterface::class);
    $query = $db->getQuery(true);

    // Create query
    $query
      ->select($db->quoteName('custom_data'))
      ->from($db->quoteName('#__extensions'))
      ->where(
        [ $db->quoteName('type') . ' = ' . $db->quote('plugin'),
          $db->quoteName('element') . ' = ' . $db->quote('joomplupro'),
        ]
      );

    // Perform the query
    $db->setQuery($query);
    $result = $db->loadResult();

    return \json_decode($result);
	}
}
