<?php
/**
****************************************************************************
**   @package    plg_system_techspuur                                     **
**   @author     Manuel Häusler <tech.spuur@quickline.ch>                 **
**   @copyright  2025 Manuel Haeusler                                     **
**   @license    GNU General Public License version 3 or later            **
****************************************************************************/

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

if(!$displayData)
{
  return;
}
?>

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
          <?php echo (string) $extension['name']; ?>
          <div class="small break-word">
            <?php echo ucfirst((string) $extension['type']); ?>
            <?php if(isset($extension['infourl']) && !empty($extension['infourl'])) : ?>
              , <a href="<?php echo (string) $extension['infourl']; ?>" target="_blank"><?php echo Text::_('JVISIT_WEBSITE'); ?></a>
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
            echo $license;
          ?>
        </td>
        <td class="d-md-table-cell">
          <?php echo (string) $extension['version']; ?>
        </td>
        <td class="d-md-table-cell">
          <?php echo (string) $extension['description']; ?>
        </td>
        <td class="d-md-table-cell small">
          <?php if(isset($extension['downloadurl']) && !empty($extension['downloadurl'])) : ?>
            <a href="<?php echo (string) $extension['downloadurl']; ?>" target="_blank">
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
