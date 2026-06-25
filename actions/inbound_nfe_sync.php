<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
authRequireActionLogin();
require_once __DIR__ . '/../includes/inbound_nfe_focus.php';

$mode = (string) ($_POST['mode'] ?? 'incremental');
$returnQuery = trim((string) ($_POST['return_query'] ?? ''));
$returnUrl = '../index.php?page=nfe_entrada' . ($returnQuery !== '' ? '&' . $returnQuery : '');

try {
    $result = inboundNfeSync($pdo, $mode === 'full');
    flash(
        'success',
        'Sincronizacao concluida: ' . (int) $result['stored'] . ' NF-e gravada(s), ' .
        (int) $result['skipped'] . ' ignorada(s). Versao Focus atual: ' . (int) $result['max_version'] . '.'
    );
} catch (Throwable $e) {
    flash('error', 'Falha ao sincronizar NF-e recebidas: ' . $e->getMessage());
}

redirect($returnUrl);
