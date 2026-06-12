<?php
declare(strict_types=1);

/**
 * inc/phonebook_export_grandstream.php
 *
 * Typy pól Grandstream odpowiadają polom AD:
 *   Work   = telephoneNumber
 *   Home   = ipPhone
 *   Mobile = mobile
 *
 * @param array<int, array{name:string, phones:array<int, array{number:string, type:string}>}> $entries
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

    $g1 = $dom->createElement('pbgroup');
    $g1->appendChild($dom->createElement('id', '1'));
    $g1->appendChild($dom->createElement('name', 'Blacklist'));
    $root->appendChild($g1);

    $g2 = $dom->createElement('pbgroup');
    $g2->appendChild($dom->createElement('id', '2'));
    $g2->appendChild($dom->createElement('name', 'Whitelist'));
    $root->appendChild($g2);

    $id = 1;
    foreach ($entries as $row) {
        $name   = trim((string)($row['name'] ?? ''));
        $phones = $row['phones'] ?? [];

        if ($name === '' || !is_array($phones) || !$phones) {
            continue;
        }

        // Usuń puste, zachowaj kolejność i typy, max 3
        $clean = [];
        foreach ($phones as $p) {
            $num  = trim((string)($p['number'] ?? ''));
            $type = trim((string)($p['type'] ?? 'Work'));
            if ($num === '') continue;
            $clean[] = ['number' => $num, 'type' => $type];
            if (count($clean) >= 3) break;
        }
        if (!$clean) continue;

        $contact = $dom->createElement('Contact');
        $contact->appendChild($dom->createElement('id', (string)$id));
        $contact->appendChild($dom->createElement('LastName', $name));
        $contact->appendChild($dom->createElement('Frequent', '0'));

        foreach ($clean as $p) {
            $phone = $dom->createElement('Phone');
            $phone->setAttribute('type', $p['type']);
            $phone->appendChild($dom->createElement('phonenumber', $p['number']));
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