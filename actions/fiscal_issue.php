<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/fiscal_focus.php';

$saleId = (int) ($_POST['sale_id'] ?? 0);
$returnPage = trim((string) ($_POST['return_page'] ?? 'pdv'));

if ($saleId <= 0) {
    flash('error', 'Venda invalida para emissao fiscal.');
    redirect('../index.php?page=' . $returnPage);
}

try {
    $result = fiscalIssueNfeBySale($pdo, $saleId);

    if (($result['sale_fiscal_status'] ?? 'pending') === 'pending') {
        try {
            $result = fiscalSyncLatestNfeBySale($pdo, $saleId);
        } catch (Throwable $syncError) {
            // A Focus pode ainda estar processando; neste caso mantemos pending.
        }
    }

    $status = (string) ($result['status'] ?? 'processando');
    $saleFiscalStatus = (string) ($result['sale_fiscal_status'] ?? 'pending');
    $msg = trim((string) ($result['message'] ?? ''));
    $httpStatus = (int) ($result['focus_http_status'] ?? 0);
    if ($saleFiscalStatus === 'failed') {
        $upd = $pdo->prepare("UPDATE sales SET fiscal_document_type = 'nfe', fiscal_status = 'failed' WHERE id = ?");
        $upd->execute([$saleId]);
        flash('error', 'Falha ao emitir NF-e da venda #' . $saleId . ' (HTTP ' . $httpStatus . '): ' . ($msg !== '' ? $msg : 'Erro de validacao na Focus.'));
    } elseif ($saleFiscalStatus === 'issued') {
        $upd = $pdo->prepare("UPDATE sales SET fiscal_document_type = 'nfe', fiscal_status = 'issued' WHERE id = ?");
        $upd->execute([$saleId]);
        flash('success', 'NF-e autorizada para venda #' . $saleId . '. ' . ($msg !== '' ? 'Focus: ' . $msg : ''));
    } else {
        $upd = $pdo->prepare("UPDATE sales SET fiscal_document_type = 'nfe', fiscal_status = 'pending' WHERE id = ?");
        $upd->execute([$saleId]);
        flash('success', 'Emissao de NF-e iniciada para venda #' . $saleId . ' (status: ' . $status . '). ' . ($msg !== '' ? 'Focus: ' . $msg : ''));
    }
} catch (Throwable $e) {
    flash('error', 'Erro ao emitir nota: ' . $e->getMessage());
}

redirect('../index.php?page=' . $returnPage);
