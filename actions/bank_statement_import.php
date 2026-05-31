<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bank_schema.php';
require_once __DIR__ . '/../includes/bank_statement_import.php';

$bank = (string) ($_POST['bank'] ?? '');
$validBanks = ['bb', 'santander', 'itau'];

if (!in_array($bank, $validBanks, true)) {
    flash('error', 'Selecione um banco valido para importar o extrato.');
    redirect('../index.php?page=financeiro');
}

if (!isset($_FILES['statement_file']) || !is_array($_FILES['statement_file'])) {
    flash('error', 'Envie um arquivo de extrato CSV ou OFX.');
    redirect('../index.php?page=financeiro');
}

$file = $_FILES['statement_file'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    flash('error', 'Nao foi possivel receber o arquivo do extrato.');
    redirect('../index.php?page=financeiro');
}

$tmpPath = (string) ($file['tmp_name'] ?? '');
$originalName = (string) ($file['name'] ?? '');
$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
$source = $extension === 'ofx' ? 'ofx' : 'csv';

if (!in_array($extension, ['csv', 'ofx', 'txt'], true)) {
    flash('error', 'Formato invalido. Envie um extrato em CSV, TXT ou OFX.');
    redirect('../index.php?page=financeiro');
}

try {
    ensureBankTransactionsSchema($pdo);
    $transactions = bankStatementParseFile($tmpPath, $source);

    if (count($transactions) === 0) {
        flash('error', 'Nenhum lancamento valido foi encontrado no extrato.');
        redirect('../index.php?page=financeiro');
    }

    $result = bankStatementImport($pdo, $bank, $source, $transactions);
    flash(
        'success',
        sprintf(
            'Extrato importado: %d lidos, %d novos e %d duplicados ignorados.',
            $result['read'],
            $result['imported'],
            $result['duplicated']
        )
    );
} catch (Throwable $e) {
    flash('error', 'Erro ao importar extrato: ' . $e->getMessage());
}

redirect('../index.php?page=financeiro');
