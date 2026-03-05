<?php
declare(strict_types=1);

/**
 * lib/phonebook_builder.php
 *
 * Wynik:
 * @return array<int, array{name:string, phones:array<int,string>}>
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

/**
 * Pomocniczo: zamiana "Imię Nazwisko" -> "Nazwisko Imię"
 * (dla prostych przypadków; jeśli nie da się rozpoznać, zwraca oryginał)
 */
if (!function_exists('pb_swap_first_last_name')) {
    /**
     * Pomocniczo: zamiana "Imię Nazwisko" -> "Nazwisko Imię"
     * (dla prostych przypadków; jeśli nie da się rozpoznać, zwraca oryginał)
     */
    function pb_swap_first_last_name(string $name): string
    {
        $name = trim($name);
        if ($name === '') return '';

        // Jeśli ktoś ma format "Nazwisko, Imię" -> "Nazwisko Imię"
        if (strpos($name, ',') !== false) {
            $parts = array_map('trim', explode(',', $name, 2));
            $last = $parts[0] ?? '';
            $first = $parts[1] ?? '';
            $out = trim($last . ' ' . $first);
            return $out !== '' ? $out : $name;
        }

        // Format "Imię [Imię2 ...] Nazwisko" -> "Nazwisko Imię [Imię2 ...]"
        $parts = preg_split('/\s+/', $name);
        if (!$parts || count($parts) < 2) return $name;

        $last = array_pop($parts);
        $first = implode(' ', $parts);

        $out = trim($last . ' ' . $first);
        return $out !== '' ? $out : $name;
    }
}

function pb_ad_connect_and_bind(array $adCfg)
{
    $host = rtrim((string)($adCfg['host'] ?? ''), '/');
    $port = (int)($adCfg['port'] ?? 389);
    $base = (string)($adCfg['base_dn'] ?? '');
    $bindDn = (string)($adCfg['bind_dn'] ?? '');
    $bindPw = (string)($adCfg['bind_password'] ?? '');

    if ($host === '' || $base === '' || $bindDn === '') {
        return null;
    }

    $uri = $host;
    if (!preg_match('~^ldaps?://~i', $uri)) $uri = 'ldap://' . $uri;
    if (!preg_match('~:\d+$~', $uri)) $uri .= ':' . $port;

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
 * AD: aktywni userzy z telephoneNumber i/lub mobile.
 * Zwraca strukturę:
 * [
 *   ['displayName' => 'Jan Kowalski', 'phones' => ['201','555111222']],
 *   ...
 * ]
 */
function pb_ad_list_active_users_with_phones(array $adCfg): array
{
    $base = (string)($adCfg['base_dn'] ?? '');
    if ($base === '') return [];

    $ldap = pb_ad_connect_and_bind($adCfg);
    if (!$ldap) return [];

    // aktywni + mają telephoneNumber lub mobile
    $filter = "(&
      (objectCategory=person)
      (objectClass=user)
      (|
        (telephoneNumber=*)
        (mobile=*)
      )
      (!(userAccountControl:1.2.840.113556.1.4.803:=2))
    )";

    $attrs = ['displayName', 'telephoneNumber', 'mobile'];

    $sr = @ldap_search($ldap, $base, $filter, $attrs);
    if (!$sr) {
        ldap_unbind($ldap);
        return [];
    }

    $entries = ldap_get_entries($ldap, $sr);
    ldap_unbind($ldap);

    $out = [];
    $count = is_array($entries) ? (int)($entries['count'] ?? 0) : 0;

    for ($i = 0; $i < $count; $i++) {
        $e = $entries[$i];

        $name = trim((string)($e['displayname'][0] ?? ''));
        if ($name === '') continue;

        $phones = [];

        $tel = $e['telephonenumber'][0] ?? null;
        if ($tel !== null) $phones[] = trim((string)$tel);

        $mob = $e['mobile'][0] ?? null;
        if ($mob !== null) $phones[] = trim((string)$mob);

        // deduplikacja + usuń puste
        $phones = array_values(array_unique(array_filter($phones, fn($x) => $x !== '')));

        if (!$phones) continue;

        $out[] = [
            'displayName' => $name,
            'phones' => $phones,
        ];
    }

    return $out;
}

