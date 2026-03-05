#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Usage:
 *   php generate_phonebooks.php /path/out1.xml /path/out2.xml
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from CLI.\n");
    exit(1);
}

$out1 = $argv[1] ?? '';
$out2 = $argv[2] ?? '';
if ($out1 === '' || $out2 === '') {
    fwrite(STDERR, "Usage: php generate_phonebooks.php out_addressbook.xml out_yealink.xml\n");
    exit(1);
}

$baseDir = realpath(__DIR__ . '/..');
if ($baseDir === false) {
    fwrite(STDERR, "Cannot determine base directory.\n");
    exit(1);
}

$configPath = $baseDir . '/config/config.php';
if (!file_exists($configPath)) {
    fwrite(STDERR, "Config not found: {$configPath}\n");
    exit(1);
}

$config = require $configPath;

require_once $baseDir . '/inc/phonebook_builder.php';
require_once $baseDir . '/inc/phonebook_export_grandstream.php';
require_once $baseDir . '/inc/phonebook_export_yealink.php';

try {
    $pdo = pb_db_connect($config);
    $entries = pb_build_directory($config, $pdo); // ONLY complete entries (name+number)

    pb_export_addressbook_xml($entries, $out1);
    pb_export_yealink_xml($entries, $out2, 'Meblomaster');

    echo "OK: generated " . count($entries) . " entries\n";
    echo " - {$out1}\n";
    echo " - {$out2}\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
