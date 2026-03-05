<?php
// pages/device_types.php
require_login($config);

$error = null;

$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editing = $editId > 0;

// OBSŁUGA POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add_type') {
        $manufacturer = trim((string)($_POST['manufacturer'] ?? ''));
        $model        = trim((string)($_POST['model'] ?? ''));
        $count        = (int)($_POST['phone_accounts_count'] ?? 1);

        if ($manufacturer === '' || $model === '') {
            $error = 'Producent i model są wymagane.';
        } elseif ($count <= 0) {
            $error = 'Liczba kont/numerów musi być większa od 0.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO equipment_types
                      (manufacturer, model, phone_accounts_count, created_at)
                    VALUES
                      (:manufacturer, :model, :cnt, NOW())
                ");
                $stmt->execute([
                    ':manufacturer' => $manufacturer,
                    ':model' => $model,
                    ':cnt' => $count,
                ]);

                header('Location: index.php?page=device_types');
                exit;
            } catch (PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                    $error = 'Taki typ (producent + model) już istnieje.';
                } else {
                    $error = 'Błąd zapisu do bazy.';
                }
            }
        }
    }

    if ($action === 'update_type') {
        $id           = (int)($_POST['id'] ?? 0);
        $manufacturer = trim((string)($_POST['manufacturer'] ?? ''));
        $model        = trim((string)($_POST['model'] ?? ''));
        $count        = (int)($_POST['phone_accounts_count'] ?? 1);

        if ($id <= 0 || $manufacturer === '' || $model === '') {
            $error = 'Nieprawidłowe dane.';
        } elseif ($count <= 0) {
            $error = 'Liczba kont/numerów musi być większa od 0.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE equipment_types
                    SET manufacturer = :manufacturer,
                        model = :model,
                        phone_accounts_count = :cnt,
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':manufacturer' => $manufacturer,
                    ':model' => $model,
                    ':cnt' => $count,
                    ':id' => $id,
                ]);

                header('Location: index.php?page=device_types');
                exit;
            } catch (PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                    $error = 'Taki typ (producent + model) już istnieje.';
                } else {
                    $error = 'Błąd aktualizacji.';
                }
            }
        }
    }

    if ($action === 'delete_type') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM equipment_types WHERE id = :id");
                $stmt->execute([':id' => $id]);

                header('Location: index.php?page=device_types');
                exit;
            } catch (PDOException $e) {
                $error = 'Nie można usunąć typu (może być używany przez urządzenia).';
            }
        }
    }
}

// Pobierz listę
$rows = $pdo->query("
    SELECT id, manufacturer, model, phone_accounts_count
    FROM equipment_types
    ORDER BY manufacturer ASC, model ASC
")->fetchAll();

// Dane formularza
$formTitle = 'Dodaj nowy typ sprzętu';
$submitText = 'Zapisz';
$initialManufacturer = '';
$initialModel = '';
$initialCount = 1;

if ($editing) {
    $stmt = $pdo->prepare("
        SELECT id, manufacturer, model, phone_accounts_count
        FROM equipment_types
        WHERE id = :id
    ");
    $stmt->execute([':id' => $editId]);
    $row = $stmt->fetch();

    if ($row) {
        $formTitle = 'Edytuj typ sprzętu';
        $submitText = 'Zapisz zmiany';
        $initialManufacturer = (string)$row['manufacturer'];
        $initialModel = (string)$row['model'];
        $initialCount = (int)$row['phone_accounts_count'];
    } else {
        $editing = false;
    }
}

$showForm = ($error !== null) || $editing;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error !== null) {
    $initialManufacturer = (string)($_POST['manufacturer'] ?? $initialManufacturer);
    $initialModel = (string)($_POST['model'] ?? $initialModel);
    $initialCount = (int)($_POST['phone_accounts_count'] ?? $initialCount);
}
?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h1 class="h3 mb-0">Typy sprzętu</h1>
</div>

<?php if ($error): ?>
  <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<div class="card">
  <div class="card-header">Lista typów</div>

  <div class="table-responsive">
    <table class="table table-striped mb-0 align-middle">
      <thead>
        <tr>
          <th style="width: 90px;">ID</th>
          <th>Producent</th>
          <th>Model</th>
          <th style="width: 200px;">Liczba kont/numerów</th>
          <th style="width: 170px;">Akcje</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= (int)$r['id'] ?></td>
            <td><?= e((string)$r['manufacturer']) ?></td>
            <td><?= e((string)$r['model']) ?></td>
            <td><?= (int)$r['phone_accounts_count'] ?></td>
            <td>
              <div class="d-flex gap-2">
                <a class="btn btn-sm btn-outline-primary"
                   href="index.php?page=device_types&edit=<?= (int)$r['id'] ?>#form">
                  Edytuj
                </a>

                <form method="post" class="m-0"
                      onsubmit="return confirm('Na pewno usunąć typ: <?= e((string)$r['manufacturer']) ?> <?= e((string)$r['model']) ?>?');">
                  <input type="hidden" name="action" value="delete_type">
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
            <td colspan="5" class="text-muted">Brak typów sprzętu.</td>
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
              data-bs-target="#typeForm"
              aria-expanded="<?= $showForm ? 'true' : 'false' ?>">
        Dodaj nowy
      </button>
    <?php else: ?>
      <a class="btn btn-outline-secondary" href="index.php?page=device_types">Anuluj edycję</a>
    <?php endif; ?>

    <div class="collapse <?= $showForm ? 'show' : '' ?> mt-3" id="typeForm">
      <div class="card card-body" id="form">
        <h2 class="h5 mb-3"><?= e($formTitle) ?></h2>

        <form method="post" class="row g-3">
          <?php if ($editing): ?>
            <input type="hidden" name="action" value="update_type">
            <input type="hidden" name="id" value="<?= (int)$editId ?>">
          <?php else: ?>
            <input type="hidden" name="action" value="add_type">
          <?php endif; ?>

          <div class="col-12 col-md-6">
            <label class="form-label">Producent</label>
            <input class="form-control"
                   name="manufacturer"
                   required
                   value="<?= e($initialManufacturer) ?>">
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label">Model</label>
            <input class="form-control"
                   name="model"
                   required
                   value="<?= e($initialModel) ?>">
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">Liczba kont/numerów</label>
            <input class="form-control"
                   type="number"
                   min="1"
                   name="phone_accounts_count"
                   required
                   value="<?= (int)$initialCount ?>">
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