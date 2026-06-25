<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
authRequireActionLogin();
require_once __DIR__ . '/../includes/product_schema.php';

ensureProductExtendedSchema($pdo);

$productId = (int) ($_POST['product_id'] ?? 0);
$name = trim((string) ($_POST['name'] ?? ''));
$sku = trim((string) ($_POST['sku'] ?? ''));
$description = trim((string) ($_POST['description'] ?? ''));
$gtin = preg_replace('/\D+/', '', (string) ($_POST['gtin'] ?? '')) ?? '';
$mpn = trim((string) ($_POST['mpn'] ?? ''));
$googleCategory = trim((string) ($_POST['google_category'] ?? ''));
$brand = trim((string) ($_POST['brand'] ?? ''));
$model = trim((string) ($_POST['model'] ?? ''));
$costPrice = (float) ($_POST['cost_price'] ?? -1);
$salePrice = (float) ($_POST['price'] ?? ($_POST['sale_price'] ?? -1));
$cars = parseProductCars($_POST['cars'] ?? '');

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

if ($gtin !== '' && !in_array(strlen($gtin), [8, 12, 13, 14], true)) {
    flash('error', 'GTIN/EAN deve ter 8, 12, 13 ou 14 digitos.');
    redirect('../index.php?page=estoque');
}

try {
    $imagePath = productImageUpload('image');

    $pdo->beginTransaction();
    if ($imagePath !== null) {
        $stmt = $pdo->prepare('UPDATE products SET name = ?, sku = ?, description = ?, gtin = ?, mpn = ?, google_category = ?, brand = ?, model = ?, image_path = ?, cost_price = ?, sale_price = ? WHERE id = ?');
        $stmt->execute([$name, $sku ?: null, $description ?: null, $gtin ?: null, $mpn ?: null, $googleCategory ?: null, $brand ?: null, $model ?: null, $imagePath, $costPrice, $salePrice, $productId]);
    } else {
        $stmt = $pdo->prepare('UPDATE products SET name = ?, sku = ?, description = ?, gtin = ?, mpn = ?, google_category = ?, brand = ?, model = ?, cost_price = ?, sale_price = ? WHERE id = ?');
        $stmt->execute([$name, $sku ?: null, $description ?: null, $gtin ?: null, $mpn ?: null, $googleCategory ?: null, $brand ?: null, $model ?: null, $costPrice, $salePrice, $productId]);
    }
    syncProductCars($pdo, $productId, $cars);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $isAjax = (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest');
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
    flash('error', $e->getMessage());
    redirect('../index.php?page=estoque');
}

$isAjax = (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest');
if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'data' => [
            'product_id' => $productId,
            'name' => $name,
            'sku' => $sku,
            'description' => $description,
            'gtin' => $gtin,
            'mpn' => $mpn,
            'google_category' => $googleCategory,
            'brand' => $brand,
            'model' => $model,
            'cars' => $cars,
            'cost_price' => $costPrice,
            'price' => $salePrice,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

flash('success', 'Produto atualizado com sucesso.');
redirect('../index.php?page=estoque');
