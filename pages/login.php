<?php
// pages/login.php

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Podaj login i hasło.';
    } else {
        if (login($pdo, $config, $username, $password)) {
            header('Location: index.php?page=dashboard');
            exit;
        }
        $error = 'Nieprawidłowy login lub hasło.';
    }
}
?>

<div class="row justify-content-center">
  <div class="col-12 col-md-6 col-lg-4">
    <h1 class="h4 mb-3">Logowanie</h1>

    <?php if ($error): ?>
      <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" class="card card-body">
      <div class="mb-3">
        <label class="form-label">Login</label>
        <input class="form-control" name="username" autocomplete="username" required>
      </div>
      <div class="mb-3">
        <label class="form-label">Hasło</label>
        <input class="form-control" type="password" name="password" autocomplete="current-password" required>
      </div>
      <button class="btn btn-primary w-100" type="submit">Zaloguj</button>
    </form>
  </div>
</div>
