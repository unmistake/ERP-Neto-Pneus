<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$productId = (int) ($_POST['product_id'] ?? 0);
if ($productId <= 0) {
    flash('error', 'Produto invalido para exclusao.');
    redirect('../index.php?page=estoque');
}

try {
    $pdo->beginTransaction();

    $saleItemCountStmt = $pdo->prepare('SELECT COUNT(*) FROM sale_items WHERE product_id = ?');
    $saleItemCountStmt->execute([$productId]);
    $saleItemCount = (int) $saleItemCountStmt->fetchColumn();

    if ($saleItemCount > 0) {
        throw new RuntimeException('Produto possui historico de vendas e nao pode ser excluido.');
    }

    $delMovementsStmt = $pdo->prepare('DELETE FROM stock_movements WHERE product_id = ?');
    $delMovementsStmt->execute([$productId]);

    $stmt = $pdo->prepare('DELETE FROM products WHERE id = ?');
    $stmt->execute([$productId]);

    if ($stmt->rowCount() === 0) {
        $pdo->rollBack();
        flash('error', 'Produto nao encontrado.');
        redirect('../index.php?page=estoque');
    }

    $pdo->commit();
    flash('success', 'Produto excluido com sucesso.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('error', 'Nao foi possivel excluir o produto: ' . $e->getMessage());
}

redirect('../index.php?page=estoque');
