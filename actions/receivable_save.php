<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
authRequireActionLogin();

$description = trim($_POST['description'] ?? '');
$amount = (float) ($_POST['amount'] ?? 0);
$dueDate = $_POST['due_date'] ?? '';
$status = $_POST['status'] ?? 'pending';

if ($description === '' || $amount <= 0 || $dueDate === '' || !in_array($status, ['pending', 'paid'], true)) {
    flash('error', 'Dados invalidos para conta a receber.');
    redirect('../index.php?page=financeiro');
}

$stmt = $pdo->prepare('INSERT INTO accounts_receivable (description, amount, due_date, status) VALUES (?, ?, ?, ?)');
$stmt->execute([$description, $amount, $dueDate, $status]);

flash('success', 'Conta a receber registrada.');
redirect('../index.php?page=financeiro');
