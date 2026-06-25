<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
authRequireActionLogin();

$productId = (int) ($_POST['product_id'] ?? 0);
$movementType = $_POST['movement_type'] ?? 'in';
$quantity = (int) ($_POST['quantity'] ?? 0);
$note = trim($_POST['note'] ?? '');

if ($productId <= 0 || $quantity <= 0 || !in_array($movementType, ['in', 'out'], true)) {
    flash('error', 'Dados invalidos para ajuste de estoque.');
    redirect('../index.php?page=estoque');
}

$stmt = $pdo->prepare('SELECT stock_qty FROM products WHERE id = ?');
$stmt->execute([$productId]);
$current = $stmt->fetchColumn();

if ($current === false) {
    flash('error', 'Produto nao encontrado.');
    redirect('../index.php?page=estoque');
}

$currentStock = (int) $current;
$newStock = $movementType === 'in' ? $currentStock + $quantity : $currentStock - $quantity;
if ($newStock < 0) {
    flash('error', 'Estoque insuficiente para saida.');
    redirect('../index.php?page=estoque');
}

$pdo->beginTransaction();

$update = $pdo->prepare('UPDATE products SET stock_qty = ? WHERE id = ?');
$update->execute([$newStock, $productId]);

$mv = $pdo->prepare('INSERT INTO stock_movements (product_id, movement_type, quantity, note) VALUES (?, ?, ?, ?)');
$mv->execute([$productId, $movementType, $quantity, $note ?: 'Ajuste manual']);

$pdo->commit();

flash('success', 'Estoque ajustado com sucesso.');
redirect('../index.php?page=estoque');
