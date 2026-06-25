<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
authRequireActionLogin();
require_once __DIR__ . '/../includes/fiscal_focus.php';
require_once __DIR__ . '/../includes/whatsapp_service.php';

$saleId = (int) ($_POST['sale_id'] ?? 0);
$returnPage = trim((string) ($_POST['return_page'] ?? 'pdv'));

if ($saleId <= 0) {
    flash('error', 'Venda invalida para sincronizar NF-e.');
    redirect('../index.php?page=' . $returnPage);
}

try {
    $result = fiscalSyncLatestNfeBySale($pdo, $saleId);
    $saleFiscalStatus = (string) ($result['sale_fiscal_status'] ?? 'pending');
    $msg = trim((string) ($result['message'] ?? ''));

    if ($saleFiscalStatus === 'failed') {
        flash('error', 'NF-e da venda #' . $saleId . ' rejeitada: ' . ($msg !== '' ? $msg : 'Falha fiscal.'));
    } elseif ($saleFiscalStatus === 'issued') {
        $whatsapp = whatsappTrySendNfePdf($pdo, $saleId);
        $whatsappMsg = '';
        if (($whatsapp['status'] ?? '') === 'sent') {
            $whatsappMsg = ' PDF enviado no WhatsApp do cliente.';
        } elseif (($whatsapp['status'] ?? '') === 'failed') {
            $whatsappMsg = ' WhatsApp nao enviado: ' . (string) ($whatsapp['message'] ?? 'erro desconhecido');
        }
        flash('success', 'NF-e da venda #' . $saleId . ' autorizada. ' . ($msg !== '' ? 'Focus: ' . $msg : '') . $whatsappMsg);
    } else {
        flash('success', 'NF-e da venda #' . $saleId . ' ainda esta em processamento. ' . ($msg !== '' ? 'Focus: ' . $msg : ''));
    }
} catch (Throwable $e) {
    flash('error', 'Erro ao sincronizar NF-e: ' . $e->getMessage());
}

redirect('../index.php?page=' . $returnPage);
