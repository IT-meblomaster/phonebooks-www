<?php
declare(strict_types=1);

/**
 * inc/phonebook_export_rtx.php
 *
 * Format CSV: Nazwisko Imie,nr_work,nr_home,nr_mobile
 *
 * RTX obsługuje 3 numery:
 *   nr_1 = Work  (telephoneNumber)
 *   nr_2 = Home  (ipPhone)
 *   nr_3 = Mobile (mobile)
 *
 * @param array<int, array{name:string, phones:array<int, array{number:string, type:string}>}> $entries
 */
function pb_export_rtx_csv(array $entries, string $outFile): void
{
    $dir = dirname($outFile);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create output dir: {$dir}");
        }
    }

    $tmp = $outFile . '.tmp';

    // 1) mapowanie numer -> [names...] (dla wykrywania konfliktów)
    $namesByNumber = [];
    foreach ($entries as $row) {
        $name   = trim((string)($row['name'] ?? ''));
        $phones = $row['phones'] ?? [];
        if ($name === '' || !is_array($phones) || !$phones) continue;

        foreach ($phones as $p) {
            $num = trim((string)($p['number'] ?? ''));
            if ($num === '') continue;
            if (!isset($namesByNumber[$num])) $namesByNumber[$num] = [];
            $namesByNumber[$num][$name] = true;
        }
    }

    // 2) zapis CSV
    $fh = fopen($tmp, 'wb');
    if ($fh === false) {
        throw new RuntimeException("Cannot write to: {$tmp}");
    }

    foreach ($entries as $row) {
        $name   = trim((string)($row['name'] ?? ''));
        $phones = $row['phones'] ?? [];
        if ($name === '' || !is_array($phones) || !$phones) continue;

        // Wyciągnij numery wg typu
        $nr1 = ''; // Work
        $nr2 = ''; // Home
        $nr3 = ''; // Mobile
        foreach ($phones as $p) {
            $num  = trim((string)($p['number'] ?? ''));
            $type = (string)($p['type'] ?? '');
            if ($num === '') continue;
            if ($type === 'Work'   && $nr1 === '') { $nr1 = $num; continue; }
            if ($type === 'Home'   && $nr2 === '') { $nr2 = $num; continue; }
            if ($type === 'Mobile' && $nr3 === '') { $nr3 = $num; continue; }
        }

        if ($nr1 === '' && $nr2 === '' && $nr3 === '') continue;

        // Konflikt: jeżeli numer ma wielu właścicieli -> dopisz do pola nazwy
        $owners = [$name => true];
        foreach ([$nr1, $nr2, $nr3] as $num) {
            if ($num === '') continue;
            $otherOwners = $namesByNumber[$num] ?? [];
            if (count($otherOwners) > 1) {
                foreach ($otherOwners as $ownerName => $_) {
                    $ownerName = trim((string)$ownerName);
                    if ($ownerName !== '') $owners[$ownerName] = true;
                }
            }
        }

        $nameField = implode(';', array_keys($owners));
        $nameField = str_replace(',', '', $nameField);
        $nameField = substr($nameField, 0, 21);

        fwrite($fh, $nameField . ',' . $nr1 . ',' . $nr2 . ',' . $nr3 . "\n");
    }

    fclose($fh);
    rename($tmp, $outFile);
}