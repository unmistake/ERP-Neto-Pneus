<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$productId = (int) ($_POST['product_id'] ?? 0);
$name = trim((string) ($_POST['name'] ?? ''));
$brand = trim((string) ($_POST['brand'] ?? ''));
$model = trim((string) ($_POST['model'] ?? ''));
$costPrice = (float) ($_POST['cost_price'] ?? -1);
$salePrice = (float) ($_POST['price'] ?? ($_POST['sale_price'] ?? -1));

if ($productId <= 0 || $name === '' || $costPrice < 0 || $salePrice < 0) {
    $isAjax = (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest');
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Dados invalidos para atualizar produto.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    flash('error', 'Dados invalidos para atualizar produto.');
    redirect('../index.php?page=estoque');
}

$stmt = $pdo->prepare('UPDATE products SET name = ?, brand = ?, model = ?, cost_price = ?, sale_price = ? WHERE id = ?');
$stmt->execute([$name, $brand !== '' ? $brand : null, $model !== '' ? $model : null, $costPrice, $salePrice, $productId]);

$isAjax = (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest');
if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'data' => [
            'product_id' => $productId,
            'name' => $name,
            'brand' => $brand,
            'model' => $model,
            'cost_price' => $costPrice,
            'price' => $salePrice,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

flash('success', 'Produto atualizado com sucesso.');
redirect('../index.php?page=estoque');
