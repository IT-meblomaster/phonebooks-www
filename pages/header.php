<?php
// pages/header.php
?>
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($config['app']['name'] ?? 'Meblomaster') ?></title>

  <!-- Bootstrap 5 (CDN) -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

  <link href="<?= e(($config['app']['base_url'] ?? '') . '/css/style.css') ?>" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg bg-body-tertiary border-bottom">
  <div class="container">
    <a class="navbar-brand" href="index.php"><?= e($config['app']['name'] ?? 'Meblomaster') ?></a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="nav">
      <ul class="navbar-nav me-auto">
        <li class="nav-item">
          <a class="nav-link <?= (current_page()==='dashboard'?'active':'') ?>" href="index.php?page=dashboard">Książka telefoniczna</a>
        </li>

        <?php
          $resources_pages = ['labels','phone_numbers','device_types','devices'];
          $is_resources = in_array(current_page(), $resources_pages, true);
        ?>

        <?php if (is_logged_in()): ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle <?= $is_resources ? 'active' : '' ?>" href="#" role="button" data-bs-toggle="dropdown">
              Zasoby
            </a>
            <ul class="dropdown-menu">
              <li>
                <a class="dropdown-item <?= (current_page()==='labels'?'active':'') ?>" href="index.php?page=labels">
                  Etykiety
                </a>
              </li>
              <li>
                <a class="dropdown-item <?= (current_page()==='phone_numbers'?'active':'') ?>" href="index.php?page=phone_numbers">
                  Numery telefonów
                </a>
              </li>
              <li>
                <a class="dropdown-item <?= (current_page()==='device_types'?'active':'') ?>" href="index.php?page=device_types">
                  Typy urządzeń
                </a>
              </li>
              <li>
                <a class="dropdown-item <?= (current_page()==='devices'?'active':'') ?>" href="index.php?page=devices">
                  Urządzenia
                </a>
              </li>
            </ul>
          </li>
          <li class="nav-item"><a class="nav-link <?= (current_page()==='phonebook'?'active':'') ?>" href="index.php?page=phonebook">Przypisywanie etykiet</a></li>
        <?php endif; ?>
      </ul>
      <ul class="navbar-nav">
        <?php if (is_logged_in()): ?>
          <li class="nav-item"><span class="navbar-text me-3">Zalogowany</span></li>
          <li class="nav-item"><a class="btn btn-outline-secondary btn-sm" href="index.php?page=logout">Wyloguj</a></li>
        <?php else: ?>
          <li class="nav-item"><a class="btn btn-primary btn-sm" href="index.php?page=login">Zaloguj</a></li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>

<main class="container py-4">
