<?php

declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

$page = $_GET['page'] ?? 'dashboard';
$allowedPages = ['dashboard', 'estoque', 'pdv', 'pdv_mobile', 'financeiro', 'crm'];

if (!in_array($page, $allowedPages, true)) {
    $page = 'dashboard';
}

ob_start();
require __DIR__ . '/pages/' . $page . '.php';
$content = ob_get_clean();

$title = 'ERP Pneus - ' . ucfirst($page);
require __DIR__ . '/includes/layout.php';
