<?php
require_login($config);

require_once __DIR__ . '/../inc/ad.php';
require_once __DIR__ . '/../vendor/autoload.php';

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

if (!function_exists('e')) {
    function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * number => [label1, label2, ...]
 */
function list_pdf_get_active_labels_by_number(PDO $pdo): array
{
    $rows = $pdo->query("
      SELECT
        p.number,
        de.display_name AS label_name
      FROM phones p
      JOIN phone_assignments pa
        ON pa.phone_id = p.id
       AND pa.valid_to IS NULL
      JOIN directory_entries de
        ON de.id = pa.directory_entry_id
      ORDER BY p.number ASC, de.display_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $row) {
        $number = trim((string)($row['number'] ?? ''));
        $label  = trim((string)($row['label_name'] ?? ''));

        if ($number === '') {
            continue;
        }

        if (!isset($out[$number])) {
            $out[$number] = [];
        }

        if ($label !== '' && !in_array($label, $out[$number], true)) {
            $out[$number][] = $label;
        }
    }

    return $out;
}

/**
 * Usuwa duplikaty wierszy osoby.
 *
 * Zasady:
 * - identyczny label+phone+email => zostaje 1 rekord
 * - jeśli są dwa rekordy z tym samym label+phone, a tylko jeden ma email,
 *   to zostaje ten z emailem
 */
function list_pdf_normalize_person_rows(array $rows): array
{
    $exact = [];
    foreach ($rows as $row) {
        $label = trim((string)($row['label'] ?? ''));
        $phone = trim((string)($row['phone'] ?? ''));
        $email = trim((string)($row['email'] ?? ''));

        $key = mb_strtolower($label . '|' . $phone . '|' . $email);
        $exact[$key] = [
            'label' => $label,
            'phone' => $phone,
            'email' => $email,
        ];
    }

    $byLabelPhone = [];
    foreach ($exact as $row) {
        $label = trim((string)($row['label'] ?? ''));
        $phone = trim((string)($row['phone'] ?? ''));
        $email = trim((string)($row['email'] ?? ''));

        $key = mb_strtolower($label . '|' . $phone);

        if (!isset($byLabelPhone[$key])) {
            $byLabelPhone[$key] = [
                'label' => $label,
                'phone' => $phone,
                'email' => $email,
            ];
            continue;
        }

        $existingEmail = trim((string)($byLabelPhone[$key]['email'] ?? ''));

        if ($existingEmail === '' && $email !== '') {
            $byLabelPhone[$key] = [
                'label' => $label,
                'phone' => $phone,
                'email' => $email,
            ];
        }
    }

    return array_values($byLabelPhone);
}

/**
 * Dopina osobę do listy wynikowej.
 * Jeśli ta sama osoba już istnieje, scala jej wiersze.
 */
function list_pdf_append_or_merge_person(array &$people, array $person): void
{
    $personKey = mb_strtolower(trim(
        ($person['last_name'] ?? '') . '|' .
        ($person['first_name'] ?? '') . '|' .
        ($person['department'] ?? '')
    ));

    foreach ($people as $idx => $existing) {
        $existingKey = mb_strtolower(trim(
            ($existing['last_name'] ?? '') . '|' .
            ($existing['first_name'] ?? '') . '|' .
            ($existing['department'] ?? '')
        ));

        if ($existingKey !== $personKey) {
            continue;
        }

        $mergedRows = array_merge(
            $existing['rows'] ?? [],
            $person['rows'] ?? []
        );

        $people[$idx]['rows'] = list_pdf_normalize_person_rows($mergedRows);
        return;
    }

    $person['rows'] = list_pdf_normalize_person_rows($person['rows'] ?? []);
    $people[] = $person;
}

/**
 * Buduje dane do PDF.
 *
 * Zasady:
 * - część 1: osoby z imieniem/nazwiskiem z AD
 * - część 2: na końcu wpisy bez imienia/nazwiska, ale z etykietą i numerem
 * - jedna lista
 * - nie łączymy rekordów tylko dlatego, że numer telefonu jest taki sam
 */
function list_pdf_build_teleaddress_book(array $config, PDO $pdo): array
{
    $adCfg = $config['ad'] ?? null;
    $adUsers = is_array($adCfg) ? ad_list_active_users_with_contact_data($adCfg) : [];
    $labelsByNumber = list_pdf_get_active_labels_by_number($pdo);

    $people = [];
    $numbersUsedByNamedPeople = [];

    foreach ($adUsers as $u) {
        $lastName   = trim((string)($u['last_name'] ?? ''));
        $firstName  = trim((string)($u['first_name'] ?? ''));
        $department = trim((string)($u['department'] ?? ''));

        $phones = $u['phones'] ?? [];
        $emails = $u['emails'] ?? [];

        if (!is_array($phones)) {
            $phones = [];
        }
        if (!is_array($emails)) {
            $emails = [];
        }

        $phones = array_values(array_unique(array_filter(array_map('trim', $phones), fn($x) => $x !== '')));
        $emails = array_values(array_unique(array_filter(array_map('trim', $emails), fn($x) => $x !== '')));

        if ($lastName === '' && $firstName === '') {
            continue;
        }

        if (!$phones && !$emails) {
            continue;
        }

        $rows = [];
        $max = max(count($phones), count($emails), 1);

        for ($i = 0; $i < $max; $i++) {
            $phone = $phones[$i] ?? '';
            $email = $emails[$i] ?? '';

            $labels = [];
            if ($phone !== '') {
                $numbersUsedByNamedPeople[$phone] = true;
                $labels = $labelsByNumber[$phone] ?? [];
            }

            if (!$labels) {
                $labels = [''];
            }

            foreach ($labels as $labelIndex => $label) {
                $rows[] = [
                    'label' => trim((string)$label),
                    'phone' => $labelIndex === 0 ? $phone : '',
                    'email' => $labelIndex === 0 ? $email : '',
                ];
            }
        }

        if (!$rows) {
            $rows[] = [
                'label' => '',
                'phone' => '',
                'email' => '',
            ];
        }

        list_pdf_append_or_merge_person($people, [
            'last_name'  => $lastName,
            'first_name' => $firstName,
            'department' => $department,
            'rows'       => $rows,
        ]);
    }

    $orphansRaw = $pdo->query("
      SELECT
        p.number,
        de.display_name AS label_name
      FROM phones p
      JOIN phone_assignments pa
        ON pa.phone_id = p.id
       AND pa.valid_to IS NULL
      JOIN directory_entries de
        ON de.id = pa.directory_entry_id
      ORDER BY de.display_name ASC, p.number ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $orphans = [];
    foreach ($orphansRaw as $row) {
        $number = trim((string)($row['number'] ?? ''));
        $label  = trim((string)($row['label_name'] ?? ''));

        if ($number === '' || $label === '') {
            continue;
        }

        if (isset($numbersUsedByNamedPeople[$number])) {
            continue;
        }

        $orphans[] = [
            'last_name'  => '',
            'first_name' => '',
            'department' => '',
            'rows'       => [[
                'label' => $label,
                'phone' => $number,
                'email' => '',
            ]],
        ];
    }

    usort($people, static function (array $a, array $b): int {
        $ka = mb_strtolower(trim(($a['last_name'] ?? '') . ' ' . ($a['first_name'] ?? '')));
        $kb = mb_strtolower(trim(($b['last_name'] ?? '') . ' ' . ($b['first_name'] ?? '')));
        return strnatcmp($ka, $kb);
    });

    usort($orphans, static function (array $a, array $b): int {
        $la = mb_strtolower((string)($a['rows'][0]['label'] ?? ''));
        $lb = mb_strtolower((string)($b['rows'][0]['label'] ?? ''));
        $cmp = strnatcmp($la, $lb);
        if ($cmp !== 0) {
            return $cmp;
        }

        $na = mb_strtolower((string)($a['rows'][0]['phone'] ?? ''));
        $nb = mb_strtolower((string)($b['rows'][0]['phone'] ?? ''));
        return strnatcmp($na, $nb);
    });

    return array_merge($people, $orphans);
}

/**
 * Generuje HTML tabeli do PDF.
 */
function list_pdf_render_html(array $rows): string
{
    ob_start();
    ?>
    <!doctype html>
    <html lang="pl">
    <head>
        <meta charset="utf-8">
        <style>
            body {
                font-family: sans-serif;
                font-size: 10pt;
            }

            h1 {
                font-size: 16pt;
                margin: 0 0 12px 0;
            }

            .meta {
                margin-bottom: 10px;
                font-size: 9pt;
                color: #555;
            }

            table {
                width: 100%;
                border-collapse: collapse;
                table-layout: fixed;
            }

            th, td {
                border: 1px solid #666;
                padding: 5px 6px;
                vertical-align: top;
                word-wrap: break-word;
            }

            th {
                font-weight: bold;
                text-align: left;
            }

            .col-first-name { width: 14%; }
            .col-last-name  { width: 18%; }
            .col-label      { width: 20%; }
            .col-phone      { width: 14%; }
            .col-email      { width: 22%; }
            .col-department { width: 12%; }
        </style>
    </head>
    <body>
        <h1>Książka teleadresowa</h1>
        <div class="meta">
            Wygenerowano: <?= e(date('Y-m-d H:i:s')) ?>
        </div>

        <table autosize="1">
            <thead>
                <tr>
                    <th class="col-first-name">Imię</th>
                    <th class="col-last-name">Nazwisko</th>
                    <th class="col-label">Etykieta</th>
                    <th class="col-phone">Numer telefonu</th>
                    <th class="col-email">Email</th>
                    <th class="col-department">Dział</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="6">Brak danych do wyświetlenia.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $person): ?>
                        <?php
                        $subrows = $person['rows'] ?? [];
                        $rowspan = max(count($subrows), 1);
                        ?>
                        <?php foreach ($subrows as $i => $row): ?>
                            <tr>
                                <?php if ($i === 0): ?>
                                    <td rowspan="<?= (int)$rowspan ?>"><?= e((string)($person['first_name'] ?? '')) ?></td>
                                    <td rowspan="<?= (int)$rowspan ?>"><?= e((string)($person['last_name'] ?? '')) ?></td>
                                <?php endif; ?>


                                <?php
                                $label = trim((string)($row['label'] ?? ''));
                                if ($label === '') {
                                    $label = trim(
                                        (string)($person['last_name'] ?? '') . ' ' . (string)($person['first_name'] ?? '')
                                    );
                                }
                                ?>
                                <td><?= e($label) ?></td>
                                <td><?= e((string)($row['phone'] ?? '')) ?></td>
                                <td><?= e((string)($row['email'] ?? '')) ?></td>

                                <?php if ($i === 0): ?>
                                    <td rowspan="<?= (int)$rowspan ?>"><?= e((string)($person['department'] ?? '')) ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </body>
    </html>
    <?php
    return (string)ob_get_clean();
}

$rows = list_pdf_build_teleaddress_book($config, $pdo);
$html = list_pdf_render_html($rows);

$mpdf = new \Mpdf\Mpdf([
    'mode' => 'utf-8',
    'format' => 'A4-L',
    'margin_left' => 8,
    'margin_right' => 8,
    'margin_top' => 8,
    'margin_bottom' => 8,
    'margin_header' => 4,
    'margin_footer' => 4,
]);

$mpdf->SetTitle('Ksiazka teleadresowa');
$mpdf->WriteHTML($html);
$mpdf->Output('ksiazka-teleadresowa.pdf', \Mpdf\Output\Destination::INLINE);
exit;