<?php
// pages/devices.php
require_login($config);
require_once __DIR__ . '/../inc/devices_lib.php';

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

$error = null;

$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editing = $editId > 0;

// modal: numery
$phonesDeviceId = isset($_GET['phones']) ? (int)$_GET['phones'] : 0;

// słowniki
$types = dev_get_types($pdo);
$typeById = dev_build_type_map($types);
$phones = dev_get_phones($pdo);

// POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'add_device') {
            dev_insert_device($pdo, [
                'equipment_type_id' => (int)($_POST['equipment_type_id'] ?? 0),
                'ip_address' => trim((string)($_POST['ip_address'] ?? '')),
                'mac_address' => trim((string)($_POST['mac_address'] ?? '')),
                'location' => trim((string)($_POST['location'] ?? '')),
                'firmware' => trim((string)($_POST['firmware'] ?? '')),
                'comment' => trim((string)($_POST['comment'] ?? '')),
            ]);

            header('Location: index.php?page=devices');
            exit;
        }

        if ($action === 'update_device') {
            $id = (int)($_POST['id'] ?? 0);
            dev_update_device($pdo, $id, [
                'equipment_type_id' => (int)($_POST['equipment_type_id'] ?? 0),
                'ip_address' => trim((string)($_POST['ip_address'] ?? '')),
                'mac_address' => trim((string)($_POST['mac_address'] ?? '')),
                'location' => trim((string)($_POST['location'] ?? '')),
                'firmware' => trim((string)($_POST['firmware'] ?? '')),
                'comment' => trim((string)($_POST['comment'] ?? '')),
            ]);

            header('Location: index.php?page=devices');
            exit;
        }

        if ($action === 'delete_device') {
            dev_delete_device($pdo, (int)($_POST['id'] ?? 0));
            header('Location: index.php?page=devices');
            exit;
        }

        if ($action === 'save_device_phones') {
            $deviceId = (int)($_POST['device_id'] ?? 0);
            $typeId   = (int)($_POST['equipment_type_id'] ?? 0);
            $phoneIds = is_array($_POST['phone_id'] ?? null) ? $_POST['phone_id'] : [];
            $actives  = is_array($_POST['is_active'] ?? null) ? $_POST['is_active'] : [];

            dev_save_device_phones($pdo, $deviceId, $typeId, $typeById, $phoneIds, $actives);

            header('Location: index.php?page=devices');
            exit;
        }

    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            $msg = (string)($e->getMessage() ?? '');
            if (stripos($msg, 'uq_devices_ip') !== false) {
                $error = 'Taki adres IP już istnieje.';
            } elseif (stripos($msg, 'uq_devices_mac') !== false) {
                $error = 'Taki adres MAC już istnieje.';
            } else {
                $error = 'Duplikat (unikalność) — IP lub MAC już istnieje.';
            }
        } else {
            $error = 'Błąd bazy danych.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// LISTA
$rows = dev_list_devices($pdo);

// Numery do listy
$deviceIds = array_values(array_unique(array_map(fn($r) => (int)$r['id'], $rows)));
$phonesByDevice = dev_get_phone_lists_for_devices($pdo, $deviceIds);

// FORM add/edit
$formTitle = 'Dodaj nowe urządzenie';
$submitText = 'Zapisz';

$initialTypeId = 0;
$initialIp = '';
$initialMac = '';
$initialLocation = '';
$initialFirmware = '';
$initialComment = '';

if ($editing) {
    $row = dev_get_device($pdo, $editId);
    if ($row) {
        $formTitle = 'Edytuj urządzenie';
        $submitText = 'Zapisz zmiany';

        $initialTypeId = (int)$row['equipment_type_id'];
        $initialIp = (string)($row['ip_address'] ?? '');
        $initialMac = (string)($row['mac_address'] ?? '');
        $initialLocation = (string)($row['location'] ?? '');
        $initialFirmware = (string)($row['firmware'] ?? '');
        $initialComment = (string)($row['comment'] ?? '');
    } else {
        $editing = false;
        $editId = 0;
    }
}

$showForm = ($error !== null) || $editing;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error !== null) {
    $initialTypeId = (int)($_POST['equipment_type_id'] ?? $initialTypeId);
    $initialIp = (string)($_POST['ip_address'] ?? $initialIp);
    $initialMac = (string)($_POST['mac_address'] ?? $initialMac);
    $initialLocation = (string)($_POST['location'] ?? $initialLocation);
    $initialFirmware = (string)($_POST['firmware'] ?? $initialFirmware);
    $initialComment = (string)($_POST['comment'] ?? $initialComment);
}

