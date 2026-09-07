<?php
namespace Elfangor93\Plugin\System\Techspuur\Field;

\defined('_JEXEC') || die();

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Filesystem\Path;

class DownloadlogField extends FormField
{
  protected $type = 'downloadlog';
  protected $hiddenLabel = false;
  protected $hiddenDescription = false;

  protected function getInput()
  {
    $logFolder = Path::clean(Factory::getApplication()->get('tmp_path') . '/techspuur');
    $baseUrl   = Uri::base() . 'index.php?option=plg_techspuur';
    $token     = Session::getFormToken();
    $escape    = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $icon      = '<span class="icon icon-download" aria-hidden="true"></span> ';
    $html      = '<div class="d-flex flex-column gap-2 align-items-start">';
    $html     .= '<p><strong>' . Text::_('PLG_SYSTEM_TECHSPUUR_LOGFILE_PATH') . ':</strong><br>' . $escape($logFolder) . '</p>';

    foreach([
      'downloadDiagnosticLog' => ['diagnostic.log', 'PLG_SYSTEM_TECHSPUUR_FIELD_DOWNLOAD_LOG_TXT'],
      'downloadSensitiveLog'  => ['sensitive.log', 'PLG_SYSTEM_TECHSPUUR_FIELD_DOWNLOAD_SENSITIVE_LOG_TXT'],
    ] as $task => [$filename, $label])
    {
      if(is_file($logFolder . DIRECTORY_SEPARATOR . $filename))
      {
        $url   = $baseUrl . '&task=' . $task . '&' . $token . '=1';
        $html .= '<a class="btn btn-secondary" href="' . $escape($url) . '">' . $icon . Text::_($label) . '</a>';
      }
    }

    if(!is_file($logFolder . DIRECTORY_SEPARATOR . 'diagnostic.log') && !is_file($logFolder . DIRECTORY_SEPARATOR . 'sensitive.log'))
    {
      $html .= '<p>' . Text::_('PLG_SYSTEM_TECHSPUUR_NO_LOGFILE_FOUND') . '</p>';
    }

    $html .= '<form method="post" action="' . $escape($baseUrl . '&task=deleteLogs') . '">';
    $html .= '<input type="hidden" name="' . $escape($token) . '" value="1">';
    $html .= '<button type="submit" class="btn btn-danger"><span class="icon icon-trash" aria-hidden="true"></span> ' . Text::_('PLG_SYSTEM_TECHSPUUR_FIELD_DELETE_LOGS_TXT') . '</button>';
    $html .= '</form></div>';

    return $html;
  }

  protected function getLabel()
  {
    return '';
  }
}
