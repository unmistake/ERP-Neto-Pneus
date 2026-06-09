<?php

declare(strict_types=1);

function ensureProductExtendedSchema(PDO $pdo): void
{
    $columns = [
        'sku' => "ALTER TABLE products ADD COLUMN sku VARCHAR(50) NULL AFTER name",
        'description' => "ALTER TABLE products ADD COLUMN description TEXT NULL AFTER sku",
        'gtin' => "ALTER TABLE products ADD COLUMN gtin VARCHAR(14) NULL AFTER description",
        'mpn' => "ALTER TABLE products ADD COLUMN mpn VARCHAR(70) NULL AFTER gtin",
        'google_category' => "ALTER TABLE products ADD COLUMN google_category VARCHAR(160) NULL AFTER mpn",
    ];

    foreach ($columns as $column => $sql) {
        $exists = (bool) $pdo->query("SHOW COLUMNS FROM products LIKE " . $pdo->quote($column))->fetch();
        if (!$exists) {
            $pdo->exec($sql);
        }
    }

    $hasImagePath = (bool) $pdo->query("SHOW COLUMNS FROM products LIKE 'image_path'")->fetch();
    if (!$hasImagePath) {
        $pdo->exec("ALTER TABLE products ADD COLUMN image_path VARCHAR(255) NULL AFTER location");
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS cars (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_cars_name (name)
        )"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS product_cars (
            product_id INT NOT NULL,
            car_id INT NOT NULL,
            PRIMARY KEY (product_id, car_id),
            CONSTRAINT fk_product_cars_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
            CONSTRAINT fk_product_cars_car FOREIGN KEY (car_id) REFERENCES cars(id) ON DELETE CASCADE
        )"
    );
}

function parseProductCars(string|array|null $value): array
{
    if (is_array($value)) {
        $parts = $value;
    } else {
        $parts = preg_split('/[,;\r\n]+/', (string) $value) ?: [];
    }

    $cars = [];
    foreach ($parts as $part) {
        $name = trim((string) $part);
        if ($name !== '') {
            $cars[mb_strtolower($name)] = $name;
        }
    }

    return array_values($cars);
}

function syncProductCars(PDO $pdo, int $productId, array $carNames): void
{
    $pdo->prepare('DELETE FROM product_cars WHERE product_id = ?')->execute([$productId]);

    if (count($carNames) === 0) {
        return;
    }

    $insertCar = $pdo->prepare('INSERT IGNORE INTO cars (name) VALUES (?)');
    $findCar = $pdo->prepare('SELECT id FROM cars WHERE name = ?');
    $insertLink = $pdo->prepare('INSERT IGNORE INTO product_cars (product_id, car_id) VALUES (?, ?)');

    foreach ($carNames as $carName) {
        $insertCar->execute([$carName]);
        $findCar->execute([$carName]);
        $carId = (int) $findCar->fetchColumn();
        if ($carId > 0) {
            $insertLink->execute([$productId, $carId]);
        }
    }
}

function productCarsMap(PDO $pdo, array $productIds): array
{
    $productIds = array_values(array_unique(array_map('intval', $productIds)));
    if (count($productIds) === 0) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT pc.product_id, c.name
         FROM product_cars pc
         INNER JOIN cars c ON c.id = pc.car_id
         WHERE pc.product_id IN (' . implode(',', array_fill(0, count($productIds), '?')) . ')
         ORDER BY c.name'
    );
    $stmt->execute($productIds);

    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int) $row['product_id']][] = (string) $row['name'];
    }

    return $map;
}

function productCarsText(array $cars): string
{
    return implode(', ', array_filter(array_map('trim', $cars)));
}

function productImageUpload(string $fieldName = 'image'): ?string
{
    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
        return null;
    }

    $file = $_FILES[$fieldName];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Falha ao enviar imagem do produto.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    if ($tmpName === '' || $size <= 0 || $size > 5 * 1024 * 1024) {
        throw new RuntimeException('Imagem invalida ou maior que 5MB.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmpName);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Formato de imagem nao permitido. Use JPG, PNG, WEBP ou GIF.');
    }

    $uploadDir = __DIR__ . '/../uploads/products';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Nao foi possivel criar a pasta de imagens.');
    }

    $fileName = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    $target = $uploadDir . '/' . $fileName;
    if (!move_uploaded_file($tmpName, $target)) {
        throw new RuntimeException('Nao foi possivel salvar a imagem enviada.');
    }

    return 'uploads/products/' . $fileName;
}
