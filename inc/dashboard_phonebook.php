<?php
declare(strict_types=1);

function dashboard_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function dashboard_ldap_escape_filter(string $value): string
{
    if (function_exists('ldap_escape')) {
        return ldap_escape($value, '', LDAP_ESCAPE_FILTER);
    }

    return str_replace(
        ["\\", '*', '(', ')', "\0"],
        ['\\5c', '\\2a', '\\28', '\\29', '\\00'],
        $value
    );
}

function dashboard_norm_name_for_file(string $s): string
{
    $s = trim($s);
    $s = preg_replace('/\s+/u', ' ', $s) ?: $s;
    return $s;
}

function dashboard_photo_filename(string $sn, string $given): string
{
    $sn = dashboard_norm_name_for_file($sn);
    $given = dashboard_norm_name_for_file($given);
    return trim($sn . ' ' . $given) . '.jpg';
}

function dashboard_sort_value(array $row, string $key): string
{
    $v = (string)($row[$key] ?? '');
    $v = mb_strtolower($v, 'UTF-8');
    return trim($v);
}

function dashboard_build_query(array $base, array $add): string
{
    return http_build_query(array_merge($base, $add));
}

function dashboard_next_dir(string $currentCol, string $sort, string $dir): string
{
    if ($currentCol !== $sort) {
        return 'asc';
    }

    return $dir === 'asc' ? 'desc' : 'asc';
}

function dashboard_sort_indicator(string $currentCol, string $sort, string $dir): string
{
    if ($currentCol !== $sort) {
        return '';
    }

    return $dir === 'asc' ? '▲' : '▼';
}

function dashboard_phonebook_resolve_ldap_config(array $config): array
{
    if (isset($config['ad']) && is_array($config['ad'])) {
        return $config['ad'];
    }

    if (isset($config['ldap']) && is_array($config['ldap'])) {
        return $config['ldap'];
    }

    return $config;
}

/**
 * Normalizacja znaków (taka sama jak w phonebook_builder.php → pb_normalize_name)
 */
function dashboard_normalize_name(string $name): string
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
 * Zamiana "Imię Nazwisko" -> "Nazwisko Imię"
 * (taka sama logika jak pb_swap_first_last_name w phonebook_builder.php)
 */
function dashboard_swap_first_last_name(string $name): string
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

/**
 * Wczytuje aktywne overrides (etykiety) z bazy.
 * Zwraca: number => label_name
 */
