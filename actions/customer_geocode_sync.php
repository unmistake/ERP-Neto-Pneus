<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
authRequireActionLogin();
require_once __DIR__ . '/../includes/customer_geocode.php';

$force = (string) ($_POST['mode'] ?? '') === 'full';
$limit = (int) ($_POST['limit'] ?? 25);

// Geocodificar pode demorar (1s por cliente). Amplia o tempo de execucao do lote.
@set_time_limit(180);

try {
    $result = customerGeocodeSyncPending($pdo, $limit, $force);
    flash(
        'success',
        'Sincronizacao de localizacoes concluida: ' . (int) $result['located'] . ' cliente(s) no mapa, ' .
        (int) $result['failed'] . ' sem coordenada, ' . (int) $result['remaining'] . ' ainda pendente(s).'
    );
} catch (Throwable $e) {
    flash('error', 'Falha ao geocodificar clientes: ' . $e->getMessage());
}

redirect('../index.php?page=mapa');
