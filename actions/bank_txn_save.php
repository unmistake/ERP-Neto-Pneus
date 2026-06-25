<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
authRequireActionLogin();
require_once __DIR__ . '/../includes/bank_schema.php';

$bank = (string) ($_POST['bank'] ?? '');
$type = (string) ($_POST['transaction_type'] ?? '');
$description = trim((string) ($_POST['description'] ?? ''));
$amount = (float) ($_POST['amount'] ?? 0);
$transactionDate = (string) ($_POST['transaction_date'] ?? '');
$reference = trim((string) ($_POST['reference'] ?? ''));

$validBanks = ['bb', 'santander', 'itau'];
$validTypes = ['in', 'out'];

if (
    !in_array($bank, $validBanks, true) ||
    !in_array($type, $validTypes, true) ||
    $description === '' ||
    $amount <= 0 ||
    $transactionDate === ''
) {
    flash('error', 'Dados invalidos para conciliacao bancaria.');
    redirect('../index.php?page=financeiro');
}

ensureBankTransactionsSchema($pdo);

$stmt = $pdo->prepare(
    'INSERT INTO bank_transactions (bank, source, transaction_type, description, amount, transaction_date, reference)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);
$stmt->execute([
    $bank,
    'manual',
    $type,
    $description,
    $amount,
    $transactionDate,
    $reference !== '' ? $reference : null,
]);

flash('success', 'Lancamento bancario registrado com sucesso.');
redirect('../index.php?page=financeiro');
