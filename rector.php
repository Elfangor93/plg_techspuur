<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;

return static function (RectorConfig $rectorConfig): void {
  $paths = [
    __DIR__ . '/plugin',
  ];

  $rectorConfig->paths($paths);

  // Joomla is analysis-only and is intentionally excluded from Rector's paths.
  $rectorConfig->autoloadPaths([
    __DIR__ . '/joomla'
  ]);

  $rectorConfig->sets([
    LevelSetList::UP_TO_PHP_82,
    SetList::CODE_QUALITY,
    __DIR__ . '/vendor/joomla-projects/typehints/rector/joomla_5_0.php',
  ]);
};
