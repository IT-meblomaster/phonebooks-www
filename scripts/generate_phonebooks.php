#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Usage:
 *   php generate_phonebooks.php /path/out1.xml /path/out2.xml /path/out3.csv
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from CLI.\n");
    exit(1);
}



$out1 = $argv[1] ?? '';
$out2 = $argv[2] ?? '';
$out3 = $argv[3] ?? '';

function logLine(string $level, string $message): void
{
    $line = sprintf(
        "[%s] [%s] %s%s",
        date('Y-m-d H:i:s'),
        strtoupper($level),
        $message,
        PHP_EOL
    );

    if (strtoupper($level) === 'ERROR') {
        fwrite(STDERR, $line);
        return;
    }

    fwrite(STDOUT, $line);
}


if ($out1 === '' || $out2 === '' || $out3 === '') {
    fwrite(STDERR, "Usage: php generate_phonebooks.php out_grandstream.xml out_yealink.xml out_rtx.csv\n");
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
require_once $baseDir . '/inc/phonebook_export_rtx.php';

$adCfg = $config['ad'] ?? null;

if (!is_array($adCfg) || !pb_has_domain_access($adCfg)) {
    fwrite(STDERR, "SKIP: brak dostępu do domeny/AD, generowanie pominięte.\n");
    exit(0);
}

try {
    $pdo = pb_db_connect($config);
    $entries = pb_build_directory($config, $pdo); // ONLY complete entries (name+number)

    pb_export_addressbook_xml($entries, $out1);
    pb_export_yealink_xml($entries, $out2, 'Meblomaster');
    pb_export_rtx_csv($entries, $out3);

    logLine('info', 'Generated ' . count($entries) . ' entries');
    logLine('info', $out1);
    logLine('info', $out2);
    logLine('info', $out3);

} catch (Throwable $e) {
    logLine('error', $e->getMessage());
    exit(1);
}