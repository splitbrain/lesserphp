<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php54\Rector\Array_\LongArrayToShortArrayRector;
use Rector\Set\ValueObject\LevelSetList;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([
        //__DIR__ . '/tests',
        __DIR__ . '/lessc.inc.php',
    ]);

    $rectorConfig->sets([
        LevelSetList::UP_TO_PHP_74
    ]);
};
