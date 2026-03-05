<?php
declare(strict_types=1);

/**
 * Export #1 (Grandstream AddressBook)
 *
 * Grandstream: wiele numerów dla jednego kontaktu musi mieć różne typy.
 * Używamy max 3 numerów w kolejności: Work -> Mobile -> Home.
 *
 * @param array<int, array{name:string, phones:array<int, string|int>}> $entries
 */
function pb_export_addressbook_xml(array $entries, string $outFile): void
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

    $root = $dom->createElement('AddressBook');
    $dom->appendChild($root);

    // pbgroup 1: Blacklist
    $g1 = $dom->createElement('pbgroup');
    $g1->appendChild($dom->createElement('id', '1'));
    $g1->appendChild($dom->createElement('name', 'Blacklist'));
    $root->appendChild($g1);

    // pbgroup 2: Whitelist
    $g2 = $dom->createElement('pbgroup');
    $g2->appendChild($dom->createElement('id', '2'));
    $g2->appendChild($dom->createElement('name', 'Whitelist'));
    $root->appendChild($g2);

    // Kolejność typów dla max 3 numerów
    $phoneTypes = ['Work', 'Mobile', 'Home'];

    $id = 1;

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
            if ($p === '') {
                continue;
            }
            $clean[$p] = true;
        }
        $phones = array_keys($clean);

        if (!$phones) {
            continue;
        }

        sort($phones, SORT_NATURAL);

        // bierzemy max 3 numery
        $phones = array_slice($phones, 0, count($phoneTypes));

        $contact = $dom->createElement('Contact');

        $contact->appendChild($dom->createElement('id', (string)$id));
        $contact->appendChild($dom->createElement('LastName', (string)$name));
        $contact->appendChild($dom->createElement('Frequent', '0'));

        // przypisz typy: Work -> Mobile -> Home
        foreach ($phones as $i => $p) {
            $type = $phoneTypes[$i] ?? 'Work'; // fallback (gdyby kiedyś rozszerzyć listę)
            $phone = $dom->createElement('Phone');
            $phone->setAttribute('type', $type);
            $phone->appendChild($dom->createElement('phonenumber', (string)$p));
            $phone->appendChild($dom->createElement('accountindex', '1'));
            $contact->appendChild($phone);
        }

        $contact->appendChild($dom->createElement('Primary', '0'));
        $contact->appendChild($dom->createElement('Department'));

        $root->appendChild($contact);
        $id++;
    }

    $xml = $dom->saveXML();
    if ($xml === false) {
        throw new RuntimeException("Failed to generate AddressBook XML");
    }

    file_put_contents($tmp, $xml);
    rename($tmp, $outFile);
}