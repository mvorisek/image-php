<?php

declare(strict_types=1);

namespace Mvorisek\Docker\ImagePhp;

$loadedExts = get_loaded_extensions();
$missingExts = array_diff([
    'bcmath',
    'curl',
    'exif',
    'gd',
    'gmp',
    'igbinary',
    'imagick',
    'imap',
    'intl',
    'mbstring',
    'mysqli',
    'mysqlnd',
    'openssl',
    'pdo_mysql',
    'PDO_OCI',
    'pdo_pgsql',
    'pdo_sqlite',
    'pdo_sqlsrv',
    'pcntl',
    'redis',
    'sockets',
    'sqlite3',
    'tidy',
    'xdebug',
    'xml',
    'xsl',
    'Zend OPcache',
    'zip',
], $loadedExts);

if (PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 5) { // TODO remove once the extensions can be installed
    $missingExts = array_diff($missingExts, [
        'pdo_sqlsrv',
    ]);
}

if (count($missingExts) > 0) {
    echo 'TEST FAILED - missing php extensions: ' . implode(', ', $missingExts) . "\n";

    exit(1);
}

$xdebugConfPath = '/usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini';
if (!file_exists($xdebugConfPath) && in_array('xdebug', $loadedExts, true)) {
    echo 'TEST FAILED - missing xdebug configuration: ' . $xdebugConfPath . "\n";

    exit(1);
}
