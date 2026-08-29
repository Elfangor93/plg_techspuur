<?php
/**
 * **************************************************************************
 *    @package    plg_system_techspuur                                     **
 *    @author     Manuel Häusler <tech.spuur@quickline.ch>                 **
 *    @copyright  2026 Manuel Haeusler                                     **
 *    @license    GNU General Public License version 3 or later            **
 * **************************************************************************
 */

// No direct access
\defined('_JEXEC') || die;

use Elfangor93\Plugin\System\Techspuur\Extension\TechSpuur;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;

return new class implements ServiceProviderInterface
{
  public function register(Container $container)
  {
    $container->set(
      PluginInterface::class,
      function (Container $container) {
        $config  = (array)PluginHelper::getPlugin('system', 'techspuur');
        $subject = $container->get(DispatcherInterface::class);

        /** @var \Joomla\CMS\Plugin\CMSPlugin $plugin */
        $plugin = new TechSpuur($subject, $config);
        $plugin->setApplication(Factory::getApplication());

        return $plugin;
      }
    );
  }
};
