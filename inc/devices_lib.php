<?php
declare(strict_types=1);

/**
 * inc/devices_lib.php
 * Logika DB dla modułu urządzeń + przypisywanie numerów (sloty).
 *
 * Wymagane tabele/kolumny:
 * - devices: id, equipment_type_id, ip_address, mac_address, location, firmware, comment, created_at, updated_at
 * - equipment_types: id, manufacturer, model, phone_accounts_count
 * - phones: id, number
 * - device_phone_assignments: id, device_id, phone_id, line_no, is_active, valid_from, valid_to, created_at
 */

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);


if (!function_exists('dev_get_types')) {

    function dev_get_types(PDO $pdo): array
    {
        return $pdo->query("
            SELECT id, manufacturer, model, phone_accounts_count
            FROM equipment_types
            ORDER BY manufacturer ASC, model ASC
        ")->fetchAll();
    }

function dev_validate_ipv4_or_empty(?string $ip): ?string
{
    $ip = trim((string)$ip);
    if ($ip === '') return null;

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        throw new RuntimeException('Nieprawidłowy adres IP (wymagany IPv4).');
    }
    return $ip;
}

/**
 * Przyjmuje MAC w formacie:
 * - 01234590ABCD
 * - 01:23:45:90:AB:CD
 * - 01-23-45-90-ab-cd
 * - 01.23.45.90.ab.cd (też przejdzie)
 * Zapis docelowy: 01:23:45:90:AB:CD
 */
function dev_normalize_mac_or_empty(?string $mac): ?string
{
    $mac = trim((string)$mac);
    if ($mac === '') return null;

    // usuń wszystkie separatory, zostaw same hex
    $hex = preg_replace('/[^0-9a-fA-F]/', '', $mac) ?? '';
    if (strlen($hex) !== 12) {
        throw new RuntimeException('Nieprawidłowy MAC (wymagane 12 znaków 0-9 / A-F).');
    }
    if (!preg_match('/^[0-9a-fA-F]{12}$/', $hex)) {
        throw new RuntimeException('Nieprawidłowy MAC (wymagane 12 znaków 0-9 / A-F).');
    }

    $hex = strtoupper($hex);
    return implode(':', str_split($hex, 2)); // 01:23:...
}



    function dev_get_phones(PDO $pdo): array
    {
        return $pdo->query("
            SELECT id, number
            FROM phones
            ORDER BY number ASC
        ")->fetchAll();
    }

    function dev_build_type_map(array $types): array
    {
        $map = [];
        foreach ($types as $t) {
            $map[(int)$t['id']] = $t;
        }
        return $map;
    }

    function dev_list_devices(PDO $pdo): array
    {
        return $pdo->query("
            SELECT
              d.id,
              d.equipment_type_id,
              d.ip_address,
              d.mac_address,
              d.location,
              d.firmware,
              d.comment,
              et.manufacturer,
              et.model,
              et.phone_accounts_count
            FROM devices d
            JOIN equipment_types et ON et.id = d.equipment_type_id
            ORDER BY et.manufacturer ASC, et.model ASC, d.id DESC
        ")->fetchAll();
    }

    function dev_get_device(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare("
            SELECT id, equipment_type_id, ip_address, mac_address, location, firmware, comment
            FROM devices
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    function dev_insert_device(PDO $pdo, array $data): void
    {
        $equipmentTypeId = (int)($data['equipment_type_id'] ?? 0);
        if ($equipmentTypeId <= 0) {
            throw new RuntimeException('Typ sprzętu jest wymagany.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO devices
              (equipment_type_id, ip_address, mac_address, location, firmware, comment, created_at)
            VALUES
              (:tid, :ip, :mac, :loc, :fw, :comment, NOW())
        ");
        $ip  = dev_validate_ipv4_or_empty($data['ip_address'] ?? null);
        $mac = dev_normalize_mac_or_empty($data['mac_address'] ?? null);

        $stmt->execute([
            ':tid' => $equipmentTypeId,
            ':ip' => $ip,
            ':mac' => $mac,
            ':loc' => (($data['location'] ?? '') === '' ? null : $data['location']),
            ':fw' => (($data['firmware'] ?? '') === '' ? null : $data['firmware']),
            ':comment' => (($data['comment'] ?? '') === '' ? null : $data['comment']),
        ]);
    }

    function dev_update_device(PDO $pdo, int $id, array $data): void
    {
        $equipmentTypeId = (int)($data['equipment_type_id'] ?? 0);
        if ($id <= 0 || $equipmentTypeId <= 0) {
            throw new RuntimeException('Nieprawidłowe dane.');
        }

        $stmt = $pdo->prepare("
            UPDATE devices
            SET equipment_type_id = :tid,
                ip_address = :ip,
                mac_address = :mac,
                location = :loc,
                firmware = :fw,
                comment = :comment,
                updated_at = NOW()
            WHERE id = :id
        ");
        $ip  = dev_validate_ipv4_or_empty($data['ip_address'] ?? null);
        $mac = dev_normalize_mac_or_empty($data['mac_address'] ?? null);
        $stmt->execute([
            ':tid' => $equipmentTypeId,
            ':ip' => $ip,
            ':mac' => $mac,
            ':loc' => (($data['location'] ?? '') === '' ? null : $data['location']),
            ':fw' => (($data['firmware'] ?? '') === '' ? null : $data['firmware']),
            ':comment' => (($data['comment'] ?? '') === '' ? null : $data['comment']),
            ':id' => $id,
        ]);
    }

    function dev_delete_device(PDO $pdo, int $id): void
    {
        if ($id <= 0) return;

        $stmt = $pdo->prepare("DELETE FROM devices WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    /**
     * Pobierz numery przypisane do urządzeń (do listy).
     * Wynik: device_id => ['active'=>[nums], 'inactive'=>[nums]]
     */
    function dev_get_phone_lists_for_devices(PDO $pdo, array $deviceIds): array
    {
        $deviceIds = array_values(array_filter(array_map('intval', $deviceIds), fn($x) => $x > 0));
        if (!$deviceIds) return [];

        $in = implode(',', array_fill(0, count($deviceIds), '?'));

        $stmt = $pdo->prepare("
            SELECT dpa.device_id, p.number, dpa.is_active
            FROM device_phone_assignments dpa
            JOIN phones p ON p.id = dpa.phone_id
            WHERE dpa.valid_to IS NULL
              AND dpa.device_id IN ($in)
            ORDER BY p.number ASC
        ");
        $stmt->execute($deviceIds);
        $rows = $stmt->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $did = (int)($r['device_id'] ?? 0);
            $num = trim((string)($r['number'] ?? ''));
            $act = (int)($r['is_active'] ?? 0) === 1;

            if ($did <= 0 || $num === '') continue;

            if (!isset($out[$did])) $out[$did] = ['active' => [], 'inactive' => []];
            if ($act) $out[$did]['active'][] = $num;
            else      $out[$did]['inactive'][] = $num;
        }

        foreach ($out as $did => $x) {
            $out[$did]['active'] = array_values(array_unique($x['active']));
            $out[$did]['inactive'] = array_values(array_unique($x['inactive']));
            sort($out[$did]['active'], SORT_NATURAL);
            sort($out[$did]['inactive'], SORT_NATURAL);
        }

        return $out;
    }

    /**
     * Dane do modala numerów: urządzenie + sloty + przypisania po line_no
     */
    function dev_get_modal_device(PDO $pdo, int $deviceId): ?array
    {
        if ($deviceId <= 0) return null;

        $stmt = $pdo->prepare("
            SELECT
              d.id,
              d.equipment_type_id,
              et.manufacturer,
              et.model,
              et.phone_accounts_count
            FROM devices d
            JOIN equipment_types et ON et.id = d.equipment_type_id
            WHERE d.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $deviceId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    function dev_get_assignments_by_line(PDO $pdo, int $deviceId): array
    {
        if ($deviceId <= 0) return [];

        $stmt = $pdo->prepare("
            SELECT phone_id, line_no, is_active
            FROM device_phone_assignments
            WHERE device_id = :did
              AND valid_to IS NULL
        ");
        $stmt->execute([':did' => $deviceId]);
        $rows = $stmt->fetchAll();

        $out = [];
        foreach ($rows as $a) {
            $ln = (int)($a['line_no'] ?? 0);
            if ($ln <= 0) continue;
            $out[$ln] = [
                'phone_id' => (int)($a['phone_id'] ?? 0),
                'is_active' => (int)($a['is_active'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Zapis slotów (1..N) dla urządzenia.
     * - slot pusty => zamyka istniejące przypisanie (valid_to NOW, is_active 0)
     * - aktywacja => dezaktywuje aktywny rekord z tym phone_id na innych urządzeniach
     */
    function dev_save_device_phones(PDO $pdo, int $deviceId, int $typeId, array $typeById, array $phoneIds, array $actives): void
    {
        if ($deviceId <= 0 || $typeId <= 0) {
            throw new RuntimeException('Nieprawidłowe dane zapisu numerów.');
        }

        $maxSlots = (int)($typeById[$typeId]['phone_accounts_count'] ?? 0);
        if ($maxSlots <= 0) {
            throw new RuntimeException('Nieprawidłowa liczba slotów dla typu sprzętu.');
        }


// 0) Walidacja: ten sam numer nie może wystąpić 2x w slotach jednego urządzenia
$used = [];
for ($line = 1; $line <= $maxSlots; $line++) {
    $pid = (int)($phoneIds[$line] ?? 0);
    if ($pid <= 0) continue;

    if (isset($used[$pid])) {
        throw new RuntimeException("Nie można przypisać tego samego numeru do urządzenia więcej niż raz (slot {$used[$pid]} i {$line}).");
    }
    $used[$pid] = $line;
}



        $pdo->beginTransaction();
        try {
            // bieżące przypisania dla urządzenia: line_no => row
            $stmt = $pdo->prepare("
                SELECT id, phone_id, line_no, is_active
                FROM device_phone_assignments
                WHERE device_id = :did
                  AND valid_to IS NULL
            ");
            $stmt->execute([':did' => $deviceId]);
            $current = $stmt->fetchAll();

            $curByLine = [];
            foreach ($current as $c) {
                $ln = (int)($c['line_no'] ?? 0);
                if ($ln > 0) $curByLine[$ln] = $c;
            }

            for ($line = 1; $line <= $maxSlots; $line++) {
                $newPhoneId = (int)($phoneIds[$line] ?? 0);
                $newActive  = isset($actives[$line]) ? 1 : 0;

                $existing = $curByLine[$line] ?? null;
                $existingId = (int)($existing['id'] ?? 0);
                $existingPhoneId = (int)($existing['phone_id'] ?? 0);

                // Slot pusty -> zamknij istniejące przypisanie (jeśli było)
                if ($newPhoneId <= 0) {
                    if ($existingId > 0) {
                        $stmt = $pdo->prepare("
                            UPDATE device_phone_assignments
                            SET valid_to = NOW(), is_active = 0
                            WHERE id = :id AND valid_to IS NULL
                        ");
                        $stmt->execute([':id' => $existingId]);
                    }
                    continue;
                }

                // Aktywacja numeru -> globalnie tylko na 1 urządzeniu
if ($newActive === 1) {
    // Sprawdź czy ten numer jest już aktywny na innym urządzeniu
    $stmt = $pdo->prepare("
        SELECT device_id, line_no
        FROM device_phone_assignments
        WHERE phone_id = :pid
          AND valid_to IS NULL
          AND is_active = 1
          AND device_id <> :did
        LIMIT 1
    ");
    $stmt->execute([':pid' => $newPhoneId, ':did' => $deviceId]);
    $conflict = $stmt->fetch();

    if ($conflict) {
        $cDev = (int)($conflict['device_id'] ?? 0);
        $cLine = (int)($conflict['line_no'] ?? 0);
        throw new RuntimeException("Numer jest już aktywny na innym urządzeniu (urządzenie #{$cDev}, slot {$cLine}).");
    }
}





                // Jeśli slot miał inny phone_id -> zamknij stare i potraktuj jako nowe
                if ($existingId > 0 && $existingPhoneId > 0 && $existingPhoneId !== $newPhoneId) {
                    $stmt = $pdo->prepare("
                        UPDATE device_phone_assignments
                        SET valid_to = NOW(), is_active = 0
                        WHERE id = :id AND valid_to IS NULL
                    ");
                    $stmt->execute([':id' => $existingId]);

                    $existingId = 0;
                    $existingPhoneId = 0;
                }

                if ($existingId <= 0) {
                    $stmt = $pdo->prepare("
                        INSERT INTO device_phone_assignments
                          (device_id, phone_id, line_no, is_active, valid_from, valid_to, created_at)
                        VALUES
                          (:did, :pid, :line, :active, NOW(), NULL, NOW())
                    ");
                    $stmt->execute([
                        ':did' => $deviceId,
                        ':pid' => $newPhoneId,
                        ':line' => $line,
                        ':active' => $newActive,
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE device_phone_assignments
                        SET phone_id = :pid,
                            is_active = :active
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':pid' => $newPhoneId,
                        ':active' => $newActive,
                        ':id' => $existingId,
                    ]);
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}