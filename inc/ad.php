<?php
declare(strict_types=1);

/**
 * inc/ad.php
 *
 * Zwraca aktywnych userów z AD, ale "spłaszczone":
 * - jeżeli user ma telephoneNumber, ipPhone i/lub mobile -> zwracamy osobny rekord na każdy numer
 * - każdy rekord ma pole 'number' (to jest numer telefonu)
 *
 * Każdy rekord:
 * [
 *   'number' => '203',
 *   'displayName' => 'Adam Adamczyk',
 *   'sAMAccountName' => 'aadamczyk',
 *   'dn' => 'CN=...'
 * ]
 */

function ad_connect(array $cfg)
{
    $host = rtrim((string)($cfg['host'] ?? ''), '/');
    $port = (int)($cfg['port'] ?? 389);

    if ($host === '') {
        return null;
    }

    $uri = $host;
    if (!preg_match('~^ldaps?://~i', $uri)) {
        $uri = 'ldap://' . $uri;
    }
    if (!preg_match('~:\d+$~', $uri)) {
        $uri .= ':' . $port;
    }

    $ldap = ldap_connect($uri);
    if (!$ldap) {
        return null;
    }

    ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);

    return $ldap;
}

function ad_bind($ldap, array $cfg): bool
{
    $bindDn = (string)($cfg['bind_dn'] ?? '');
    $bindPw = (string)($cfg['bind_password'] ?? '');

    if ($bindDn === '') {
        return false;
    }

    return @ldap_bind($ldap, $bindDn, $bindPw);
}

/**
 * Pobiera aktywnych userów z AD, którzy mają telephoneNumber, ipPhone lub mobile.
 * Kolejność numerów na rekord: telephoneNumber → ipPhone → mobile.
 * Pomija zablokowanych (ACCOUNTDISABLE).
 *
 * @return array<int, array{number:string, displayName:string, sAMAccountName:string, dn:string}>
 */
function ad_list_active_users_with_phones(array $cfg): array
{
    if (!function_exists('ldap_connect')) {
        return [];
    }

    $baseDn = (string)($cfg['base_dn'] ?? '');
    if ($baseDn === '') {
        return [];
    }

    $ldap = ad_connect($cfg);
    if (!$ldap) {
        return [];
    }

    if (!ad_bind($ldap, $cfg)) {
        ldap_unbind($ldap);
        return [];
    }

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

    $attrs = ['dn', 'sAMAccountName', 'displayName', 'telephoneNumber', 'ipPhone', 'mobile'];

    $sr = @ldap_search($ldap, $baseDn, $filter, $attrs);
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

        $displayName = (string)($e['displayname'][0] ?? '');
        $sam = (string)($e['samaccountname'][0] ?? '');
        $dn = (string)($e['dn'] ?? '');

        // Stała kolejność: telephoneNumber → ipPhone → mobile
        $nums = [];
        if (!empty($e['telephonenumber'][0])) {
            $nums[] = trim((string)$e['telephonenumber'][0]);
        }
        if (!empty($e['ipphone'][0])) {
            $nums[] = trim((string)$e['ipphone'][0]);
        }
        if (!empty($e['mobile'][0])) {
            $nums[] = trim((string)$e['mobile'][0]);
        }

        // deduplikacja + usuń puste
        $nums = array_values(array_unique(array_filter($nums, fn($x) => $x !== '')));

        foreach ($nums as $num) {
            $out[] = [
                'number'         => $num,
                'displayName'    => $displayName,
                'sAMAccountName' => $sam,
                'dn'             => $dn,
            ];
        }
    }

    return $out;
}

/**
 * Rozbija displayName na imię i nazwisko.
 * Obsługuje:
 * - "Nazwisko, Imię"
 * - "Imię Nazwisko"
 */
function ad_split_first_last_name(string $displayName): array
{
    $displayName = trim($displayName);
    if ($displayName === '') {
        return ['first_name' => '', 'last_name' => ''];
    }

    if (strpos($displayName, ',') !== false) {
        $parts = array_map('trim', explode(',', $displayName, 2));
        return [
            'first_name' => $parts[1] ?? '',
            'last_name'  => $parts[0] ?? '',
        ];
    }

    $parts = preg_split('/\s+/', $displayName) ?: [];
    if (count($parts) < 2) {
        return [
            'first_name' => '',
            'last_name'  => $displayName,
        ];
    }

    $lastName = array_pop($parts);
    $firstName = implode(' ', $parts);

    return [
        'first_name' => trim($firstName),
        'last_name'  => trim($lastName),
    ];
}

function ad_list_active_users_with_contact_data(array $cfg): array
{
    if (!function_exists('ldap_connect')) {
        return [];
    }

    $baseDn = (string)($cfg['base_dn'] ?? '');
    if ($baseDn === '') {
        return [];
    }

    $ldap = ad_connect($cfg);
    if (!$ldap) {
        return [];
    }

    if (!ad_bind($ldap, $cfg)) {
        ldap_unbind($ldap);
        return [];
    }

    $filter = "(&
      (objectCategory=person)
      (objectClass=user)
      (!(userAccountControl:1.2.840.113556.1.4.803:=2))
      (|
        (telephoneNumber=*)
        (ipPhone=*)
        (mobile=*)
        (mail=*)
      )
    )";

    $attrs = [
        'displayName',
        'telephoneNumber',
        'ipPhone',
        'mobile',
        'mail',
        'physicaldeliveryofficename',
    ];

    $sr = @ldap_search($ldap, $baseDn, $filter, $attrs);
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

        $displayName = trim((string)($e['displayname'][0] ?? ''));
        if ($displayName === '') {
            continue;
        }

        $name = ad_split_first_last_name($displayName);

        // Stała kolejność: telephoneNumber → ipPhone → mobile
        $phones = [];
        if (!empty($e['telephonenumber'][0])) {
            $phones[] = trim((string)$e['telephonenumber'][0]);
        }
        if (!empty($e['ipphone'][0])) {
            $phones[] = trim((string)$e['ipphone'][0]);
        }
        if (!empty($e['mobile'][0])) {
            $phones[] = trim((string)$e['mobile'][0]);
        }
        $phones = array_values(array_unique(array_filter($phones, fn($x) => $x !== '')));

        $emails = [];
        if (!empty($e['mail'][0])) {
            $emails[] = trim((string)$e['mail'][0]);
        }
        $emails = array_values(array_unique(array_filter($emails, fn($x) => $x !== '')));

        if (!$phones && !$emails) {
            continue;
        }

        $out[] = [
            'displayName' => $displayName,
            'first_name'  => $name['first_name'],
            'last_name'   => $name['last_name'],
            'department'  => trim((string)($e['physicaldeliveryofficename'][0] ?? '')),
            'emails'      => $emails,
            'phones'      => $phones,
        ];
    }

    return $out;
}