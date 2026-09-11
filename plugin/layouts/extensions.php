<?php
/**
 * **************************************************************************
 *    @package    plg_system_techspuur                                     **
 *    @author     Manuel Häusler <tech.spuur@quickline.ch>                 **
 *    @copyright  2026 Manuel Haeusler                                     **
 *    @license    GNU General Public License version 3 or later            **
 * **************************************************************************
 */

\defined('_JEXEC') || die;

use Joomla\CMS\Language\Text;

/** @var object $displayData */
$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$safeUrl = static function ($value) use ($escape): string {
  $url = trim((string) $value);

  if(filter_var($url, FILTER_VALIDATE_URL) === false || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https')
  {
    return '';
  }

  return $escape($url);
};
?>

<h4><?php echo Text::_('PLG_SYSTEM_TECHSPUUR_EXTENSIONS_TITLE'); ?></h4>
<table id="jg-extensions" class="table table-striped">
  <thead>
    <tr>
      <th class="w-25 fw-bold">
        <?php echo Text::_('JGLOBAL_TITLE'); ?>
      </th>
      <th class="w-12 fw-bold">
        <?php echo Text::_('PLG_SYSTEM_TECHSPUUR_LICENSE'); ?>
      </th>
      <th class="w-12 fw-bold">
        <?php echo Text::_('PLG_SYSTEM_TECHSPUUR_VERSION'); ?>
      </th>
      <th class="fw-bold">
        <?php echo Text::_('JGLOBAL_DESCRIPTION'); ?>
      </th>
      <th class="w-12 fw-bold">
        <?php echo Text::_('PLG_SYSTEM_TECHSPUUR_DOWNLOAD'); ?>
      </th>
    </tr>
  </thead>
  <tbody>
    <?php foreach($displayData->extension as $extension) : ?>
      <tr>
        <td class="d-md-table-cell">
          <?php echo $escape($extension['name']); ?>
          <div class="small break-word">
            <?php echo $escape(ucfirst((string) $extension['type'])); ?>
            <?php $infoUrl = isset($extension['infourl']) ? $safeUrl($extension['infourl']) : ''; ?>
            <?php if($infoUrl !== '') : ?>
              , <a href="<?php echo $infoUrl; ?>" target="_blank" rel="noopener noreferrer"><?php echo Text::_('JVISIT_WEBSITE'); ?></a>
            <?php endif; ?>
          </div>
        </td>
        <td class="d-md-table-cell">
          <?php
            $license = (string) $extension['license'];

      if($license == 'pro')
      {
        $license = 'paid';
      }
      echo $escape($license);
      ?>
        </td>
        <td class="d-md-table-cell">
          <?php echo $escape($extension['version']); ?>
        </td>
        <td class="d-md-table-cell">
          <?php echo $escape($extension['description']); ?>
        </td>
        <td class="d-md-table-cell small">
          <?php $downloadUrl = isset($extension['downloadurl']) ? $safeUrl($extension['downloadurl']) : ''; ?>
          <?php if($downloadUrl !== '') : ?>
            <a href="<?php echo $downloadUrl; ?>" target="_blank" rel="noopener noreferrer">
              <?php echo Text::_('PLG_SYSTEM_TECHSPUUR_DOWNLOAD'); ?>
            </a>
          <?php else : ?>
            <?php echo '-'; ?>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
