<?php
// index.php

declare(strict_types=1);

$config = require __DIR__ . '/config/config.php';

require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/helpers.php';

start_session($config);

$pdo = db($config);

$page = current_page();

// Publiczne strony:
$publicPages = ['login','dashboard'];

// Mapowanie nazw na pliki
$pageFile = __DIR__ . '/pages/' . $page . '.php';

if (!in_array($page, $publicPages, true) && $page !== 'logout') {
    // Wymuś logowanie na wszystko poza login/logout
    require_login($config);
}

if (!is_file($pageFile)) {
    http_response_code(404);
    $page = 'dashboard';
    $pageFile = __DIR__ . '/pages/dashboard.php';
}

require __DIR__ . '/pages/header.php';
require $pageFile;
require __DIR__ . '/pages/footer.php';
