<?php
// inc/auth.php

declare(strict_types=1);

function start_session(array $config): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name($config['security']['session_name'] ?? 'phonebook_sess');

    // Bezpieczne ciasteczka sesji
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']),
        'samesite' => 'Strict',
        'path' => '/',
    ]);

    session_start();
}

function is_logged_in(): bool
{
    return !empty($_SESSION['admin_id']);
}

function require_login(array $config): void
{
    start_session($config);
    if (!is_logged_in()) {
        header('Location: index.php?page=login');
        exit;
    }
}

function login(PDO $pdo, array $config, string $username, string $password): bool
{
    start_session($config);

    $stmt = $pdo->prepare("SELECT id, password_hash, role, is_active FROM admins WHERE username = :u LIMIT 1");
    $stmt->execute([':u' => $username]);
    $row = $stmt->fetch();

    if (!$row || (int)$row['is_active'] !== 1) {
        return false;
    }

    if (!password_verify($password, $row['password_hash'])) {
        return false;
    }

    // Regeneracja ID sesji po logowaniu
    session_regenerate_id(true);

    $_SESSION['admin_id'] = (int)$row['id'];
    $_SESSION['admin_role'] = (string)$row['role'];

    // update last_login_at
    $upd = $pdo->prepare("UPDATE admins SET last_login_at = NOW() WHERE id = :id");
    $upd->execute([':id' => (int)$row['id']]);

    return true;
}

function logout(array $config): void
{
    start_session($config);
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
    }
    session_destroy();
}
