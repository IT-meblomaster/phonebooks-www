<?php
// pages/phonebook.php
require_login($config);
require_once __DIR__ . '/../inc/ad.php';

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

$error = null;
$success = null;

// Lista etykiet do modala
$labels = $pdo->query("
  SELECT id, display_name
  FROM directory_entries
  ORDER BY display_name ASC
")->fetchAll();

/**
 * Pomocniczo: zamiana "Imię Nazwisko" -> "Nazwisko Imię"
 * (dla prostych przypadków; jeśli nie da się rozpoznać, zwraca oryginał)
 */
function pb_swap_first_last_name(string $name): string {
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

/**
 * Generowanie XML (Grandstream + Yealink)
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'generate_xml') {
    try {
        require_once __DIR__ . '/../inc/phonebook_builder.php';
        require_once __DIR__ . '/../inc/phonebook_export_grandstream.php';
        require_once __DIR__ . '/../inc/phonebook_export_yealink.php';

        $pdoGen = pb_db_connect($config);
        $entries = pb_build_directory($config, $pdoGen);

        $baseDir = '/var/www/10.0.5.219';
        if ($baseDir === false) {
            throw new RuntimeException('Nie można ustalić katalogu bazowego projektu.');
        }

        $file1 = $baseDir . '/phonebook/grandstream.xml';
        $file2 = $baseDir . '/phonebook/yealink.xml';

        pb_export_addressbook_xml($entries, $file1);
        pb_export_yealink_xml($entries, $file2, 'Meblomaster');

        $success = "Wygenerowano XML (" . count($entries) . " rekordów)";
    } catch (Throwable $e) {
        $error = "Błąd generowania XML: " . $e->getMessage();
    }
}

/**
 * Przypisanie etykiety do numeru (override w DB)
 * - jeśli numer nie ma phone_id (np. tylko z AD) -> dodajemy go do tabeli phones, potem robimy override
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'assign_label') {
    $phoneId = (int)($_POST['phone_id'] ?? 0);
    $labelId = (int)($_POST['label_id'] ?? 0);
    $phoneNumber = trim((string)($_POST['phone_number'] ?? ''));

    if ($labelId <= 0) {
        $error = 'Nieprawidłowe dane przypisania (brak etykiety).';
    } else {
        try {
            $pdo->beginTransaction();

            // Jeśli nie mamy phone_id -> znajdź/dodaj numer w phones
            if ($phoneId <= 0) {
                if ($phoneNumber === '') {
                    throw new RuntimeException('Brak numeru telefonu w żądaniu.');
                }

                // spróbuj znaleźć istniejący
                $stmt = $pdo->prepare("SELECT id FROM phones WHERE number = :n LIMIT 1");
                $stmt->execute([':n' => $phoneNumber]);
                $found = $stmt->fetch();

                if ($found && !empty($found['id'])) {
                    $phoneId = (int)$found['id'];
                } else {
                    // dodaj nowy
                    $stmt = $pdo->prepare("INSERT INTO phones (number) VALUES (:n)");
                    $stmt->execute([':n' => $phoneNumber]);
                    $phoneId = (int)$pdo->lastInsertId();
                }
            }

            if ($phoneId <= 0) {
                throw new RuntimeException('Nie udało się ustalić phone_id.');
            }

            // Zamknij istniejące aktywne przypisanie dla telefonu
            $stmt = $pdo->prepare("
              UPDATE phone_assignments
              SET valid_to = NOW()
              WHERE phone_id = :pid AND valid_to IS NULL
            ");
            $stmt->execute([':pid' => $phoneId]);

            // Dodaj nowe aktywne przypisanie
            $stmt = $pdo->prepare("
              INSERT INTO phone_assignments
                (phone_id, directory_entry_id, valid_from, valid_to, assigned_by_admin_id, comment)
              VALUES
                (:pid, :eid, NOW(), NULL, :aid, NULL)
            ");
            $stmt->execute([
                ':pid' => $phoneId,
                ':eid' => $labelId,
                ':aid' => (int)($_SESSION['admin_id'] ?? 0),
            ]);

            $pdo->commit();
            header('Location: index.php?page=phonebook');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Błąd przypisywania: ' . $e->getMessage();
        }
    }
}

/**
 * Usunięcie (zakończenie) przypisania z bazy: tylko jeśli istnieje assignment_id
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'delete_assignment') {
    $assignmentId = (int)($_POST['assignment_id'] ?? 0);

    if ($assignmentId <= 0) {
        $error = 'Nieprawidłowe dane usuwania przypisania.';
    } else {
        try {
            $stmt = $pdo->prepare("
              UPDATE phone_assignments
              SET valid_to = NOW()
              WHERE id = :id AND valid_to IS NULL
            ");
            $stmt->execute([':id' => $assignmentId]);

            header('Location: index.php?page=phonebook');
            exit;
        } catch (PDOException $e) {
            $error = 'Błąd podczas usuwania przypisania.';
        }
    }
}

/**
 * 1) AD: aktywni userzy z telephoneNumber OR mobile (spłaszczone do number)
 * + wykrywanie duplikatów numerów w AD
 */
$adCfg = $config['ad'] ?? null;
$adUsers = is_array($adCfg) ? ad_list_active_users_with_phones($adCfg) : [];

// Mapy dla AD
$adFirstByNumber = [];   // number => pierwszy rekord
$adAllByNumber = [];     // number => [rekordy...]
foreach ($adUsers as $u) {
    $num = trim((string)($u['number'] ?? ''));
    if ($num === '') continue;

    $displayName = (string)($u['displayName'] ?? '');
    $displayName = pb_swap_first_last_name($displayName);

    $row = [
        'number' => $num,
        'displayName' => $displayName,
        'sAMAccountName' => (string)($u['sAMAccountName'] ?? ''),
        'dn' => (string)($u['dn'] ?? ''),
    ];

    $adAllByNumber[$num][] = $row;
    if (!isset($adFirstByNumber[$num])) {
        $adFirstByNumber[$num] = $row;
    }
}

// Duplikaty AD: number => list
$adDuplicates = [];
foreach ($adAllByNumber as $num => $list) {
    if (count($list) > 1) {
        $adDuplicates[$num] = $list;
    }
}

// Dodatkowo: lista nazw (po numerze) do górnej tabeli
$adNamesJoinedByNumber = [];
foreach ($adAllByNumber as $num => $list) {
    $names = [];
    foreach ($list as $row) {
        $n = trim((string)($row['displayName'] ?? ''));
        if ($n !== '') $names[] = $n;
    }
    // usuń duplikaty nazw (na wszelki wypadek)
    $names = array_values(array_unique($names));
    $adNamesJoinedByNumber[$num] = implode(', ', $names);
}

/**
 * 2) DB phones
 */
$dbPhones = $pdo->query("
  SELECT id, number
  FROM phones
  ORDER BY number ASC
")->fetchAll();

// Mapy DB phones
$dbPhoneIdByNumber = [];
foreach ($dbPhones as $p) {
    $num = trim((string)($p['number'] ?? ''));
    $pid = (int)($p['id'] ?? 0);
    if ($num !== '' && $pid > 0) {
        $dbPhoneIdByNumber[$num] = $pid;
    }
}

/**
 * 3) DB assignments: aktywne override
 */
$assignments = $pdo->query("
  SELECT
    p.id AS phone_id,
    p.number,
    pa.id AS assignment_id,
    de.display_name AS label_name
  FROM phones p
  JOIN phone_assignments pa
    ON pa.phone_id = p.id AND pa.valid_to IS NULL
  JOIN directory_entries de
    ON de.id = pa.directory_entry_id
")->fetchAll();

// Mapy assignments po phone_id
$assignByPhoneId = [];
foreach ($assignments as $a) {
    $pid = (int)($a['phone_id'] ?? 0);
    if ($pid <= 0) continue;

    $assignByPhoneId[$pid] = [
        'assignment_id' => (int)($a['assignment_id'] ?? 0),
        'label_name' => (string)($a['label_name'] ?? ''),
        'number' => trim((string)($a['number'] ?? '')),
    ];
}

/**
 * 4) Budujemy listę wynikową:
 * - start: numery z AD (unikalne po number)
 * - plus: numery z DB phones, których nie było w AD
 * - apply override z DB (assignment) jeśli istnieje dla numeru (po phone_id)
 */
$results = [];

// AD -> results (unikalne)
foreach ($adFirstByNumber as $num => $u) {
    $results[$num] = [
        'number' => $num,
        'name' => (string)($adNamesJoinedByNumber[$num] ?? (string)($u['displayName'] ?? '')),
        'phone_id' => $dbPhoneIdByNumber[$num] ?? 0, // jeśli jest w DB, dopnij od razu
        'assignment_id' => 0,
        'override_label' => '',
    ];

    // jeśli jest override w DB, ma wygrać
    $pid = $results[$num]['phone_id'];
    if ($pid > 0 && isset($assignByPhoneId[$pid])) {
        $results[$num]['assignment_id'] = (int)$assignByPhoneId[$pid]['assignment_id'];
        $results[$num]['override_label'] = (string)$assignByPhoneId[$pid]['label_name'];
        $results[$num]['name'] = (string)$assignByPhoneId[$pid]['label_name'];
    }
}

// DB phones -> results (dodajemy tylko jeśli nie ma w AD)
foreach ($dbPhones as $p) {
    $pid = (int)($p['id'] ?? 0);
    $num = trim((string)($p['number'] ?? ''));
    if ($num === '' || $pid <= 0) continue;

    if (!isset($results[$num])) {
        $results[$num] = [
            'number' => $num,
            'name' => '',
            'phone_id' => $pid,
            'assignment_id' => 0,
            'override_label' => '',
        ];
    }

    // jeśli jest override w DB, ma wygrać
    if (isset($assignByPhoneId[$pid])) {
        $results[$num]['assignment_id'] = (int)$assignByPhoneId[$pid]['assignment_id'];
        $results[$num]['override_label'] = (string)$assignByPhoneId[$pid]['label_name'];
        $results[$num]['name'] = (string)$assignByPhoneId[$pid]['label_name'];
    }
}

// Sortowanie po numerze
ksort($results, SORT_NATURAL);
?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h1 class="h3 mb-0">Książka telefoniczna</h1>
</div>

<?php if ($error): ?>
  <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($success): ?>
  <div class="alert alert-success"><?= e($success) ?></div>
<?php endif; ?>

<div class="card mb-4">
  <div class="table-responsive">
    <table class="table table-striped mb-0 align-middle">
      <thead>
        <tr>
          <th style="width: 220px;">Numer telefonu</th>
          <th>Nazwa</th>
          <th class="text-nowrap">Akcje</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($results as $r): ?>
          <?php
            $name = (string)($r['name'] ?? '');
            $hasName = trim($name) !== '';
            $trClass = $hasName ? '' : 'table-warning';

            $phoneId = (int)($r['phone_id'] ?? 0);
            $assignmentId = (int)($r['assignment_id'] ?? 0);

            $actionText = ($assignmentId > 0) ? 'Zmień etykietę' : ($hasName ? 'Przypisz etykietę' : 'Dodaj etykietę');
          ?>
          <tr class="<?= $trClass ?>">
            <td><?= e((string)$r['number']) ?></td>
            <td><?= e($name) ?></td>

            <td class="text-nowrap">
              <div class="d-flex align-items-center gap-3">

                <a href="#"
                   class="link-primary js-assign"
                   data-bs-toggle="modal"
                   data-bs-target="#assignModal"
                   data-phone-id="<?= $phoneId ?>"
                   data-phone-number="<?= e((string)$r['number']) ?>"
                   data-current-label="<?= e((string)($r['override_label'] ?? '')) ?>">
                   <?= e($actionText) ?>
                </a>

                <?php if ($assignmentId > 0): ?>
                  <form method="post"
                        class="m-0"
                        onsubmit="return confirm('Usunąć przypisanie (override) dla numeru <?= e((string)$r['number']) ?>?');">
                    <input type="hidden" name="action" value="delete_assignment">
                    <input type="hidden" name="assignment_id" value="<?= $assignmentId ?>">
                    <button type="submit" class="btn btn-link link-danger p-0 text-decoration-none">
                      Usuń etykietę
                    </button>
                  </form>
                <?php endif; ?>

              </div>
            </td>
          </tr>
        <?php endforeach; ?>

        <?php if (!$results): ?>
          <tr>
            <td colspan="3" class="text-muted">Brak danych do wyświetlenia.</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<form method="post" class="mb-3">
  <input type="hidden" name="action" value="generate_xml">
  <button type="submit" class="btn btn-primary w-100">Generuj listy XML</button>
</form>

<?php if (!empty($adDuplicates)): ?>
  <div class="card mb-4 border-warning">
    <div class="card-header">
      <span class="fw-semibold">Ostrzeżenie: duplikaty numerów w AD</span>
      <span class="text-muted small ms-2">(ten sam numer przypisany do wielu użytkowników)</span>
    </div>

    <div class="table-responsive">
      <table class="table mb-0 align-middle">
        <thead>
          <tr>
            <th style="width: 220px;">Numer telefonu</th>
            <th>Użytkownicy</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($adDuplicates as $num => $list): ?>
            <tr class="table-warning">
              <td class="fw-semibold"><?= e((string)$num) ?></td>
              <td>
                <ul class="mb-0 ps-3">
                  <?php foreach ($list as $u): ?>
                    <li>
                      <?= e((string)($u['displayName'] ?? '')) ?>
                      <span class="text-muted small">(<?= e((string)($u['sAMAccountName'] ?? '')) ?>)</span>
                      <div class="text-muted small"><?= e((string)($u['dn'] ?? '')) ?></div>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<!-- MODAL: Przypisz etykietę -->
<div class="modal fade" id="assignModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">

      <form method="post">
        <input type="hidden" name="action" value="assign_label">
        <input type="hidden" name="phone_id" id="modalPhoneId" value="">
        <input type="hidden" name="phone_number" id="modalPhoneNumberHidden" value="">

        <div class="modal-header">
          <h5 class="modal-title">Przypisz etykietę</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>

        <div class="modal-body">
          <div class="mb-2">
            <div class="small text-muted">Numer</div>
            <div class="fw-semibold" id="modalPhoneNumber">—</div>
          </div>

          <div class="mb-3">
            <div class="small text-muted">Aktualnie (override z DB)</div>
            <div id="modalCurrentLabel" class="text-muted">—</div>
          </div>

          <div class="mb-3">
            <label class="form-label">Wybierz etykietę</label>
            <select class="form-select" name="label_id" id="modalLabelSelect" required>
              <option value="" selected disabled>— wybierz —</option>
              <?php foreach ($labels as $l): ?>
                <option value="<?= (int)$l['id'] ?>"><?= e((string)$l['display_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <?php if (!$labels): ?>
            <div class="alert alert-warning mb-0">
              Brak etykiet w bazie. Dodaj je w sekcji „Etykiety”.
            </div>
          <?php endif; ?>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-primary" <?= !$labels ? 'disabled' : '' ?>>Zapisz</button>
        </div>

      </form>

    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.js-assign').forEach((a) => {
    a.addEventListener('click', () => {
      const phoneId = a.getAttribute('data-phone-id') || '';
      const phoneNumber = a.getAttribute('data-phone-number') || '—';
      const currentLabel = (a.getAttribute('data-current-label') || '').trim();

      const modalPhoneId = document.getElementById('modalPhoneId');
      const modalPhoneNumber = document.getElementById('modalPhoneNumber');
      const modalPhoneNumberHidden = document.getElementById('modalPhoneNumberHidden');
      const modalCurrentLabel = document.getElementById('modalCurrentLabel');

      if (modalPhoneId) modalPhoneId.value = phoneId;
      if (modalPhoneNumber) modalPhoneNumber.textContent = phoneNumber;
      if (modalPhoneNumberHidden) modalPhoneNumberHidden.value = phoneNumber;
      if (modalCurrentLabel) modalCurrentLabel.textContent = currentLabel !== '' ? currentLabel : '';
    });
  });

  const modal = document.getElementById('assignModal');
  if (modal) {
    modal.addEventListener('show.bs.modal', () => {
      const select = document.getElementById('modalLabelSelect');
      if (select) select.value = '';
    });
  }
});
</script>