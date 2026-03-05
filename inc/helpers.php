<?php
// inc/helpers.php

declare(strict_types=1);

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function current_page(): string
{
    $page = $_GET['page'] ?? 'dashboard';
    $page = preg_replace('/[^a-z0-9_\-]/i', '', (string)$page);
    return $page ?: 'dashboard';
}