/**
 * Buduje listę kontaktów:
 * 1) AD (telephoneNumber + mobile) => jeśli duplikat numeru w AD, nazwa to lista osób
 * 2) DB phones tylko te, których nie było w AD
 * 3) DB override (etykieta) wygrywa
 * 4) WYNIK: jeden wpis = jeden numer (phones=[numer])
 *
 * @return array<int, array{name:string, phones:array<int,string>}>
 */
function pb_build_directory(array $config, PDO $pdo): array
{
    // results: number => ['number'=>string, 'name'=>string, 'phone_id'=>?int]
    $results = [];
    $seen = [];

    // do agregacji duplikatów w AD: number => [names...]
    $adNamesByNumber = [];

    // 1) AD
    $adCfg = $config['ad'] ?? null;
    $adUsers = is_array($adCfg) ? pb_ad_list_active_users_with_phones($adCfg) : [];

    foreach ($adUsers as $u) {
        $name = trim((string)($u['displayName'] ?? ''));
        if ($name === '') continue;

        // zamiana imię/nazwisko: nazwisko pierwsze
        $name = pb_swap_first_last_name($name);

        $phones = $u['phones'] ?? [];
        if (!is_array($phones) || !$phones) continue;

        foreach ($phones as $num) {
            $num = trim((string)$num);
            if ($num === '') continue;

            $adNamesByNumber[$num][] = $name;

            // trzymamy rekord po numerze (name uzupełnimy po zebraniu wszystkich)
            $results[$num] = [
                'number' => $num,
                'name' => '',        // uzupełnimy niżej
                'phone_id' => null,
            ];
            $seen[$num] = true;
        }
    }

    // po zebraniu wszystkich osób: number => "Nazwisko Imię, Nazwisko Imię, ..."
    foreach ($adNamesByNumber as $num => $names) {
        $names = array_values(array_unique(array_filter(array_map('trim', $names), fn($x) => $x !== '')));
        if (!$names) continue;
        $results[$num]['name'] = implode(', ', $names);
    }

    // 2) DB phones: dokładamy tylko numery nieobecne w AD + dopinamy phone_id dla tych z AD
    $dbPhones = $pdo->query("SELECT id, number FROM phones")->fetchAll();
    foreach ($dbPhones as $p) {
        $pid = (int)($p['id'] ?? 0);
        $num = trim((string)($p['number'] ?? ''));
        if ($pid <= 0 || $num === '') continue;

        if (isset($seen[$num])) {
            $results[$num]['phone_id'] = $pid;
            continue;
        }

        $results[$num] = [
            'number' => $num,
            'name' => '',
            'phone_id' => $pid,
        ];
        $seen[$num] = true;
    }

    // 3) Overrides (etykiety) wygrywają dla numerów z DB phones
    $assignments = $pdo->query("
      SELECT
        p.id AS phone_id,
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
            // teoretycznie nie powinno się zdarzyć, ale nie gubimy danych
            $results[$num] = [
                'number' => $num,
                'name' => '',
                'phone_id' => (int)($a['phone_id'] ?? 0),
            ];
        }

        // override wygrywa (bez zmian)
        $results[$num]['name'] = trim((string)($a['label_name'] ?? ''));
    }

    // sort po numerze
    ksort($results, SORT_NATURAL);

    // 4) WYNIK: grupujemy po nazwie kontaktu
    // - Yealink: 1 Unit na nazwę, Phone1..PhoneN
    // - Grandstream: 1 Contact na nazwę, wiele <Phone> w środku
    //
    // Filtr: tylko pełne rekordy (name + number). Częściowo puste pomijamy.

    $byName = []; // name => set(number=>true)

    foreach ($results as $r) {
        $name = pb_normalize_name(trim((string)($r['name'] ?? '')));
        $num  = trim((string)($r['number'] ?? ''));

        if ($name === '' || $num === '') continue;

        if (!isset($byName[$name])) $byName[$name] = [];
        $byName[$name][$num] = true; // deduplikacja numerów w obrębie kontaktu
    }

    // stabilne sortowanie nazw
    $names = array_keys($byName);
    sort($names, SORT_NATURAL);

    $out = [];
    foreach ($names as $name) {
        $phones = array_keys($byName[$name]);
        sort($phones, SORT_NATURAL);

        if (!$phones) continue;

        $out[] = [
            'name' => $name,
            'phones' => $phones,
        ];
    }

    return $out;
}