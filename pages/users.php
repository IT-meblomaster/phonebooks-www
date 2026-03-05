<?php
// pages/users.php
require_login($config);

$error = null;

// Dodawanie local user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_local') {
    $display = trim((string)($_POST['display_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $dept = trim((string)($_POST['department'] ?? ''));
    $title = trim((string)($_POST['title'] ?? ''));

    if ($display === '') {
        $error = 'Display name jest wymagane.';
    } else {
        $externalId = bin2hex(random_bytes(16)); // UUID-like token

        $stmt = $pdo->prepare("
            INSERT INTO directory_users (type, external_id, display_name, email, department, title, is_active)
            VALUES ('local', :eid, :dn, :em, :dep, :ti, 1)
        ");
        $stmt->execute([
            ':eid' => $externalId,
            ':dn' => $display,
            ':em' => ($email !== '' ? $email : null),
            ':dep' => ($dept !== '' ? $dept : null),
            ':ti' => ($title !== '' ? $title : null),
        ]);

        header('Location: index.php?page=users');
        exit;
    }
}

// Lista użytkowników
$rows = $pdo->query("
    SELECT id, type, external_id, display_name, email, department, title, is_active, updated_at
    FROM directory_users
    ORDER BY display_name ASC
")->fetchAll();
?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h1 class="h3 mb-0">Users</h1>
</div>

<?php if ($error): ?>
  <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<div class="card mb-4">
  <div class="card-header">Dodaj local user</div>
  <div class="card-body">
    <form method="post" class="row g-3">
      <input type="hidden" name="action" value="add_local">
      <div class="col-12 col-md-6">
        <label class="form-label">Display name</label>
        <input class="form-control" name="display_name" required>
      </div>
      <div class="col-12 col-md-6">
        <label class="form-label">Email</label>
        <input class="form-control" name="email" type="email">
      </div>
      <div class="col-12 col-md-6">
        <label class="form-label">Department</label>
        <input class="form-control" name="department">
      </div>
      <div class="col-12 col-md-6">
        <label class="form-label">Title</label>
        <input class="form-control" name="title">
      </div>
      <div class="col-12">
        <button class="btn btn-primary" type="submit">Dodaj</button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">Lista użytkowników</div>
  <div class="table-responsive">
    <table class="table table-striped mb-0 align-middle">
      <thead>
        <tr>
          <th>ID</th>
          <th>Type</th>
          <th>Display name</th>
          <th>Email</th>
          <th>Department</th>
          <th>Title</th>
          <th>Active</th>
          <th>Updated</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= (int)$r['id'] ?></td>
            <td><span class="badge text-bg-<?= $r['type']==='ad' ? 'info' : 'secondary' ?>"><?= e($r['type']) ?></span></td>
            <td><?= e((string)$r['display_name']) ?></td>
            <td><?= e((string)($r['email'] ?? '')) ?></td>
            <td><?= e((string)($r['department'] ?? '')) ?></td>
            <td><?= e((string)($r['title'] ?? '')) ?></td>
            <td><?= ((int)$r['is_active'] === 1) ? 'yes' : 'no' ?></td>
            <td class="text-muted"><?= e((string)$r['updated_at']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="8" class="text-muted">Brak użytkowników.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