// MODAL numery
$modalDevice = null;
$modalSlots = 0;
$modalAssignmentsByLine = [];

if ($phonesDeviceId > 0) {
    $modalDevice = dev_get_modal_device($pdo, $phonesDeviceId);
    if ($modalDevice) {
        $modalSlots = (int)($modalDevice['phone_accounts_count'] ?? 0);
        $modalAssignmentsByLine = dev_get_assignments_by_line($pdo, $phonesDeviceId);
    } else {
        $phonesDeviceId = 0;
    }
}
?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h1 class="h3 mb-0">Urządzenia</h1>
</div>

<?php if ($error): ?>
  <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<div class="card">
  <div class="card-header">Lista urządzeń</div>

  <div class="table-responsive">
    <table class="table table-striped mb-0 align-middle"
           id="devicesTable"
           data-default-sort-col="2"
           data-default-sort-asc="1">
      <thead>
        <tr>
          <th onclick="tableSort('devicesTable', this)"
              data-sort-col="0"
              data-sort-type="text"
              style="cursor:pointer;">
            Typ <span class="sort-indicator"></span>
          </th>

          <th style="width: 170px;">Numery telefonów</th>

          <th onclick="tableSort('devicesTable', this)"
              data-sort-col="2"
              data-sort-type="ip"
              style="cursor:pointer;">
            IP <span class="sort-indicator">▲</span>
          </th>

          <th onclick="tableSort('devicesTable', this)"
              data-sort-col="3"
              data-sort-type="text"
              style="cursor:pointer;">
            MAC <span class="sort-indicator"></span>
          </th>

          <th onclick="tableSort('devicesTable', this)"
              data-sort-col="4"
              data-sort-type="text"
              style="cursor:pointer;">
            Lokalizacja <span class="sort-indicator"></span>
          </th>

          <th style="width: 320px;">Akcje</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <?php
            $did = (int)$r['id'];
            $nums = $phonesByDevice[$did] ?? ['active' => [], 'inactive' => []];
            $activeNums = $nums['active'];
            $inactiveNums = $nums['inactive'];
            $comment = trim((string)($r['comment'] ?? ''));
          ?>
          <tr>
            <td>
              <?= e((string)$r['manufacturer']) ?> <?= e((string)$r['model']) ?>
              <div class="text-muted small">sloty: <?= (int)$r['phone_accounts_count'] ?></div>
            </td>

            <td>
              <?php if ($activeNums): ?>
                <div class="fw-semibold"><?= e(implode(', ', $activeNums)) ?></div>
              <?php endif; ?>
              <?php if ($inactiveNums): ?>
                <div class="text-muted small"><?= e(implode(', ', $inactiveNums)) ?></div>
              <?php endif; ?>
              <?php if (!$activeNums && !$inactiveNums): ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>

            <td>
              <?php $ip = trim((string)($r['ip_address'] ?? '')); ?>
              <?php if ($ip !== ''): ?>
                <a href="http://<?= e($ip) ?>/" target="_blank" rel="noopener">
                  <?= e($ip) ?>
                </a>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td><?= e((string)($r['mac_address'] ?? '')) ?></td>
            <td><?= e((string)($r['location'] ?? '')) ?></td>

            <td>
              <div class="d-flex flex-wrap gap-2">

                <a class="btn btn-sm btn-outline-secondary"
                   href="index.php?page=devices&phones=<?= $did ?>">
                  Numery telefonów
                </a>

                <?php if ($comment !== ''): ?>
                  <button type="button"
                          class="btn btn-sm btn-outline-info js-show-comment"
                          data-bs-toggle="modal"
                          data-bs-target="#commentModal"
                          data-comment="<?= e($comment) ?>"
                          data-device-id="<?= $did ?>">
                    Pokaż komentarz
                  </button>
                <?php endif; ?>

                <a class="btn btn-sm btn-outline-primary"
                   href="index.php?page=devices&edit=<?= $did ?>#form">
                  Edytuj
                </a>

                <form method="post" class="m-0"
                      onsubmit="return confirm('Na pewno usunąć urządzenie?');">
                  <input type="hidden" name="action" value="delete_device">
                  <input type="hidden" name="id" value="<?= $did ?>">
                  <button class="btn btn-sm btn-outline-danger" type="submit">Usuń</button>
                </form>

              </div>
            </td>
          </tr>
        <?php endforeach; ?>

        <?php if (!$rows): ?>
          <tr>
            <td colspan="6" class="text-muted">Brak urządzeń.</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="card-body">
    <hr>

    <?php if (!$editing): ?>
      <button id="btnShowDeviceForm" class="btn btn-primary"
              type="button"
              data-bs-toggle="collapse"
              data-bs-target="#deviceForm"
              aria-expanded="<?= $showForm ? 'true' : 'false' ?>">
        Dodaj nowe
      </button>
    <?php else: ?>
      <a class="btn btn-outline-secondary" href="index.php?page=devices">Anuluj edycję</a>
    <?php endif; ?>

    <div class="collapse <?= $showForm ? 'show' : '' ?> mt-3" id="deviceForm">
      <div class="card card-body" id="form">
        <h2 class="h5 mb-3"><?= e($formTitle) ?></h2>

        <form method="post" class="row g-3">
          <?php if ($editing): ?>
            <input type="hidden" name="action" value="update_device">
            <input type="hidden" name="id" value="<?= (int)$editId ?>">
          <?php else: ?>
            <input type="hidden" name="action" value="add_device">
          <?php endif; ?>

          <div class="col-12 col-md-6">
            <label class="form-label">Typ sprzętu</label>
            <select class="form-select" name="equipment_type_id" required>
              <option value="" disabled <?= $initialTypeId <= 0 ? 'selected' : '' ?>>— wybierz —</option>
              <?php foreach ($types as $t): ?>
                <option value="<?= (int)$t['id'] ?>" <?= ((int)$t['id'] === (int)$initialTypeId) ? 'selected' : '' ?>>
                  <?= e((string)$t['manufacturer']) ?> <?= e((string)$t['model']) ?>
                  (<?= (int)$t['phone_accounts_count'] ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label">Lokalizacja</label>
            <input class="form-control" name="location" value="<?= e($initialLocation) ?>">
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">IP</label>
            <input class="form-control" name="ip_address" value="<?= e($initialIp) ?>">
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">MAC</label>
            <input class="form-control" name="mac_address" value="<?= e($initialMac) ?>">
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">Firmware</label>
            <input class="form-control" name="firmware" value="<?= e($initialFirmware) ?>">
          </div>

          <div class="col-12">
            <label class="form-label">Komentarz (do urządzenia)</label>
            <textarea class="form-control" name="comment" rows="2"><?= e($initialComment) ?></textarea>
          </div>

          <div class="col-12">
            <button class="btn btn-success" type="submit">
              <?= e($submitText) ?>
            </button>
          </div>
        </form>

      </div>
    </div>

  </div>
</div>

<!-- MODAL: Komentarz -->
<div class="modal fade" id="commentModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Komentarz urządzenia <span id="commentDeviceId"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <div id="commentBody" style="white-space: pre-wrap;"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zamknij</button>
      </div>
    </div>
  </div>
</div>

<!-- MODAL: Numery telefonów -->
<?php if ($phonesDeviceId > 0 && $modalDevice): ?>
<div class="modal fade" id="devicePhonesModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">

      <form method="post" id="devicePhonesForm">
        <input type="hidden" name="action" value="save_device_phones">
        <input type="hidden" name="device_id" value="<?= (int)$phonesDeviceId ?>">
        <input type="hidden" name="equipment_type_id" value="<?= (int)$modalDevice['equipment_type_id'] ?>">

        <div class="modal-header">
          <h5 class="modal-title">
            Numery telefonów — urządzenie #<?= (int)$modalDevice['id'] ?>
            (<?= e((string)$modalDevice['manufacturer']) ?> <?= e((string)$modalDevice['model']) ?>)
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>

        <div class="modal-body">
          <?php if ((int)$modalSlots <= 0): ?>
            <div class="alert alert-warning mb-0">Typ sprzętu ma nieprawidłową liczbę slotów.</div>
          <?php else: ?>

            <div class="table-responsive">
              <table class="table align-middle mb-0">
                <thead>
                  <tr>
                    <th style="width: 90px;">Slot</th>
                    <th>Numer telefonu</th>
                    <th style="width: 140px;">Aktywny</th>
                    <th style="width: 120px;">Usuń</th>
                  </tr>
                </thead>
                <tbody>
                  <?php for ($i = 1; $i <= (int)$modalSlots; $i++): ?>
                    <?php
                      $cur = $modalAssignmentsByLine[$i] ?? null;
                      $curPhoneId = (int)($cur['phone_id'] ?? 0);
                      $curActive  = (int)($cur['is_active'] ?? 0) === 1;
                    ?>
                    <tr>
                      <td class="fw-semibold"><?= $i ?></td>

                      <td>
                        <select class="form-select js-phone-select" name="phone_id[<?= $i ?>]" data-slot="<?= $i ?>">
                          <option value="0">— brak —</option>
                          <?php foreach ($phones as $p): ?>
                            <option value="<?= (int)$p['id'] ?>" <?= ((int)$p['id'] === $curPhoneId) ? 'selected' : '' ?>>
                              <?= e((string)$p['number']) ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </td>

                      <td>
                        <div class="form-check">
                          <input class="form-check-input js-active-checkbox"
                                 type="checkbox"
                                 name="is_active[<?= $i ?>]"
                                 id="active<?= $i ?>"
                                 data-slot="<?= $i ?>"
                                 <?= $curActive ? 'checked' : '' ?>>
                          <label class="form-check-label" for="active<?= $i ?>">tak</label>
                        </div>
                      </td>

                      <td>
                        <button type="button"
                                class="btn btn-sm btn-outline-danger js-clear-slot"
                                data-slot="<?= $i ?>">
                          Usuń
                        </button>
                      </td>
                    </tr>
                  <?php endfor; ?>
                </tbody>
              </table>
            </div>

            <div class="text-muted small mt-2">
              Uwaga: numer może być aktywny tylko na jednym urządzeniu — w przeciwnym razie zapis zakończy się błędem.
            </div>

          <?php endif; ?>
        </div>

        <div class="modal-footer">
          <a class="btn btn-outline-secondary" href="index.php?page=devices">Anuluj</a>
          <button type="submit" class="btn btn-primary" <?= !$phones ? 'disabled' : '' ?>>
            Zapisz i zamknij
          </button>
        </div>

      </form>

    </div>
  </div>
</div>

<script>
function refreshPhoneOptions() {
  const selects = Array.from(document.querySelectorAll('.js-phone-select'));
  const chosen = new Map(); // phoneId -> slot
  selects.forEach(sel => {
    const slot = sel.getAttribute('data-slot');
    const val = sel.value;
    if (val && val !== '0') chosen.set(val, slot);
  });

  selects.forEach(sel => {
    const myVal = sel.value;
    Array.from(sel.options).forEach(opt => {
      if (!opt.value || opt.value === '0') return;
      opt.disabled = (chosen.has(opt.value) && opt.value !== myVal);
    });
  });
}

document.addEventListener('DOMContentLoaded', () => {
  const modalEl = document.getElementById('devicePhonesModal');
  if (modalEl) {
    const modal = new bootstrap.Modal(modalEl);
    modal.show();
  }

  document.querySelectorAll('.js-phone-select').forEach(sel => {
    sel.addEventListener('change', refreshPhoneOptions);
  });
  refreshPhoneOptions();

  document.querySelectorAll('.js-clear-slot').forEach(btn => {
    btn.addEventListener('click', () => {
      const slot = btn.getAttribute('data-slot');
      const select = document.querySelector('.js-phone-select[data-slot="'+slot+'"]');
      const chk = document.querySelector('.js-active-checkbox[data-slot="'+slot+'"]');
      if (select) select.value = '0';
      if (chk) chk.checked = false;
      refreshPhoneOptions();
    });
  });
});
</script>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const btn = document.getElementById('btnShowDeviceForm');
  const formWrap = document.getElementById('form');
  const collapseEl = document.getElementById('deviceForm');

  if (!btn || !formWrap || !collapseEl) return;

  btn.addEventListener('click', () => {
    collapseEl.addEventListener('shown.bs.collapse', () => {
      formWrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, { once: true });
  });
});
</script>

<!-- wspólny sorter tabel -->
<script src="scripts/table_sort.js"></script>