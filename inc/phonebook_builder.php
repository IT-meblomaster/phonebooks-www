<?php
declare(strict_types=1);

/**
 * inc/phonebook_builder.php
 *
 * Wynik:
 * @return array<int, array{name:string, phones:array<int, array{number:string, type:string}>}>
 *
 * type: 'Work' = telephoneNumber, 'Home' = ipPhone, 'Mobile' = mobile
 */

function pb_db_connect(array $config): PDO
{
    $db = $config['db'] ?? null;
    if (!is_array($db)) {
        throw new RuntimeException('Invalid DB config');
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        (string)$db['host'],
        (int)$db['port'],
        (string)$db['name'],
        (string)$db['charset']
    );

    return new PDO($dsn, (string)$db['user'], (string)$db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function pb_normalize_name(string $name): string
{
    $map = [
        'Ą'=>'A','Ć'=>'C','Ę'=>'E','Ł'=>'L','Ń'=>'N','Ó'=>'O','Ś'=>'S','Ź'=>'Z','Ż'=>'Z',
        'ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z',
    ];

    $name = strtr($name, $map);
    $name = preg_replace('/\s+/', ' ', $name) ?? $name;
    return trim($name);
}

if (!function_exists('pb_swap_first_last_name')) {
    function pb_swap_first_last_name(string $name): string
    {
        $name = trim($name);
        if ($name === '') return '';

        if (strpos($name, ',') !== false) {
            $parts = array_map('trim', explode(',', $name, 2));
            $last  = $parts[0] ?? '';
            $first = $parts[1] ?? '';
            $out   = trim($last . ' ' . $first);
            return $out !== '' ? $out : $name;
        }

        $parts = preg_split('/\s+/', $name);
        if (!$parts || count($parts) < 2) return $name;

        $last  = array_pop($parts);
        $first = implode(' ', $parts);
        $out   = trim($last . ' ' . $first);
        return $out !== '' ? $out : $name;
    }
}

function pb_ad_connect_and_bind(array $adCfg)
{
    $host   = rtrim((string)($adCfg['host'] ?? ''), '/');
    $port   = (int)($adCfg['port'] ?? 389);
    $base   = (string)($adCfg['base_dn'] ?? '');
    $bindDn = (string)($adCfg['bind_dn'] ?? '');
    $bindPw = (string)($adCfg['bind_password'] ?? '');

    if ($host === '' || $base === '' || $bindDn === '') return null;

    $uri = $host;
    if (!preg_match('~^ldaps?://~i', $uri)) $uri = 'ldap://' . $uri;
    if (!preg_match('~:\d+$~', $uri))       $uri .= ':' . $port;

    $ldap = ldap_connect($uri);
    if (!$ldap) return null;

    ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);

    if (!@ldap_bind($ldap, $bindDn, $bindPw)) {
        ldap_unbind($ldap);
        return null;
    }

    return $ldap;
}

/**
 * AD: aktywni userzy z telephoneNumber, ipPhone i/lub mobile.
 *
 * Zwraca strukturę z typami:
 * [
 *   ['displayName' => 'Jan Kowalski', 'phones' => [
 *       ['number' => '201', 'type' => 'Work'],
 *       ['number' => '5001', 'type' => 'Home'],
 *       ['number' => '+48600123456', 'type' => 'Mobile'],
 *   ]],
 *   ...
 * ]
 */
function pb_ad_list_active_users_with_phones(array $adCfg): array
{
    $base = (string)($adCfg['base_dn'] ?? '');
    if ($base === '') return [];

    $ldap = pb_ad_connect_and_bind($adCfg);
    if (!$ldap) return [];

    $filter = "(&
      (objectCategory=person)
      (objectClass=user)
      (|
        (telephoneNumber=*)
        (ipPhone=*)
        (mobile=*)
      )
      (!(userAccountControl:1.2.840.113556.1.4.803:=2))
    )";

    $attrs = ['displayName', 'telephoneNumber', 'ipPhone', 'mobile'];

    $sr = @ldap_search($ldap, $base, $filter, $attrs);
    if (!$sr) {
        ldap_unbind($ldap);
        return [];
    }

    $entries = ldap_get_entries($ldap, $sr);
    ldap_unbind($ldap);

    $out   = [];
    $count = is_array($entries) ? (int)($entries['count'] ?? 0) : 0;

    for ($i = 0; $i < $count; $i++) {
        $e = $entries[$i];

        $name = trim((string)($e['displayname'][0] ?? ''));
        if ($name === '') continue;

        $phones = [];

        if (!empty($e['telephonenumber'][0])) {
            $phones[] = ['number' => trim((string)$e['telephonenumber'][0]), 'type' => 'Work'];
        }
        if (!empty($e['ipphone'][0])) {
            $phones[] = ['number' => trim((string)$e['ipphone'][0]), 'type' => 'Home'];
        }
        if (!empty($e['mobile'][0])) {
            $phones[] = ['number' => trim((string)$e['mobile'][0]), 'type' => 'Mobile'];
        }

        // usuń puste numery
        $phones = array_values(array_filter($phones, fn($p) => $p['number'] !== ''));
        if (!$phones) continue;

        $out[] = [
            'displayName' => $name,
            'phones'      => $phones,
        ];
    }

    return $out;
}

