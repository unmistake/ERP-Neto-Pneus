<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/inbound_nfe_focus.php';

inboundNfeEnsureSchema($pdo);

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    flash('error', 'NF-e recebida invalida para download.');
    redirect('../index.php?page=nfe_entrada');
}

$stmt = $pdo->prepare('SELECT id, access_key, number FROM inbound_nfes WHERE id = ?');
$stmt->execute([$id]);
$note = $stmt->fetch();
if (!$note) {
    flash('error', 'NF-e recebida nao encontrada.');
    redirect('../index.php?page=nfe_entrada');
}

try {
    $pdf = inboundNfeFocusDownloadPdf((string) $note['access_key']);
    $filename = 'nfe-entrada-' . preg_replace('/\D+/', '', (string) ($note['number'] ?: $note['id'])) . '.pdf';

    header('Content-Type: ' . ($pdf['content_type'] ?: 'application/pdf'));
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen((string) $pdf['content']));
    echo $pdf['content'];
    exit;
} catch (Throwable $e) {
    flash('error', 'Falha ao baixar PDF da NF-e recebida: ' . $e->getMessage());
    redirect('../index.php?page=nfe_entrada');
}
