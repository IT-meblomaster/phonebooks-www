<?php
// pages/labels.php
require_login($config);

$error = null;

// Czy edytujemy?
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editing = $editId > 0;

// Obsługa POST: dodaj / edytuj / usuń
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add_label') {
        $display = trim((string)($_POST['display_name'] ?? ''));
        $notes   = trim((string)($_POST['notes'] ?? ''));

        if ($display === '') {
            $error = 'Nazwa etykiety jest wymagana.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO directory_entries (display_name, notes)
                    VALUES (:dn, :notes)
                ");
                $stmt->execute([
                    ':dn' => $display,
                    ':notes' => ($notes !== '' ? $notes : null),
                ]);

                header('Location: index.php?page=labels');
                exit;
            } catch (PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                    $error = 'Taka etykieta już istnieje.';
                } else {
                    $error = 'Błąd zapisu do bazy.';
                }
            }
        }
    }

    if ($action === 'update_label') {
        $id      = (int)($_POST['id'] ?? 0);
        $display = trim((string)($_POST['display_name'] ?? ''));
        $notes   = trim((string)($_POST['notes'] ?? ''));

        if ($id <= 0) {
            $error = 'Nieprawidłowe ID etykiety.';
        } elseif ($display === '') {
            $error = 'Nazwa etykiety jest wymagana.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE directory_entries
                    SET display_name = :dn, notes = :notes
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':dn' => $display,
                    ':notes' => ($notes !== '' ? $notes : null),
                    ':id' => $id,
                ]);

                header('Location: index.php?page=labels');
                exit;
            } catch (PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                    $error = 'Taka etykieta już istnieje.';
                } else {
                    $error = 'Błąd zapisu do bazy.';
                }
            }
        }
    }

    if ($action === 'delete_label') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            $error = 'Nieprawidłowe ID etykiety.';
        } else {
            try {
                // Jeśli kiedyś podłączysz przypisania z FK RESTRICT,
                // to tu wyleci wyjątek - i pokażemy sensowny komunikat.
                $stmt = $pdo->prepare("DELETE FROM directory_entries WHERE id = :id");
                $stmt->execute([':id' => $id]);

                header('Location: index.php?page=labels');
                exit;
            } catch (PDOException $e) {
                // Najczęściej: nie da się usunąć bo jest używana w innych tabelach (FK)
                $error = 'Nie można usunąć etykiety (może być używana w przypisaniach).';
            }
        }
    }
}

// Pobranie listy etykiet
$rows = $pdo->query("
    SELECT id, display_name, notes
    FROM directory_entries
    ORDER BY display_name ASC
")->fetchAll();

// Dane do formularza (dodaj lub edytuj)
$formMode = 'add';
$formTitle = 'Dodaj nową etykietę';
$submitText = 'Zapisz';
$initial = ['display_name' => '', 'notes' => ''];

if ($editing) {
    $stmt = $pdo->prepare("SELECT id, display_name, notes FROM directory_entries WHERE id = :id");
    $stmt->execute([':id' => $editId]);
    $row = $stmt->fetch();

    if ($row) {
        $formMode = 'edit';
        $formTitle = 'Edytuj etykietę';
        $submitText = 'Zapisz zmiany';
        $initial['display_name'] = (string)$row['display_name'];
        $initial['notes'] = (string)($row['notes'] ?? '');
    } else {
        // jak ktoś poda złe ID w URL
        $editing = false;
        $editId = 0;
    }
}

// Jeśli był błąd przy submit, pokaż formularz i zachowaj wpisane dane
$showForm = ($error !== null) || $editing;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error !== null) {
    $initial['display_name'] = (string)($_POST['display_name'] ?? $initial['display_name']);
    $initial['notes'] = (string)($_POST['notes'] ?? $initial['notes']);
}
?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h1 class="h3 mb-0">Etykiety</h1>
</div>

<?php if ($error): ?>
  <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<div class="card">
  <div class="card-header">Lista etykiet</div>

  <div class="table-responsive">
    <table class="table table-striped mb-0 align-middle">
      <thead>
        <tr>
          <th style="width: 90px;">ID</th>
          <th>Etykieta</th>
          <th>Komentarz</th>
          <th style="width: 170px;">Akcje</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= (int)$r['id'] ?></td>
            <td><?= e((string)$r['display_name']) ?></td>
            <td class="text-muted"><?= e((string)($r['notes'] ?? '')) ?></td>
            <td>
              <div class="d-flex gap-2">
                <a class="btn btn-sm btn-outline-primary"
                   href="index.php?page=labels&edit=<?= (int)$r['id'] ?>#form">
                  Edytuj
                </a>

                <form method="post" class="m-0"
                      onsubmit="return confirm('Na pewno usunąć etykietę: <?= e((string)$r['display_name']) ?>?');">
                  <input type="hidden" name="action" value="delete_label">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
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
            <td colspan="4" class="text-muted">Brak etykiet.</td>
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
              data-bs-target="#labelForm"
              aria-expanded="<?= $showForm ? 'true' : 'false' ?>"
              aria-controls="labelForm">
        Dodaj nową
      </button>
    <?php else: ?>
      <a class="btn btn-outline-secondary" href="index.php?page=labels">Anuluj edycję</a>
    <?php endif; ?>

    <div class="collapse <?= $showForm ? 'show' : '' ?> mt-3" id="labelForm">
      <div class="card card-body" id="form">
        <h2 class="h5 mb-3"><?= e($formTitle) ?></h2>

        <form method="post" class="row g-3">
          <?php if ($editing): ?>
            <input type="hidden" name="action" value="update_label">
            <input type="hidden" name="id" value="<?= (int)$editId ?>">
          <?php else: ?>
            <input type="hidden" name="action" value="add_label">
          <?php endif; ?>

          <div class="col-12">
            <label class="form-label">Nazwa etykiety</label>
            <input class="form-control"
                   name="display_name"
                   required
                   value="<?= e($initial['display_name']) ?>">
          </div>

          <div class="col-12">
            <label class="form-label">Komentarz</label>
            <textarea class="form-control"
                      name="notes"
                      rows="3"><?= e($initial['notes']) ?></textarea>
          </div>

          <div class="col-12">
            <button class="btn btn-success" type="submit"><?= e($submitText) ?></button>
          </div>
        </form>

      </div>
    </div>

  </div>
</div>

<?php if ($editing): ?>
<script>
  // jeśli jesteśmy w trybie edit, zapewnij że formularz jest rozwinięty po załadowaniu
  document.addEventListener('DOMContentLoaded', () => {
    const el = document.getElementById('labelForm');
    if (el) {
      const c = bootstrap.Collapse.getOrCreateInstance(el, {toggle: false});
      c.show();
    }
  });
</script>
<?php endif; ?>
