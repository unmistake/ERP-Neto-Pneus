<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
authRequireActionLogin();
require_once __DIR__ . '/../includes/fiscal_focus.php';

$saleId = (int) ($_GET['sale_id'] ?? $_POST['sale_id'] ?? 0);

if ($saleId <= 0) {
    flash('error', 'Venda invalida para download da NF-e.');
    redirect('../index.php?page=pdv');
}

try {
    $pdf = fiscalDownloadLatestNfeDanfe($pdo, $saleId);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: ' . ($pdf['content_type'] ?: 'application/pdf'));
    header('Content-Disposition: attachment; filename="' . $pdf['filename'] . '"');
    header('Content-Length: ' . strlen((string) $pdf['content']));
    echo $pdf['content'];
    exit;
} catch (Throwable $e) {
    flash('error', 'Erro ao baixar PDF da NF-e: ' . $e->getMessage());
    redirect('../index.php?page=pdv');
}

