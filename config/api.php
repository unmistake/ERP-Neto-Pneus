<?php

$token = trim((string) getenv('ERP_API_TOKEN'));
$localConfigFile = __DIR__ . '/api.local.php';

if ($token === '' && is_file($localConfigFile)) {
    $localConfig = require $localConfigFile;
    $token = trim((string) ($localConfig['token'] ?? ''));
}

return [
    // O valor padrao facilita o desenvolvimento local. Producao usa variavel
    // de ambiente ou config/api.local.php, que nao entra no Git.
    'token' => $token !== '' ? $token : 'token-da-api-netopneus',
];
