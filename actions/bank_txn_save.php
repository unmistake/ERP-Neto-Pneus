<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

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

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS bank_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bank ENUM('bb','santander','itau') NOT NULL,
        transaction_type ENUM('in','out') NOT NULL,
        description VARCHAR(160) NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        transaction_date DATE NOT NULL,
        reference VARCHAR(120) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )"
);

$stmt = $pdo->prepare(
    'INSERT INTO bank_transactions (bank, transaction_type, description, amount, transaction_date, reference)
     VALUES (?, ?, ?, ?, ?, ?)'
);
$stmt->execute([
    $bank,
    $type,
    $description,
    $amount,
    $transactionDate,
    $reference !== '' ? $reference : null,
]);

flash('success', 'Lancamento bancario registrado com sucesso.');
redirect('../index.php?page=financeiro');

