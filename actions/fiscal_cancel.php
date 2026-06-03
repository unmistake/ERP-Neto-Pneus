<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/fiscal_focus.php';

$saleId = (int) ($_POST['sale_id'] ?? 0);
$returnPage = trim((string) ($_POST['return_page'] ?? 'pdv'));
$justification = trim((string) ($_POST['justification'] ?? ''));

if ($saleId <= 0) {
    flash('error', 'Venda invalida para cancelamento de NF-e.');
    redirect('../index.php?page=' . $returnPage);
}

try {
    $result = fiscalCancelLatestNfeBySale($pdo, $saleId, $justification);
    $msg = trim((string) ($result['message'] ?? ''));
    flash('success', 'NF-e da venda #' . $saleId . ' cancelada com sucesso. ' . ($msg !== '' ? 'Focus: ' . $msg : ''));
} catch (Throwable $e) {
    flash('error', 'Erro ao cancelar NF-e da venda #' . $saleId . ': ' . $e->getMessage());
}

redirect('../index.php?page=' . $returnPage);