function dashboard_load_override_labels_by_number(PDO $pdo): array
{
    $dbPhones = $pdo->query("
        SELECT id, number
        FROM phones
    ")->fetchAll(PDO::FETCH_ASSOC);

    $dbPhoneIdByNumber = [];
    foreach ($dbPhones as $p) {
        $num = trim((string)($p['number'] ?? ''));
        $pid = (int)($p['id'] ?? 0);

        if ($num !== '' && $pid > 0) {
            $dbPhoneIdByNumber[$num] = $pid;
        }
    }

    $assignments = $pdo->query("
        SELECT
            p.id AS phone_id,
            p.number,
            pa.id AS assignment_id,
            de.display_name AS label_name
        FROM phones p
        JOIN phone_assignments pa
            ON pa.phone_id = p.id
           AND pa.valid_to IS NULL
        JOIN directory_entries de
            ON de.id = pa.directory_entry_id
    ")->fetchAll(PDO::FETCH_ASSOC);

    $assignByPhoneId = [];
    foreach ($assignments as $a) {
        $pid = (int)($a['phone_id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }

        $assignByPhoneId[$pid] = [
            'assignment_id' => (int)($a['assignment_id'] ?? 0),
            'label_name'    => trim((string)($a['label_name'] ?? '')),
            'number'        => trim((string)($a['number'] ?? '')),
        ];
    }

    $labelsByNumber = [];
    foreach ($dbPhoneIdByNumber as $num => $pid) {
        if ($pid > 0 && isset($assignByPhoneId[$pid])) {
            $label = trim((string)($assignByPhoneId[$pid]['label_name'] ?? ''));
            if ($label !== '') {
                $labelsByNumber[$num] = $label;
            }
        }
    }

    return $labelsByNumber;
}

/**
 * Główna funkcja ładująca dane dla dashboardu.
 *
 * Spójność z phonebook_builder.php / pb_build_directory():
 *  - pobiera z AD: telephoneNumber, ipPhone ORAZ mobile
 *  - agreguje duplikaty: ten sam numer u wielu osób → "Nazwisko Imię, Nazwisko Imię"
 *  - override z DB wygrywa nad nazwą z AD
 *  - kolumna 'phones' zawiera WSZYSTKIE numery danej osoby (telephoneNumber, ipPhone, mobile)
 *  - kolumna 'label' to finalna nazwa wyświetlana (taka sama jak w XML)
 *
 * Dane AD są pobierane przez LDAP (bezpośrednio), nie przez ad_list_active_users_with_phones,
 * żeby mieć dostęp do wszystkich kolumn tabeli (mail, office itp.).
 */
function dashboard_phonebook_load(array $config, PDO $pdo): array
{
    $ldapCfg       = dashboard_phonebook_resolve_ldap_config($config);
    $labelsByNumber = dashboard_load_override_labels_by_number($pdo);
    $totalCount    = 0;

    // Kolumny tabeli (klucze = nazwy atrybutów LDAP lowercase)
    $columns = [
        'label',
        'givenname',
        'sn',
        'mail',
        'phones',                       // telephoneNumber + ipPhone + mobile (w tej kolejności)
        'physicaldeliveryofficename',
    ];

    $columnLabels = [
        'label'                       => 'Nazwa wyświetlana',
        'givenname'                   => 'Imię',
        'sn'                          => 'Nazwisko',
        'mail'                        => 'Email',
        'phones'                      => 'Telefony',
        'physicaldeliveryofficename'  => 'Biuro',
    ];

    $photoDir = '/mnt/photos';

    $officeFilterRaw = isset($_GET['office']) ? trim((string)$_GET['office']) : '';
    $officeFilter    = $officeFilterRaw !== '' ? $officeFilterRaw : null;

    $sort = isset($_GET['sort']) ? strtolower(trim((string)$_GET['sort'])) : 'sn';
    $dir  = isset($_GET['dir'])  ? strtolower(trim((string)$_GET['dir']))  : 'asc';

    if (!in_array($sort, $columns, true)) {
        $sort = 'sn';
    }
    if (!in_array($dir, ['asc', 'desc'], true)) {
        $dir = 'asc';
    }

    $useSsl       = !empty($ldapCfg['use_ssl']);
    $host         = (string)($ldapCfg['host'] ?? '');
    $port         = (int)($ldapCfg['port'] ?? 389);
    $bindDn       = (string)($ldapCfg['bind_dn'] ?? '');
    $bindPassword = (string)($ldapCfg['bind_password'] ?? '');
    $baseDn       = (string)($ldapCfg['base_dn'] ?? '');

    if ($host !== '' && preg_match('~^ldaps?://~i', $host)) {
        $ldapUrl = $host;
        if (!preg_match('~:\d+$~', $ldapUrl)) {
            $ldapUrl .= ':' . $port;
        }
    } else {
        $ldapUrl = ($useSsl ? 'ldaps://' : 'ldap://') . $host . ':' . $port;
    }

    $errors = [];
    $rows   = [];

    if ($host === '' || $bindDn === '' || $baseDn === '') {
        $errors[] = 'Brak kompletnej konfiguracji LDAP.';
    } else {
        $conn = @ldap_connect($ldapUrl);

        if (!$conn) {
            $errors[] = "Nie udało się połączyć z LDAP ({$ldapUrl}).";
        } else {
            ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);

            if (!empty($ldapCfg['use_starttls'])) {
                if (!@ldap_start_tls($conn)) {
                    $errors[] = 'Nie udało się zestawić StartTLS.';
                }
            }

            if (!$errors && !@ldap_bind($conn, $bindDn, $bindPassword)) {
                $errors[] = 'Błąd bind do LDAP.';
            }

            if (!$errors) {
                // Filtr spójny z pb_ad_list_active_users_with_phones():
                // aktywni + mają telephoneNumber, ipPhone lub mobile
                $baseFilter =
                    "(&" .
                        "(objectCategory=person)" .
                        "(objectClass=user)" .
                        "(!(userAccountControl:1.2.840.113556.1.4.803:=2))" .
                        "(|" .
                            "(telephoneNumber=*)" .
                            "(ipPhone=*)" .
                            "(mobile=*)" .
                        ")" .
                    ")";

                if ($officeFilter !== null) {
                    $escapedOffice = dashboard_ldap_escape_filter($officeFilter);
                    $filter = "(&{$baseFilter}(physicalDeliveryOfficeName={$escapedOffice}))";
                } else {
                    $filter = $baseFilter;
                }

                // Policz wszystkich (bez filtra office)
                $totalSearch = @ldap_search($conn, $baseDn, $baseFilter, ['dn'], 0, 0);
                if ($totalSearch) {
                    $totalEntries = ldap_get_entries($conn, $totalSearch);
                    $totalCount   = (int)($totalEntries['count'] ?? 0);
                }

                // Atrybuty do pobrania z LDAP
                // 'phones' to kolumna wirtualna — pobieramy telephoneNumber + ipPhone + mobile
                $ldapAttrs = [
                    'dn',
                    'givenname',
                    'sn',
                    'mail',
                    'telephoneNumber',
                    'ipPhone',
                    'mobile',
                    'physicalDeliveryOfficeName',
                ];

                $search = @ldap_search($conn, $baseDn, $filter, $ldapAttrs, 0, 0);

                if (!$search) {
                    $errors[] = 'Błąd wyszukiwania LDAP.';
                } else {
                    $entries = ldap_get_entries($conn, $search);

                    // Krok 1: zbierz wszystkich użytkowników z AD z ich numerami
                    // (tak samo jak pb_ad_list_active_users_with_phones + pb_build_directory)
                    // adUsers: number => [row_data_from_ldap...]
                    // adNamesByNumber: number => [names...]

                    $adNamesByNumber = [];  // number => ['Nazwisko Imię', ...]
                    $adRowByNumber   = [];  // number => row (pierwszy napotkany dla tego numeru)

                    $count = isset($entries['count']) ? (int)$entries['count'] : 0;

                    for ($i = 0; $i < $count; $i++) {
                        $e = $entries[$i];

                        // Pomocnicza funkcja: wyciągnij wartość tekstową z wpisu LDAP
                        $getVal = function (string $attr) use ($e): string {
                            $k = strtolower($attr);
                            if (!isset($e[$k]) || !is_array($e[$k]) || !isset($e[$k]['count'])) {
                                return '';
                            }
                            $vals = [];
                            for ($j = 0; $j < $e[$k]['count']; $j++) {
                                $v = $e[$k][$j] ?? null;
                                if (!is_string($v)) continue;
                                $isUtf8 = mb_check_encoding($v, 'UTF-8')
                                    && !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $v);
                                $vals[] = $isUtf8 ? $v : 'base64:' . base64_encode($v);
                            }
                            return implode(' | ', $vals);
                        };

                        $sn    = trim($getVal('sn'));
                        $given = trim($getVal('givenname'));
                        $mail  = trim($getVal('mail'));
                        $office = trim($getVal('physicalDeliveryOfficeName'));
                        $dn    = trim((string)($e['dn'] ?? ''));

                        // displayName AD (sn + givenname → zamiana kolejności jak w XML)
                        $displayName = '';
                        if ($sn !== '' && $given !== '') {
                            $displayName = dashboard_swap_first_last_name($given . ' ' . $sn);
                        } elseif ($sn !== '') {
                            $displayName = $sn;
                        } elseif ($given !== '') {
                            $displayName = $given;
                        }

                        // Numery: telephoneNumber → ipPhone → mobile (stała kolejność, deduplikacja)
                        $tel = trim($getVal('telephoneNumber'));
                        $ip  = trim($getVal('ipPhone'));
                        $mob = trim($getVal('mobile'));

                        $phoneList = [];
                        if ($tel !== '') $phoneList[] = $tel;
                        if ($ip  !== '') $phoneList[] = $ip;
                        if ($mob !== '') $phoneList[] = $mob;
                        $phoneList = array_values(array_unique($phoneList));

                        if (!$phoneList) continue; // brak numerów → pomijamy

                        foreach ($phoneList as $num) {
                            $num = trim($num);
                            if ($num === '') continue;

                            $adNamesByNumber[$num][] = $displayName;

                            // Zachowujemy dane LDAP dla tego numeru (tylko raz — pierwsze wystąpienie)
                            if (!isset($adRowByNumber[$num])) {
                                $adRowByNumber[$num] = [
                                    'dn'     => $dn,
                                    'sn'     => $sn,
                                    'givenname' => $given,
                                    'mail'   => $mail,
                                    'physicaldeliveryofficename' => $office,
                                    '_all_phones_of_user' => $phoneList,
                                ];
                            }
                        }
                    }

                    // Krok 2: zbuduj wiersze tabeli — jeden wiersz na użytkownika
                    // (grupujemy po "sn+givenname", nie po numerze, żeby nie dublować wierszy)
                    // Kluczem jest para sn|givenname (jak w XML: jeden kontakt = jedna osoba)

                    $userRows = []; // "sn|givenname" => row

                    for ($i = 0; $i < $count; $i++) {
                        $e = $entries[$i];

                        $getVal = function (string $attr) use ($e): string {
                            $k = strtolower($attr);
                            if (!isset($e[$k]) || !is_array($e[$k]) || !isset($e[$k]['count'])) {
                                return '';
                            }
                            $vals = [];
                            for ($j = 0; $j < $e[$k]['count']; $j++) {
                                $v = $e[$k][$j] ?? null;
                                if (!is_string($v)) continue;
                                $isUtf8 = mb_check_encoding($v, 'UTF-8')
                                    && !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $v);
                                $vals[] = $isUtf8 ? $v : 'base64:' . base64_encode($v);
                            }
                            return implode(' | ', $vals);
                        };

                        $sn     = trim($getVal('sn'));
                        $given  = trim($getVal('givenname'));
                        $mail   = trim($getVal('mail'));
                        $office = trim($getVal('physicalDeliveryOfficeName'));
                        $dn     = trim((string)($e['dn'] ?? ''));

                        $tel = trim($getVal('telephoneNumber'));
                        $ip  = trim($getVal('ipPhone'));
                        $mob = trim($getVal('mobile'));

                        $phoneList = [];
                        if ($tel !== '') $phoneList[] = $tel;
                        if ($ip  !== '') $phoneList[] = $ip;
                        if ($mob !== '') $phoneList[] = $mob;
                        $phoneList = array_values(array_unique($phoneList));

                        if (!$phoneList) continue;

                        // Klucz identyfikujący osobę
                        $userKey = $sn . '|' . $given . '|' . $dn;

                        if (isset($userRows[$userKey])) {
                            // Dołącz nowe numery (gdyby były pobrane z innego wpisu)
                            foreach ($phoneList as $num) {
                                if (!in_array($num, $userRows[$userKey]['_phones'], true)) {
                                    $userRows[$userKey]['_phones'][] = $num;
                                }
                            }
                            continue;
                        }

                        // Ustal label (nazwa wyświetlana) — spójnie z pb_build_directory:
                        // sprawdzamy override dla każdego z numerów
                        $overrideLabel = '';
                        foreach ($phoneList as $num) {
                            if (isset($labelsByNumber[$num]) && $labelsByNumber[$num] !== '') {
                                $overrideLabel = $labelsByNumber[$num];
                                break; // pierwszy znaleziony override wygrywa
                            }
                        }

                        // Jeśli brak override: zbuduj nazwę tak jak pb_build_directory
                        // ("Nazwisko Imię" po normalizacji)
                        if ($overrideLabel !== '') {
                            $label = $overrideLabel;
                        } else {
                            $displayName = '';
                            if ($sn !== '' && $given !== '') {
                                $displayName = dashboard_swap_first_last_name($given . ' ' . $sn);
                            } elseif ($sn !== '') {
                                $displayName = $sn;
                            } else {
                                $displayName = $given;
                            }
                            $label = dashboard_normalize_name($displayName);
                        }

                        $fn = dashboard_photo_filename($sn, $given);

                        $userRows[$userKey] = [
                            'dn'                         => $dn,
                            'sn'                         => $sn,
                            'givenname'                  => $given,
                            'mail'                       => $mail,
                            'physicaldeliveryofficename' => $office,
                            '_phones'                    => $phoneList,
                            'label'                      => $label,
                            '_photo_name'                => $fn,
                            '_photo_path'                => rtrim($photoDir, '/') . '/' . $fn,
                            '_photo_exists'              => is_file(rtrim($photoDir, '/') . '/' . $fn),
                        ];
                    }

                    // Krok 3: przekształć do tablicy wierszy z kolumną 'phones' (string)
                    foreach ($userRows as $row) {
                        $phones = $row['_phones'] ?? [];
                        $row['phones'] = implode("
", $phones);
                        // Kolumna 'telephonenumber' już nie potrzebna, ale zostawiamy
                        // dla kompatybilności wstecznej (photo_filename itp.)
                        $row['telephonenumber'] = $phones[0] ?? '';
                        $rows[] = $row;
                    }
                }
            }

            @ldap_unbind($conn);
        }
    }

    // Sortowanie
    usort($rows, function (array $a, array $b) use ($sort, $dir): int {
        $cmp = strcmp(dashboard_sort_value($a, $sort), dashboard_sort_value($b, $sort));
        if ($cmp === 0) {
            $cmp = strcmp(dashboard_sort_value($a, 'sn'), dashboard_sort_value($b, 'sn'));
            if ($cmp === 0) {
                $cmp = strcmp(dashboard_sort_value($a, 'givenname'), dashboard_sort_value($b, 'givenname'));
            }
        }

        return $dir === 'desc' ? -$cmp : $cmp;
    });

    $baseParams = [];
    if ($officeFilter !== null) {
        $baseParams['office'] = $officeFilter;
    }

    return [
        'errors'      => $errors,
        'rows'        => $rows,
        'columns'     => $columns,
        'labels'      => $columnLabels,
        'sort'        => $sort,
        'dir'         => $dir,
        'officeFilter' => $officeFilter,
        'baseParams'  => $baseParams,
        'photoDir'    => $photoDir,
        'totalCount'  => $totalCount,
    ];
}