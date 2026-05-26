<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$description = trim($_POST['description'] ?? '');
$category = trim($_POST['category'] ?? '');
$amount = (float) ($_POST['amount'] ?? 0);
$costDate = $_POST['cost_date'] ?? '';

if ($description === '' || $amount <= 0 || $costDate === '') {
    flash('error', 'Dados invalidos para custo.');
    redirect('../index.php?page=financeiro');
}

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS costs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        description VARCHAR(160) NOT NULL,
        category VARCHAR(80) NULL,
        amount DECIMAL(10,2) NOT NULL,
        cost_date DATE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )"
);

$stmt = $pdo->prepare('INSERT INTO costs (description, category, amount, cost_date) VALUES (?, ?, ?, ?)');
$stmt->execute([$description, $category ?: null, $amount, $costDate]);

flash('success', 'Custo registrado com sucesso.');
redirect('../index.php?page=financeiro');
