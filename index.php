<?php

declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$page = $_GET['page'] ?? 'dashboard';
$allowedPages = ['dashboard', 'estoque', 'pdv', 'pdv_mobile', 'financeiro', 'crm', 'mapa', 'nfe_entrada', 'login'];

if (!in_array($page, $allowedPages, true)) {
    $page = 'dashboard';
}

authEnsureSchema($pdo);

if ($page === 'login') {
    require __DIR__ . '/pages/login.php';
    exit;
}

$publicPages = ['pdv', 'pdv_mobile'];
if (!in_array($page, $publicPages, true)) {
    authRequireLogin($_SERVER['REQUEST_URI'] ?? 'index.php');
}

ob_start();
require __DIR__ . '/pages/' . $page . '.php';
$content = ob_get_clean();

$title = 'ERP Pneus - ' . ucfirst($page);
require __DIR__ . '/includes/layout.php';
