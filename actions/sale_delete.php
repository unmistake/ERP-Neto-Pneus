<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$saleId = (int) ($_POST['sale_id'] ?? 0);
if ($saleId <= 0) {
    flash('error', 'Venda invalida para exclusao.');
    redirect('../index.php?page=pdv');
}

try {
    $pdo->beginTransaction();

    $saleStmt = $pdo->prepare('SELECT id FROM sales WHERE id = ? FOR UPDATE');
    $saleStmt->execute([$saleId]);
    $sale = $saleStmt->fetch();
    if (!$sale) {
        throw new RuntimeException('Venda nao encontrada.');
    }

    $itemsStmt = $pdo->prepare('SELECT product_id, quantity FROM sale_items WHERE sale_id = ?');
    $itemsStmt->execute([$saleId]);
    $items = $itemsStmt->fetchAll();

    $stockStmt = $pdo->prepare('SELECT stock_qty FROM products WHERE id = ? FOR UPDATE');
    $updateStockStmt = $pdo->prepare('UPDATE products SET stock_qty = ? WHERE id = ?');
    $movementStmt = $pdo->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, note) VALUES (?, 'in', ?, ?)");

    foreach ($items as $item) {
        $productId = (int) $item['product_id'];
        $qty = (int) $item['quantity'];

        $stockStmt->execute([$productId]);
        $product = $stockStmt->fetch();
        if (!$product) {
            continue;
        }

        $newStock = (int) $product['stock_qty'] + $qty;
        $updateStockStmt->execute([$newStock, $productId]);
        $movementStmt->execute([$productId, $qty, 'Estorno exclusao venda #' . $saleId]);
    }

    $delRecStmt = $pdo->prepare('DELETE FROM accounts_receivable WHERE sale_id = ?');
    $delRecStmt->execute([$saleId]);

    $delFiscalStmt = $pdo->prepare('DELETE FROM fiscal_documents WHERE sale_id = ?');
    $delFiscalStmt->execute([$saleId]);

    $delItemsStmt = $pdo->prepare('DELETE FROM sale_items WHERE sale_id = ?');
    $delItemsStmt->execute([$saleId]);

    $delSaleStmt = $pdo->prepare('DELETE FROM sales WHERE id = ?');
    $delSaleStmt->execute([$saleId]);

    $pdo->commit();
    flash('success', 'Venda excluida com sucesso.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('error', 'Nao foi possivel excluir venda: ' . $e->getMessage());
}

redirect('../index.php?page=pdv');