/**
 * Buduje listę kontaktów.
 *
 * Wynik: jeden wpis na kontakt.
 * phones: tablica ['number' => string, 'type' => string]
 * Dla numerów z DB (bez pola AD) typ jest zgadywany z długości numeru:
 *   <= 6 znaków  -> Work
 *   >= 11 znaków -> Mobile
 *   pozostałe    -> Home
 *
 * @return array<int, array{name:string, phones:array<int, array{number:string, type:string}>}>
 */
function pb_build_directory(array $config, PDO $pdo): array
{
    // number => ['number', 'name', 'phone_id', 'type']
    $results = [];
    $seen    = [];

    $adNamesByNumber = [];  // number => [names...]

    // 1) AD
    $adCfg   = $config['ad'] ?? null;
    $adUsers = is_array($adCfg) ? pb_ad_list_active_users_with_phones($adCfg) : [];

    foreach ($adUsers as $u) {
        $name = trim((string)($u['displayName'] ?? ''));
        if ($name === '') continue;

        $name   = pb_swap_first_last_name($name);
        $phones = $u['phones'] ?? [];
        if (!is_array($phones) || !$phones) continue;

        foreach ($phones as $p) {
            $num  = trim((string)($p['number'] ?? ''));
            $type = (string)($p['type'] ?? 'Work');
            if ($num === '') continue;

            $adNamesByNumber[$num][] = $name;

            if (!isset($results[$num])) {
                $results[$num] = [
                    'number'   => $num,
                    'name'     => '',
                    'phone_id' => null,
                    'type'     => $type,
                ];
                $seen[$num] = true;
            }
        }
    }

    // Uzupełnij nazwy (agregacja duplikatów)
    foreach ($adNamesByNumber as $num => $names) {
        $names = array_values(array_unique(array_filter(array_map('trim', $names), fn($x) => $x !== '')));
        if (!$names) continue;
        $results[$num]['name'] = implode(', ', $names);
    }

    // 2) DB phones
    $dbPhones = $pdo->query("SELECT id, number FROM phones")->fetchAll();
    foreach ($dbPhones as $p) {
        $pid = (int)($p['id'] ?? 0);
        $num = trim((string)($p['number'] ?? ''));
        if ($pid <= 0 || $num === '') continue;

        if (isset($seen[$num])) {
            $results[$num]['phone_id'] = $pid;
            continue;
        }

        // Zgaduj typ z długości numeru
        $len  = strlen(preg_replace('/\D/', '', $num));
        if ($len >= 11) {
            $type = 'Mobile';
        } elseif ($len <= 6) {
            $type = 'Work';
        } else {
            $type = 'Home';
        }

        $results[$num] = [
            'number'   => $num,
            'name'     => '',
            'phone_id' => $pid,
            'type'     => $type,
        ];
        $seen[$num] = true;
    }

    // 3) Overrides (etykiety) wygrywają
    $assignments = $pdo->query("
        SELECT
            p.id   AS phone_id,
            p.number,
            de.display_name AS label_name
        FROM phones p
        JOIN phone_assignments pa
            ON pa.phone_id = p.id AND pa.valid_to IS NULL
        JOIN directory_entries de
            ON de.id = pa.directory_entry_id
    ")->fetchAll();

    foreach ($assignments as $a) {
        $num = trim((string)($a['number'] ?? ''));
        if ($num === '') continue;

        if (!isset($results[$num])) {
            $len  = strlen(preg_replace('/\D/', '', $num));
            $type = $len >= 11 ? 'Mobile' : ($len <= 6 ? 'Work' : 'Home');
            $results[$num] = [
                'number'   => $num,
                'name'     => '',
                'phone_id' => (int)($a['phone_id'] ?? 0),
                'type'     => $type,
            ];
        }
        $results[$num]['name'] = trim((string)($a['label_name'] ?? ''));
    }

    // 4) Grupowanie po nazwie kontaktu
    // name => [ type => number ] (jeden numer per typ, Work/Home/Mobile)
    $byName = [];

    foreach ($results as $r) {
        $name = pb_normalize_name(trim((string)($r['name'] ?? '')));
        $num  = trim((string)($r['number'] ?? ''));
        $type = (string)($r['type'] ?? 'Work');
        if ($name === '' || $num === '') continue;

        if (!isset($byName[$name])) $byName[$name] = [];
        // Nie nadpisuj jeśli typ już zajęty
        if (!isset($byName[$name][$type])) {
            $byName[$name][$type] = $num;
        }
    }

    // Sortowanie nazw
    $names = array_keys($byName);
    sort($names, SORT_NATURAL);

    // Stała kolejność typów w wyjściu: Work → Home → Mobile
    $typeOrder = ['Work', 'Home', 'Mobile'];

    $out = [];
    foreach ($names as $name) {
        $phonesByType = $byName[$name];
        $phones = [];
        foreach ($typeOrder as $type) {
            if (isset($phonesByType[$type])) {
                $phones[] = ['number' => $phonesByType[$type], 'type' => $type];
            }
        }
        if (!$phones) continue;

        $out[] = [
            'name'   => $name,
            'phones' => $phones,
        ];
    }

    return $out;
}

function pb_has_domain_access(array $adCfg): bool
{
    $conn = pb_ad_connect_and_bind($adCfg);
    if ($conn === null) return false;
    @ldap_unbind($conn);
    return true;
}