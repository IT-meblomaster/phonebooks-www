<?php
declare(strict_types=1);

/**
 * inc/phonebook_export_yealink.php
 *
 * Yealink nie ma typów — tylko Phone1/Phone2/Phone3.
 * Kolejność: Work (telephoneNumber) → Home (ipPhone) → Mobile (mobile)
 *
 * @param array<int, array{name:string, phones:array<int, array{number:string, type:string}>}> $entries
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
        $name   = trim((string)($row['name'] ?? ''));
        $phones = $row['phones'] ?? [];

        if ($name === '' || !is_array($phones) || !$phones) {
            continue;
        }

        // Wyciągnij numery (zachowaj kolejność z buildera), max 3
        $clean = [];
        foreach ($phones as $p) {
            $num = trim((string)($p['number'] ?? ''));
            if ($num !== '' && !in_array($num, $clean, true)) {
                $clean[] = $num;
            }
            if (count($clean) >= 3) break;
        }
        if (!$clean) continue;

        $unit = $dom->createElement('Unit');
        $unit->setAttribute('Name', $name);
        $unit->setAttribute('default_photo', 'Resource:');

        foreach ($clean as $idx => $num) {
            $unit->setAttribute('Phone' . ($idx + 1), $num);
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