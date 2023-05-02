<?php

declare(strict_types=1);

namespace Mvorisek\Docker\ImagePhp;

$loadedExts = get_loaded_extensions();
$missingExts = array_diff([
    'bcmath',
    'imagick',
], $loadedExts);

if (count($missingExts) > 0) {
    echo 'TEST FAILED - missing php extensions: ' . implode(', ', $missingExts) . "\n";

    exit(1);
}
