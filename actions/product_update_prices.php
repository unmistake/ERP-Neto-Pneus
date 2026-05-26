<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$productId = (int) ($_POST['product_id'] ?? 0);
$costPrice = (float) ($_POST['cost_price'] ?? -1);
$salePrice = (float) ($_POST['price'] ?? ($_POST['sale_price'] ?? -1));

if ($productId <= 0 || $costPrice < 0 || $salePrice < 0) {
    $isAjax = (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest');
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Dados invalidos para atualizar preco/custo.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    flash('error', 'Dados invalidos para atualizar preco/custo.');
    redirect('../index.php?page=estoque');
}

$stmt = $pdo->prepare('UPDATE products SET cost_price = ?, sale_price = ? WHERE id = ?');
$stmt->execute([$costPrice, $salePrice, $productId]);

$isAjax = (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest');
if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'data' => [
            'product_id' => $productId,
            'cost_price' => $costPrice,
            'price' => $salePrice,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

flash('success', 'Custo e preco atualizados com sucesso.');
redirect('../index.php?page=estoque');
