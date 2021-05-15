<?php

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
], get_loaded_extensions());

if (count($missingExts) > 0) {
    echo 'ERROR - missing php extensions: ' . implode(', ', $missingExts) . "\n";
    exit(1);
}

$xdebugConfPath = '/usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini';
if (!file_exists($xdebugConfPath)) {
    echo 'ERROR - missing xdebug configuration: ' . $xdebugConfPath . "\n";
    exit(1);
}
