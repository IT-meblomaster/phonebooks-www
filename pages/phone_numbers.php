<?php
// pages/phones.php
require_login($config);

$error = null;

$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editing = $editId > 0;

// Modal przypisania telefonu -> urządzenie
$assignPhoneId = isset($_GET['assign']) ? (int)$_GET['assign'] : 0;

/**
 * Pobierz aktywne przypisania numerów do urządzeń (tylko aktywne)
 * Zakładamy: device_phone_assignments.valid_to IS NULL i is_active=1
 * oraz: devices.equipment_type_id -> equipment_types(manufacturer, model, phone_accounts_count)
 */
function pb_list_active_device_assignments(PDO $pdo): array
{
    $rows = $pdo->query("
        SELECT
            p.id AS phone_id,
            p.number,
            d.id AS device_id,
            d.ip_address,
            et.manufacturer,
            et.model
        FROM phones p
        LEFT JOIN device_phone_assignments dpa
            ON dpa.phone_id = p.id
           AND dpa.valid_to IS NULL
           AND dpa.is_active = 1
        LEFT JOIN devices d
            ON d.id = dpa.device_id
        LEFT JOIN equipment_types et
            ON et.id = d.equipment_type_id
        ORDER BY p.number ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // map: phone_id => ['device_id'=>..., 'device_name'=>..., 'ip'=>...]
    $out = [];
    foreach ($rows as $r) {
        $pid = (int)($r['phone_id'] ?? 0);
        if ($pid <= 0) continue;

        $did = (int)($r['device_id'] ?? 0);
        $deviceName = '';
        if ($did > 0) {
            $deviceName = trim((string)($r['manufacturer'] ?? '') . ' ' . (string)($r['model'] ?? ''));
        }

        $out[$pid] = [
            'device_id' => $did,
            'device_name' => $deviceName,
            'ip' => trim((string)($r['ip_address'] ?? '')),
        ];
    }

    return $out;
}

/**
 * Lista urządzeń z wolnymi slotami.
 * Wolne sloty liczymy jako: slots - count(aktualnych przypisań valid_to IS NULL)
 */
function pb_list_devices_with_free_slots(PDO $pdo): array
{
    return $pdo->query("
        SELECT
            d.id AS device_id,
            d.ip_address,
            et.manufacturer,
            et.model,
            et.phone_accounts_count,
            (
              et.phone_accounts_count - IFNULL(x.used_cnt, 0)
            ) AS free_slots
        FROM devices d
        JOIN equipment_types et ON et.id = d.equipment_type_id
        LEFT JOIN (
            SELECT device_id, COUNT(*) AS used_cnt
            FROM device_phone_assignments
            WHERE valid_to IS NULL
            GROUP BY device_id
        ) x ON x.device_id = d.id
        WHERE (et.phone_accounts_count - IFNULL(x.used_cnt, 0)) > 0
        ORDER BY et.manufacturer ASC, et.model ASC, d.id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Pobierz dane telefonu do modala
 */
function pb_get_phone(PDO $pdo, int $phoneId): ?array
{
    $stmt = $pdo->prepare("SELECT id, number FROM phones WHERE id = :id");
    $stmt->execute([':id' => $phoneId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/**
 * Przypisz telefon do urządzenia:
 * - wybiera pierwszy wolny slot (line_no)
 * - pozwala ustawić is_active (checkbox w modalu)
 * - jeżeli is_active=1 i numer aktywny na innym urządzeniu -> błąd
 * - jeżeli numer już ma aktywne przypisanie (valid_to IS NULL) do tego samego urządzenia -> błąd
 */
function pb_assign_phone_to_device(PDO $pdo, int $phoneId, int $deviceId, bool $makeActive): void
{
    if ($phoneId <= 0 || $deviceId <= 0) {
        throw new RuntimeException('Nieprawidłowe dane przypisania.');
    }

    $pdo->beginTransaction();
    try {
        // 1) Ustal liczbę slotów dla urządzenia
        $stmt = $pdo->prepare("
            SELECT d.id, d.equipment_type_id, et.phone_accounts_count
            FROM devices d
            JOIN equipment_types et ON et.id = d.equipment_type_id
            WHERE d.id = :did
            LIMIT 1
        ");
        $stmt->execute([':did' => $deviceId]);
        $dev = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$dev) {
            throw new RuntimeException('Nie znaleziono urządzenia.');
        }
        $slots = (int)($dev['phone_accounts_count'] ?? 0);
        if ($slots <= 0) {
            throw new RuntimeException('Nieprawidłowa liczba slotów w typie urządzenia.');
        }

        // 2) Jeżeli ma być aktywny -> sprawdź czy numer nie jest aktywny na innym urządzeniu
        if ($makeActive) {
            $stmt = $pdo->prepare("
                SELECT device_id, line_no
                FROM device_phone_assignments
                WHERE phone_id = :pid
                  AND valid_to IS NULL
                  AND is_active = 1
                  AND device_id <> :did
                LIMIT 1
            ");
            $stmt->execute([':pid' => $phoneId, ':did' => $deviceId]);
            $conf = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($conf) {
                $cDev = (int)($conf['device_id'] ?? 0);
                $cLine = (int)($conf['line_no'] ?? 0);
                throw new RuntimeException("Numer jest już aktywny na innym urządzeniu (urządzenie #{$cDev}, slot {$cLine}).");
            }
        }

        // 3) Sprawdź czy numer już nie jest przypisany do tego urządzenia (aktywny rekord)
        $stmt = $pdo->prepare("
            SELECT 1
            FROM device_phone_assignments
            WHERE device_id = :did
              AND phone_id = :pid
              AND valid_to IS NULL
            LIMIT 1
        ");
        $stmt->execute([':did' => $deviceId, ':pid' => $phoneId]);
        if ($stmt->fetchColumn()) {
            throw new RuntimeException('Ten numer jest już przypisany do tego urządzenia.');
        }

        // 4) Znajdź pierwszy wolny slot
        $stmt = $pdo->prepare("
            SELECT line_no
            FROM device_phone_assignments
            WHERE device_id = :did
              AND valid_to IS NULL
        ");
        $stmt->execute([':did' => $deviceId]);
        $used = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ln = (int)($r['line_no'] ?? 0);
            if ($ln > 0) $used[$ln] = true;
        }

        $freeLine = 0;
        for ($i = 1; $i <= $slots; $i++) {
            if (!isset($used[$i])) { $freeLine = $i; break; }
        }
        if ($freeLine <= 0) {
            throw new RuntimeException('To urządzenie nie ma wolnych slotów.');
        }

        // 5) Insert przypisania
        $stmt = $pdo->prepare("
            INSERT INTO device_phone_assignments
                (device_id, phone_id, line_no, is_active, valid_from, valid_to)
            VALUES
                (:did, :pid, :ln, :act, NOW(), NULL)
        ");
        $stmt->execute([
            ':did' => $deviceId,
            ':pid' => $phoneId,
            ':ln'  => $freeLine,
            ':act' => $makeActive ? 1 : 0,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

// OBSŁUGA POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add_phone') {
        $number = trim((string)($_POST['number'] ?? ''));

        if ($number === '') {
            $error = 'Numer jest wymagany.';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO phones (number) VALUES (:number)");
                $stmt->execute([':number' => $number]);

                header('Location: index.php?page=phones');
                exit;
            } catch (PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                    $error = 'Taki numer już istnieje.';
                } else {
                    $error = 'Błąd zapisu do bazy.';
                }
            }
        }
    }

    if ($action === 'update_phone') {
        $id     = (int)($_POST['id'] ?? 0);
        $number = trim((string)($_POST['number'] ?? ''));

        if ($id <= 0 || $number === '') {
            $error = 'Nieprawidłowe dane.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE phones
                    SET number = :number
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':number' => $number,
                    ':id' => $id
                ]);

                header('Location: index.php?page=phones');
                exit;
            } catch (PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                    $error = 'Taki numer już istnieje.';
                } else {
                    $error = 'Błąd aktualizacji.';
                }
            }
        }
    }

    if ($action === 'delete_phone') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM phones WHERE id = :id");
                $stmt->execute([':id' => $id]);

                header('Location: index.php?page=phones');
                exit;
            } catch (PDOException $e) {
                $error = 'Nie można usunąć numeru (może być używany w przypisaniach).';
            }
        }
    }

    if ($action === 'assign_phone_to_device') {
        try {
            $phoneId  = (int)($_POST['phone_id'] ?? 0);
            $deviceId = (int)($_POST['device_id'] ?? 0);
            $active   = isset($_POST['make_active']) && (string)$_POST['make_active'] === '1';

            pb_assign_phone_to_device($pdo, $phoneId, $deviceId, $active);

            header('Location: index.php?page=phones');
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
            // zostawimy modal otwarty przez GET assign=...
            $assignPhoneId = (int)($_POST['phone_id'] ?? 0);
        }
    }
}

// Pobierz listę numerów
$rows = $pdo->query("
    SELECT id, number
    FROM phones
    ORDER BY number ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Aktywne przypisania (phone_id => device info)
$activeAssign = pb_list_active_device_assignments($pdo);

// Dane formularza
$formMode = 'add';
$formTitle = 'Dodaj nowy numer';
$submitText = 'Zapisz';
$initialNumber = '';

if ($editing) {
    $stmt = $pdo->prepare("SELECT id, number FROM phones WHERE id = :id");
    $stmt->execute([':id' => $editId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $formMode = 'edit';
        $formTitle = 'Edytuj numer';
        $submitText = 'Zapisz zmiany';
        $initialNumber = (string)$row['number'];
    } else {
        $editing = false;
    }
}

$showForm = ($error !== null) || $editing;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error !== null) {
    $initialNumber = (string)($_POST['number'] ?? $initialNumber);
}

// Modal: dane telefonu i lista urządzeń z wolnymi slotami
$modalPhone = null;
$modalDevices = [];
if ($assignPhoneId > 0) {
    $modalPhone = pb_get_phone($pdo, $assignPhoneId);
    if ($modalPhone) {
        $modalDevices = pb_list_devices_with_free_slots($pdo);
    } else {
        $assignPhoneId = 0;
    }
}
?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h1 class="h3 mb-0">Numery telefonów</h1>
</div>

<?php if ($error): ?>
  <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<div class="card">
  <div class="card-header">Lista numerów</div>

  <div class="table-responsive">
    <table class="table table-striped mb-0 align-middle"
           id="phonesTable"
           data-default-sort-col="0"
           data-default-sort-asc="1">
      <thead>
        <tr>
          <th onclick="tableSort('phonesTable', this)"
              data-sort-col="0"
              data-sort-type="text"
              style="cursor:pointer;">
            Numer <span class="sort-indicator">▲</span>
          </th>

          <th onclick="tableSort('phonesTable', this)"
              data-sort-col="1"
              data-sort-type="text"
              style="cursor:pointer;">
            Przypisane do urządzenia <span class="sort-indicator"></span>
          </th>

          <th onclick="tableSort('phonesTable', this)"
              data-sort-col="2"
              data-sort-type="ip"
              style="cursor:pointer;">
            IP <span class="sort-indicator"></span>
          </th>

          <th style="width: 170px;">Akcje</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <?php
            $pid = (int)$r['id'];
            $num = (string)$r['number'];

            $a = $activeAssign[$pid] ?? null;
            $deviceId = (int)($a['device_id'] ?? 0);
            $deviceName = trim((string)($a['device_name'] ?? ''));
            $ip = trim((string)($a['ip'] ?? ''));
            $hasActiveDevice = $deviceId > 0;
          ?>
          <tr>
            <td><?= e($num) ?></td>

            <td>
              <?php if ($hasActiveDevice): ?>
                <?= e($deviceName !== '' ? $deviceName : ('urządzenie #' . $deviceId)) ?>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>

            <td>
              <?php if ($hasActiveDevice && $ip !== ''): ?>
                <a href="http://<?= e($ip) ?>/" target="_blank" rel="noopener">
                  <?= e($ip) ?>
                </a>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>

            <td>
              <div class="d-flex gap-2">
                <?php if (!$hasActiveDevice): ?>
                  <a class="btn btn-sm btn-outline-secondary"
                     href="index.php?page=phones&assign=<?= $pid ?>">
                    Przypisz
                  </a>
                <?php endif; ?>

                <a class="btn btn-sm btn-outline-primary"
                   href="index.php?page=phones&edit=<?= $pid ?>#form">
                  Edytuj
                </a>

                <form method="post" class="m-0"
                      onsubmit="return confirm('Na pewno usunąć numer <?= e($num) ?>?');">
                  <input type="hidden" name="action" value="delete_phone">
                  <input type="hidden" name="id" value="<?= $pid ?>">
                  <button class="btn btn-sm btn-outline-danger" type="submit">
                    Usuń
                  </button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>

        <?php if (!$rows): ?>
          <tr>
            <td colspan="4" class="text-muted">Brak numerów.</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="card-body">
    <hr>

    <?php if (!$editing): ?>
      <button class="btn btn-primary"
              type="button"
              data-bs-toggle="collapse"
              data-bs-target="#phoneForm"
              aria-expanded="<?= $showForm ? 'true' : 'false' ?>">
        Dodaj nowy
      </button>
    <?php else: ?>
      <a class="btn btn-outline-secondary" href="index.php?page=phones">Anuluj edycję</a>
    <?php endif; ?>

    <div class="collapse <?= $showForm ? 'show' : '' ?> mt-3" id="phoneForm">
      <div class="card card-body" id="form">
        <h2 class="h5 mb-3"><?= e($formTitle) ?></h2>

        <form method="post" class="row g-3">
          <?php if ($editing): ?>
            <input type="hidden" name="action" value="update_phone">
            <input type="hidden" name="id" value="<?= (int)$editId ?>">
          <?php else: ?>
            <input type="hidden" name="action" value="add_phone">
          <?php endif; ?>

          <div class="col-12">
            <label class="form-label">Numer</label>
            <input class="form-control"
                   name="number"
                   required
                   value="<?= e($initialNumber) ?>">
          </div>

          <div class="col-12">
            <button class="btn btn-success" type="submit"><?= e($submitText) ?></button>
          </div>
        </form>

      </div>
    </div>

  </div>
</div>

<!-- MODAL: Przypisz numer do urządzenia -->
<?php if ($assignPhoneId > 0 && $modalPhone): ?>
<div class="modal fade" id="assignPhoneModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">

      <form method="post" id="assignPhoneForm">
        <input type="hidden" name="action" value="assign_phone_to_device">
        <input type="hidden" name="phone_id" value="<?= (int)$modalPhone['id'] ?>">

        <div class="modal-header">
          <h5 class="modal-title">Przypisz numer <?= e((string)$modalPhone['number']) ?> do urządzenia</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>

        <div class="modal-body">
          <?php if (!$modalDevices): ?>
            <div class="alert alert-warning mb-0">
              Brak urządzeń z wolnymi slotami.
            </div>
          <?php else: ?>
            <div class="mb-3">
              <label class="form-label">Urządzenie (tylko z wolnymi slotami)</label>
              <select class="form-select" name="device_id" required>
                <option value="" selected disabled>— wybierz —</option>
                <?php foreach ($modalDevices as $d): ?>
                  <?php
                    $dn = trim((string)($d['manufacturer'] ?? '') . ' ' . (string)($d['model'] ?? ''));
                    $dip = trim((string)($d['ip_address'] ?? ''));
                    $free = (int)($d['free_slots'] ?? 0);
                    $did = (int)($d['device_id'] ?? 0);
                  ?>
                  <option value="<?= $did ?>">
                    <?= e($dn) ?>
                    <?= $dip !== '' ? (' — IP ' . $dip) : '' ?>
                    (wolne: <?= $free ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="make_active" value="1" id="makeActive" checked>
              <label class="form-check-label" for="makeActive">Ustaw jako aktywny</label>
            </div>

            <div class="text-muted small mt-2">
              Jeśli numer jest już aktywny na innym urządzeniu, zapis zakończy się błędem.
            </div>
          <?php endif; ?>
        </div>

        <div class="modal-footer">
          <a class="btn btn-outline-secondary" href="index.php?page=phones">Anuluj</a>
          <button type="submit" class="btn btn-primary" <?= !$modalDevices ? 'disabled' : '' ?>>
            Zapisz i zamknij
          </button>
        </div>

      </form>

    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const modalEl = document.getElementById('assignPhoneModal');
  if (modalEl) {
    const modal = new bootstrap.Modal(modalEl);
    modal.show();
  }
});
</script>
<?php endif; ?>

<!-- wspólny sorter tabel -->
<script src="scripts/table_sort.js"></script>