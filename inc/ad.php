<?php
declare(strict_types=1);

/**
 * inc/ad.php
 *
 * Zwraca aktywnych userów z AD, ale "spłaszczone":
 * - jeżeli user ma telephoneNumber i mobile -> zwracamy 2 rekordy
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

    // budujemy URI (ldap://host:389)
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
 * Pobiera aktywnych userów z AD, którzy mają telephoneNumber lub mobile.
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

    $attrs = ['dn', 'sAMAccountName', 'displayName', 'telephoneNumber', 'mobile'];

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

        // bierzemy max 1 z telephoneNumber i max 1 z mobile (wg Twojego założenia)
        $nums = [];

        if (!empty($e['telephonenumber'][0])) {
            $nums[] = trim((string)$e['telephonenumber'][0]);
        }
        if (!empty($e['mobile'][0])) {
            $nums[] = trim((string)$e['mobile'][0]);
        }

        // deduplikacja + usuń puste
        $nums = array_values(array_unique(array_filter($nums, fn($x) => $x !== '')));

        foreach ($nums as $num) {
            $out[] = [
                'number' => $num,
                'displayName' => $displayName,
                'sAMAccountName' => $sam,
                'dn' => $dn,
            ];
        }
    }

    return $out;
}