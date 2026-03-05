<?php
declare(strict_types=1);

/**
 * Export #2 (Yealink)
 *
 * @param array<int, array{name:string, phones:array<int, string|int>}> $entries
 */
function pb_export_yealink_xml(array $entries, string $outFile, string $menuName = 'Meblomaster'): void
{
    $dir = dirname($outFile);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create output dir: {$dir}");
        }
    }

    $tmp = $outFile . '.tmp';

    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true;

    $root = $dom->createElement('YealinkIPPhoneBook');
    $dom->appendChild($root);

    $root->appendChild($dom->createElement('Title', 'Yealink'));

    $menu = $dom->createElement('Menu');
    $menu->setAttribute('Name', (string)$menuName);

    foreach ($entries as $row) {
        $name = trim((string)($row['name'] ?? ''));
        $phones = $row['phones'] ?? [];

        if ($name === '' || !is_array($phones) || count($phones) === 0) {
            continue;
        }

        // wyczyść numery + usuń duplikaty (stringujemy)
        $clean = [];
        foreach ($phones as $p) {
            $p = trim((string)$p);
            if ($p === '') continue;
            $clean[$p] = true;
        }
        $phones = array_keys($clean);

        if (!$phones) continue;

        sort($phones, SORT_NATURAL);

        $unit = $dom->createElement('Unit');
        $unit->setAttribute('Name', (string)$name);
        $unit->setAttribute('default_photo', 'Resource:');

        // Phone1..Phone3
        $maxPhones = 3;
        $idx = 1;
        foreach ($phones as $p) {
            if ($idx > $maxPhones) break;
            $unit->setAttribute('Phone' . $idx, (string)$p);
            $idx++;
        }

        if (!$unit->hasAttribute('Phone1')) continue;

        $menu->appendChild($unit);
    }

    $root->appendChild($menu);

    $xml = $dom->saveXML();
    if ($xml === false) {
        throw new RuntimeException("Failed to generate Yealink XML");
    }

    file_put_contents($tmp, $xml);
    rename($tmp, $outFile);
}