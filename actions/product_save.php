<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/product_schema.php';

ensureProductExtendedSchema($pdo);

$name = trim($_POST['name'] ?? '');
$category = trim($_POST['category'] ?? 'pneu');
$itemCondition = trim($_POST['item_condition'] ?? 'novo');
$usedTireCondition = trim($_POST['used_tire_condition'] ?? '');
$brand = trim($_POST['brand'] ?? '');
$model = trim($_POST['model'] ?? '');
$width = trim($_POST['width'] ?? '');
$profile = trim($_POST['profile'] ?? '');
$rimPneu = trim($_POST['rim_pneu'] ?? '');
$rimRoda = trim($_POST['rim_roda'] ?? '');
$rim = $category === 'roda' ? $rimRoda : $rimPneu;
$location = trim($_POST['location'] ?? '');
$costPrice = (float) ($_POST['cost_price'] ?? 0);
$salePrice = (float) ($_POST['price'] ?? ($_POST['sale_price'] ?? 0));
$stockQty = (int) ($_POST['stock_qty'] ?? 0);
$cars = parseProductCars($_POST['cars'] ?? '');

if (!in_array($category, ['pneu', 'roda'], true) || !in_array($itemCondition, ['novo', 'usado'], true)) {
    flash('error', 'Categoria/estado invalidos para produto.');
    redirect('../index.php?page=estoque');
}

if ($category === 'pneu' && ($width === '' || $profile === '' || $rim === '')) {
    flash('error', 'Para pneu, informe largura, perfil e aro.');
    redirect('../index.php?page=estoque');
}

if ($category === 'roda' && $rim === '') {
    flash('error', 'Para roda, informe o aro.');
    redirect('../index.php?page=estoque');
}

$validUsedTireConditions = ['seminovo', 'meia_vida', 'abaixo_50_twi', 'seminovo_com_reparo'];
if ($category === 'pneu' && $itemCondition === 'usado' && !in_array($usedTireCondition, $validUsedTireConditions, true)) {
    flash('error', 'Para pneu usado, informe a classificacao do usado.');
    redirect('../index.php?page=estoque');
}

if ($name === '' || $costPrice < 0 || $salePrice < 0 || $stockQty < 0) {
    flash('error', 'Dados invalidos para cadastrar produto.');
    redirect('../index.php?page=estoque');
}

try {
    $imagePath = productImageUpload('image');

    $pdo->beginTransaction();
    $stmt = $pdo->prepare('INSERT INTO products (name, category, item_condition, used_tire_condition, brand, model, width, profile, rim, location, image_path, cost_price, sale_price, stock_qty) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $name,
        $category,
        $itemCondition,
        ($category === 'pneu' && $itemCondition === 'usado') ? $usedTireCondition : null,
        $brand ?: null,
        $model ?: null,
        $width ?: null,
        $profile ?: null,
        $rim ?: null,
        $location ?: null,
        $imagePath,
        $costPrice,
        $salePrice,
        $stockQty,
    ]);

    $productId = (int) $pdo->lastInsertId();
    syncProductCars($pdo, $productId, $cars);

    if ($stockQty > 0) {
        $mv = $pdo->prepare('INSERT INTO stock_movements (product_id, movement_type, quantity, note) VALUES (?, ?, ?, ?)');
        $mv->execute([$productId, 'in', $stockQty, 'Estoque inicial']);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('error', $e->getMessage());
    redirect('../index.php?page=estoque');
}

flash('success', 'Produto cadastrado com sucesso.');
redirect('../index.php?page=estoque');

