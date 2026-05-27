<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$txnId = (int) ($_POST['txn_id'] ?? 0);
if ($txnId <= 0) {
    flash('error', 'Lancamento bancario invalido para exclusao.');
    redirect('../index.php?page=financeiro');
}

$stmt = $pdo->prepare('DELETE FROM bank_transactions WHERE id = ?');
$stmt->execute([$txnId]);

if ($stmt->rowCount() === 0) {
    flash('error', 'Lancamento bancario nao encontrado.');
    redirect('../index.php?page=financeiro');
}

flash('success', 'Lancamento bancario excluido com sucesso.');
redirect('../index.php?page=financeiro');

