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
            'label_name' => trim((string)($a['label_name'] ?? '')),
            'number' => trim((string)($a['number'] ?? '')),
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



function dashboard_phonebook_load(array $config, PDO $pdo): array
{
    $ldapCfg = dashboard_phonebook_resolve_ldap_config($config);
    $labelsByNumber = dashboard_load_override_labels_by_number($pdo);
    $totalCount = 0;

    $columns = [
        'label',
        'givenname',
        'sn',
        'mail',
        'telephonenumber',
        'physicaldeliveryofficename',
    ];

    $labels = [
        'label' => 'Nazwa wyświetlana',
        'givenname' => 'Imię',
        'sn' => 'Nazwisko',
        'mail' => 'Email',
        'telephonenumber' => 'Telefon',
        'physicaldeliveryofficename' => 'Biuro',
    ];

    $photoDir = '/mnt/photos';

    $officeFilterRaw = isset($_GET['office']) ? trim((string)$_GET['office']) : '';
    $officeFilter = $officeFilterRaw !== '' ? $officeFilterRaw : null;

    $sort = isset($_GET['sort']) ? strtolower(trim((string)$_GET['sort'])) : 'sn';
    $dir  = isset($_GET['dir']) ? strtolower(trim((string)$_GET['dir'])) : 'asc';

    if (!in_array($sort, $columns, true)) {
        $sort = 'sn';
    }
    if (!in_array($dir, ['asc', 'desc'], true)) {
        $dir = 'asc';
    }

    $useSsl = !empty($ldapCfg['use_ssl']);
    $host = (string)($ldapCfg['host'] ?? '');
    $port = (int)($ldapCfg['port'] ?? 389);
    $bindDn = (string)($ldapCfg['bind_dn'] ?? '');
    $bindPassword = (string)($ldapCfg['bind_password'] ?? '');
    $baseDn = (string)($ldapCfg['base_dn'] ?? '');

    if ($host !== '' && preg_match('~^ldaps?://~i', $host)) {
        $ldapUrl = $host;
        if (!preg_match('~:\d+$~', $ldapUrl)) {
            $ldapUrl .= ':' . $port;
        }
    } else {
        $ldapUrl = ($useSsl ? 'ldaps://' : 'ldap://') . $host . ':' . $port;
    }

    $errors = [];
    $rows = [];

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
                $baseFilter =
                    "(&" .
                        "(objectCategory=person)" .
                        "(objectClass=user)" .
                        "(!(userAccountControl:1.2.840.113556.1.4.803:=2))" .
                        "(|" .
                            "(mail=*)" .
                            "(telephoneNumber=*)" .
                            "(givenName=*\\20*)" .
                        ")" .
                    ")";

                if ($officeFilter !== null) {
                    $escapedOffice = dashboard_ldap_escape_filter($officeFilter);
                    $filter = "(&" . $baseFilter . "(physicalDeliveryOfficeName={$escapedOffice}))";
                } else {
                    $filter = $baseFilter;
                }

                $totalSearch = @ldap_search($conn, $baseDn, $baseFilter, ['dn'], 0, 0);
                if ($totalSearch) {
                    $totalEntries = ldap_get_entries($conn, $totalSearch);
                    $totalCount = (int)($totalEntries['count'] ?? 0);
                }

                $attrs = array_values(array_unique(array_merge(['dn'], $columns)));
                $search = @ldap_search($conn, $baseDn, $filter, $attrs, 0, 0);

                if (!$search) {
                    $errors[] = 'Błąd wyszukiwania LDAP.';
                } else {
                    $entries = ldap_get_entries($conn, $search);

                    if (isset($entries['count'])) {
                        for ($i = 0; $i < $entries['count']; $i++) {
                            $e = $entries[$i];
                            $row = ['dn' => $e['dn'] ?? '','label' => '',];

                            foreach ($columns as $col) {
                                $k = strtolower($col);

                                if (!isset($e[$k]) || !is_array($e[$k]) || !isset($e[$k]['count'])) {
                                    $row[$k] = '';
                                    continue;
                                }

                                $vals = [];
                                for ($j = 0; $j < $e[$k]['count']; $j++) {
                                    $v = $e[$k][$j] ?? null;
                                    if (!is_string($v)) {
                                        continue;
                                    }

                                    $isUtf8 = mb_check_encoding($v, 'UTF-8')
                                        && !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $v);

                                    $vals[] = $isUtf8 ? $v : 'base64:' . base64_encode($v);
                                }

                                $row[$k] = implode(' | ', $vals);
                            }



                            $phone = trim((string)($row['telephonenumber'] ?? ''));
                            $sn = trim((string)($row['sn'] ?? ''));
                            $given = trim((string)($row['givenname'] ?? ''));

                            if ($phone === '') {
                                $row['label'] = '';
                            } elseif (isset($labelsByNumber[$phone]) && trim($labelsByNumber[$phone]) !== '') {
                                $row['label'] = trim($labelsByNumber[$phone]);
                            } else {
                                $row['label'] = trim($sn . ' ' . $given);
                            }



                            $sn = (string)($row['sn'] ?? '');
                            $gn = (string)($row['givenname'] ?? '');
                            $fn = dashboard_photo_filename($sn, $gn);

                            $row['_photo_name'] = $fn;
                            $row['_photo_path'] = rtrim($photoDir, '/') . '/' . $fn;
                            $row['_photo_exists'] = is_file($row['_photo_path']);

                            $rows[] = $row;
                        }
                    }
                }
            }

            @ldap_unbind($conn);
        }
    }

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
        'errors' => $errors,
        'rows' => $rows,
        'columns' => $columns,
        'labels' => $labels,
        'sort' => $sort,
        'dir' => $dir,
        'officeFilter' => $officeFilter,
        'baseParams' => $baseParams,
        'photoDir' => $photoDir,
        'totalCount' => $totalCount,
    ];
}
