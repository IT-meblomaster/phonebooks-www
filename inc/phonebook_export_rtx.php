<?php
declare(strict_types=1);

/**
 * Export #3 (RTX) - CSV
 *
 * Format:
 *   Nazwisko Imie,nr_1,nr_2
 *
 * Zasada konfliktu:
 * - CSV jest rozdzielany przecinkami,
 * - jeśli TEN SAM numer telefonu występuje u kilku osób,
 *   to w polu "Nazwisko Imie" zapisujemy listę osób rozdzieloną średnikiem ';'
 *   (np. "Kowalski Jan;Nowak Adam").
 *
 * @param array<int, array{name:string, phones:array<int, string|int>}> $entries
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

    // 1) mapowanie numer -> [names...]
    $namesByNumber = []; // string => array<string,true>
    foreach ($entries as $row) {
        $name = trim((string)($row['name'] ?? ''));
        $phones = $row['phones'] ?? [];

        if ($name === '' || !is_array($phones) || !$phones) {
            continue;
        }

        foreach ($phones as $p) {
            $num = trim((string)$p);
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
        $name = trim((string)($row['name'] ?? ''));
        $phones = $row['phones'] ?? [];

        if ($name === '' || !is_array($phones) || count($phones) === 0) {
            continue;
        }

        // wyczyść + deduplikuj numery (string)
        $clean = [];
        foreach ($phones as $p) {
            $num = trim((string)$p);
            if ($num === '') continue;
            $clean[$num] = true;
        }
        $phones = array_keys($clean);
        if (!$phones) continue;

        sort($phones, SORT_NATURAL);

        // RTX format: tylko 2 numery
        $nr1 = $phones[0] ?? '';
        $nr2 = $phones[1] ?? '';

        // konflikt: jeżeli nr1 lub nr2 ma wielu właścicieli -> dopisz do pola nazwy listę rozdzieloną ';'
        $owners = [$name => true];

        foreach ([$nr1, $nr2] as $num) {
            if ($num === '') continue;

            $otherOwners = $namesByNumber[$num] ?? null;
            if (is_array($otherOwners) && count($otherOwners) > 1) {
                foreach ($otherOwners as $ownerName => $_) {
                    $ownerName = trim((string)$ownerName);
                    if ($ownerName !== '') $owners[$ownerName] = true;
                }
            }
        }

        $nameField = implode(';', array_keys($owners));
        $nameField = str_replace(',', '', $nameField);
        $nameField = substr($nameField, 0, 21);

        // CSV separator = ',', wartości z przecinkami zostaną poprawnie zacytowane przez fputcsv
        fwrite($fh, $nameField . ',' . $nr1 . ',' . $nr2 . "\n");
    }

    fclose($fh);

    // atomowa podmiana
    rename($tmp, $outFile);
}