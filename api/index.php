<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function apiResponse(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ensureSchema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            category ENUM('pneu','roda') NOT NULL DEFAULT 'pneu',
            item_condition ENUM('novo','usado') NOT NULL DEFAULT 'novo',
            used_tire_condition ENUM('seminovo','meia_vida','abaixo_50_twi','seminovo_com_reparo') NULL,
            brand VARCHAR(80) NULL,
            model VARCHAR(40) NULL,
            width VARCHAR(10) NULL,
            profile VARCHAR(10) NULL,
            rim VARCHAR(10) NULL,
            location VARCHAR(120) NULL,
            cost_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            sale_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            stock_qty INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )"
    );

    $hasCategory = (bool) $pdo->query("SHOW COLUMNS FROM products LIKE 'category'")->fetch();
    if (!$hasCategory) {
        $pdo->exec("ALTER TABLE products ADD COLUMN category ENUM('pneu','roda') NOT NULL DEFAULT 'pneu' AFTER name");
    }
    $hasCondition = (bool) $pdo->query("SHOW COLUMNS FROM products LIKE 'item_condition'")->fetch();
    if (!$hasCondition) {
        $pdo->exec("ALTER TABLE products ADD COLUMN item_condition ENUM('novo','usado') NOT NULL DEFAULT 'novo' AFTER category");
    }
    $hasUsedTireCondition = (bool) $pdo->query("SHOW COLUMNS FROM products LIKE 'used_tire_condition'")->fetch();
    if (!$hasUsedTireCondition) {
        $pdo->exec("ALTER TABLE products ADD COLUMN used_tire_condition ENUM('seminovo','meia_vida','abaixo_50_twi','seminovo_com_reparo') NULL AFTER item_condition");
    }
    $hasWidth = (bool) $pdo->query("SHOW COLUMNS FROM products LIKE 'width'")->fetch();
    if (!$hasWidth) {
        $pdo->exec("ALTER TABLE products ADD COLUMN width VARCHAR(10) NULL AFTER model");
    }
    $hasProfile = (bool) $pdo->query("SHOW COLUMNS FROM products LIKE 'profile'")->fetch();
    if (!$hasProfile) {
        $pdo->exec("ALTER TABLE products ADD COLUMN profile VARCHAR(10) NULL AFTER width");
    }
    $hasRim = (bool) $pdo->query("SHOW COLUMNS FROM products LIKE 'rim'")->fetch();
    if (!$hasRim) {
        $pdo->exec("ALTER TABLE products ADD COLUMN rim VARCHAR(10) NULL AFTER profile");
    }

    $hasLocation = (bool) $pdo->query("SHOW COLUMNS FROM products LIKE 'location'")->fetch();
    if (!$hasLocation) {
        $pdo->exec("ALTER TABLE products ADD COLUMN location VARCHAR(120) NULL AFTER model");
    }
    $pdo->exec("UPDATE products SET location = 'Depósito' WHERE LOWER(TRIM(location)) = 'no andar de cima da loja' OR LOWER(TRIM(location)) = 'andar de cima da loja'");

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS customers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            first_name VARCHAR(80) NOT NULL,
            last_name VARCHAR(80) NOT NULL,
            phone VARCHAR(20) NOT NULL,
            tax_id VARCHAR(18) NOT NULL,
            car VARCHAR(120) NULL,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_customers_tax_id (tax_id)
        )"
    );

    $hasCustomerId = (bool) $pdo->query("SHOW COLUMNS FROM sales LIKE 'customer_id'")->fetch();
    if (!$hasCustomerId) {
        $pdo->exec("ALTER TABLE sales ADD COLUMN customer_id INT NULL AFTER id");
        $pdo->exec('ALTER TABLE sales ADD CONSTRAINT fk_sales_customer FOREIGN KEY (customer_id) REFERENCES customers(id)');
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
}

function readJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $parsed = json_decode($raw, true);
    if (!is_array($parsed)) {
        apiResponse(400, ['ok' => false, 'error' => 'JSON invalido.']);
    }

    return $parsed;
}

function getBearerToken(): string
{
    $auth = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';

    if ($auth === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }

    if (stripos($auth, 'Bearer ') === 0) {
        return trim(substr($auth, 7));
    }
    return '';
}

function customerFullName(array $customer): string
{
    return trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));
}

function parseRoute(): array
{
    $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $baseDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    $path = (string) $uriPath;

    if ($baseDir !== '' && $baseDir !== '/' && strpos($path, $baseDir) === 0) {
        $path = substr($path, strlen($baseDir));
    }

    $path = trim($path, '/');
    $segments = $path === '' ? [] : array_values(array_filter(explode('/', $path), static fn ($s) => $s !== ''));

    if (count($segments) > 0 && $segments[0] === 'index.php') {
        array_shift($segments);
    }

    if (count($segments) > 0 && $segments[0] === 'api') {
        array_shift($segments);
    }

    return $segments;
}

function getPaginationParams(int $defaultLimit = 20, int $maxLimit = 100): array
{
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = (int) ($_GET['limit'] ?? $defaultLimit);
    if ($limit <= 0) {
        $limit = $defaultLimit;
    }
    $limit = min($limit, $maxLimit);
    $offset = ($page - 1) * $limit;

    return [$page, $limit, $offset];
}

function paginatedResponse(array $rows, int $total, int $page, int $limit): array
{
    $totalPages = max(1, (int) ceil($total / $limit));
    return [
        'data' => $rows,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => $totalPages,
            'has_next' => $page < $totalPages,
            'has_prev' => $page > 1,
        ],
    ];
}

function fetchSaleWithItems(PDO $pdo, int $saleId): array
{
    $saleStmt = $pdo->prepare('SELECT id, customer_id, customer_name, total_amount, payment_method, payment_status, created_at FROM sales WHERE id = ?');
    $saleStmt->execute([$saleId]);
    $sale = $saleStmt->fetch();
    if (!$sale) {
        apiResponse(404, ['ok' => false, 'error' => 'Venda nao encontrada.']);
    }

    $itemsStmt = $pdo->prepare(
        'SELECT si.product_id, p.name AS product_name, p.brand, p.model, si.quantity, si.unit_price, si.line_total
         FROM sale_items si
         INNER JOIN products p ON p.id = si.product_id
         WHERE si.sale_id = ?
         ORDER BY si.id ASC'
    );
    $itemsStmt->execute([$saleId]);
    $items = $itemsStmt->fetchAll();

    return ['sale' => $sale, 'items' => $items];
}

function createSale(PDO $pdo, array $body): int
{
    $customerId = isset($body['customer_id']) ? (int) $body['customer_id'] : 0;
    $customerName = trim((string) ($body['customer_name'] ?? ''));
    $customerPhone = trim((string) ($body['customer_phone'] ?? ''));
    $customerTaxId = trim((string) ($body['customer_tax_id'] ?? ''));
    $paymentMethod = trim((string) ($body['payment_method'] ?? 'dinheiro'));
    $paymentStatus = trim((string) ($body['payment_status'] ?? 'paid'));
    $dueDate = trim((string) ($body['due_date'] ?? date('Y-m-d')));
    $itemsInput = $body['items'] ?? [];

    if (!is_array($itemsInput) || count($itemsInput) === 0) {
        apiResponse(422, ['ok' => false, 'error' => 'Informe ao menos um item em items[].']);
    }

    if (!in_array($paymentStatus, ['paid', 'pending'], true)) {
        apiResponse(422, ['ok' => false, 'error' => 'payment_status invalido.']);
    }

    $items = [];
    $totalAmount = 0.0;
    foreach ($itemsInput as $item) {
        $productId = (int) ($item['product_id'] ?? 0);
        $qty = (int) ($item['quantity'] ?? 0);
        $price = (float) ($item['unit_price'] ?? 0);
        if ($productId <= 0 || $qty <= 0 || $price < 0) {
            continue;
        }

        $lineTotal = $qty * $price;
        $items[] = [
            'product_id' => $productId,
            'quantity' => $qty,
            'unit_price' => $price,
            'line_total' => $lineTotal,
        ];
        $totalAmount += $lineTotal;
    }

    if (count($items) === 0 || $totalAmount <= 0) {
        apiResponse(422, ['ok' => false, 'error' => 'Itens invalidos.']);
    }

    $pdo->beginTransaction();
    try {
        if ($customerId > 0) {
            $customerStmt = $pdo->prepare('SELECT CONCAT(first_name, " ", last_name) AS full_name FROM customers WHERE id = ?');
            $customerStmt->execute([$customerId]);
            $customer = $customerStmt->fetch();
            if (!$customer) {
                throw new RuntimeException('Cliente selecionado nao encontrado.');
            }
            $customerName = (string) $customer['full_name'];
        } else {
            $customerId = null;

            if ($customerTaxId !== '') {
                $findByTax = $pdo->prepare('SELECT id, CONCAT(first_name, " ", last_name) AS full_name FROM customers WHERE tax_id = ? LIMIT 1');
                $findByTax->execute([$customerTaxId]);
                $existing = $findByTax->fetch();
                if ($existing) {
                    $customerId = (int) $existing['id'];
                    $customerName = (string) $existing['full_name'];
                }
            }

            if ($customerId === null && $customerName !== '') {
                $findByName = $pdo->prepare('SELECT id, CONCAT(first_name, " ", last_name) AS full_name FROM customers WHERE LOWER(TRIM(CONCAT(first_name, " ", last_name))) = LOWER(TRIM(?)) LIMIT 1');
                $findByName->execute([$customerName]);
                $existing = $findByName->fetch();
                if ($existing) {
                    $customerId = (int) $existing['id'];
                    $customerName = (string) $existing['full_name'];
                } else {
                    $parts = preg_split('/\s+/', $customerName) ?: [];
                    $parts = array_values(array_filter($parts, static fn ($p) => $p !== ''));
                    $firstName = $parts[0] ?? 'Cliente';
                    $lastName = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : 'API';
                    $taxIdToSave = $customerTaxId !== '' ? $customerTaxId : ('AUTO' . time() . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT));
                    $phoneToSave = $customerPhone !== '' ? $customerPhone : '00 00000-0000';
                    $createCustomerStmt = $pdo->prepare('INSERT INTO customers (first_name, last_name, phone, tax_id, car, notes) VALUES (?, ?, ?, ?, NULL, ?)');
                    $createCustomerStmt->execute([$firstName, $lastName, $phoneToSave, $taxIdToSave, 'Criado automaticamente pela API.']);
                    $customerId = (int) $pdo->lastInsertId();
                    $customerName = trim($firstName . ' ' . $lastName);
                }
            }
        }

        $saleStmt = $pdo->prepare('INSERT INTO sales (customer_id, customer_name, total_amount, payment_method, payment_status) VALUES (?, ?, ?, ?, ?)');
        $saleStmt->execute([$customerId, $customerName ?: null, $totalAmount, $paymentMethod, $paymentStatus]);
        $saleId = (int) $pdo->lastInsertId();

        $stockStmt = $pdo->prepare('SELECT name, stock_qty FROM products WHERE id = ? FOR UPDATE');
        $saleItemStmt = $pdo->prepare('INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, line_total) VALUES (?, ?, ?, ?, ?)');
        $updateStockStmt = $pdo->prepare('UPDATE products SET stock_qty = ? WHERE id = ?');
        $movementStmt = $pdo->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, note) VALUES (?, 'out', ?, ?)");

        foreach ($items as $item) {
            $stockStmt->execute([$item['product_id']]);
            $product = $stockStmt->fetch();
            if (!$product) {
                throw new RuntimeException('Produto nao encontrado durante a venda.');
            }

            $currentStock = (int) $product['stock_qty'];
            $newStock = $currentStock - $item['quantity'];
            $updateStockStmt->execute([$newStock, $item['product_id']]);
            $saleItemStmt->execute([$saleId, $item['product_id'], $item['quantity'], $item['unit_price'], $item['line_total']]);
            $movementStmt->execute([$item['product_id'], $item['quantity'], 'Venda API #' . $saleId]);
        }

        if ($paymentStatus === 'pending') {
            $desc = 'Venda API #' . $saleId . ' - ' . ($customerName ?: 'Consumidor final');
            $recStmt = $pdo->prepare("INSERT INTO accounts_receivable (description, amount, due_date, status, sale_id) VALUES (?, ?, ?, 'pending', ?)");
            $recStmt->execute([$desc, $totalAmount, $dueDate, $saleId]);
        }

        $pdo->commit();
        return $saleId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        apiResponse(400, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

ensureSchema($pdo);

$apiConfig = require __DIR__ . '/../config/api.php';
$expectedToken = (string) ($apiConfig['token'] ?? '');
$providedToken = getBearerToken();
if ($expectedToken === '' || $providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    apiResponse(401, ['ok' => false, 'error' => 'Nao autorizado.']);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = readJsonBody();
$segments = parseRoute();

// Compatibilidade com formato antigo ?resource=...
if (count($segments) === 0 && isset($_GET['resource'])) {
    $resource = (string) $_GET['resource'];
    if ($resource === 'health') {
        $segments = ['health'];
    } elseif ($resource === 'sale_details') {
        $segments = ['sales', (string) ($_GET['sale_id'] ?? '')];
    } else {
        $segments = [$resource];
    }
}

if (count($segments) === 0) {
    apiResponse(200, [
        'ok' => true,
        'service' => 'ERP API',
        'version' => 'v1',
        'endpoints' => ['/health', '/products', '/customers', '/customers/{id}', '/sales', '/sales/{id}', '/costs', '/costs/{id}'],
    ]);
}

if ($segments[0] === 'health' && $method === 'GET') {
    apiResponse(200, ['ok' => true, 'service' => 'ERP API', 'status' => 'up']);
}

if ($segments[0] === 'products' && $method === 'GET' && count($segments) === 1) {
    [$page, $limit, $offset] = getPaginationParams(20, 200);
    $q = trim((string) ($_GET['q'] ?? ''));
    $brand = trim((string) ($_GET['brand'] ?? ''));
    $model = trim((string) ($_GET['model'] ?? ''));
    $stockStatus = trim((string) ($_GET['stock_status'] ?? '')); // in_stock | out_of_stock
    $usedTireCondition = trim((string) ($_GET['used_tire_condition'] ?? ''));

    $where = [];
    $params = [];
    if ($q !== '') {
        $where[] = '(name LIKE ? OR brand LIKE ? OR model LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if ($brand !== '') {
        $where[] = 'brand LIKE ?';
        $params[] = '%' . $brand . '%';
    }
    if ($model !== '') {
        $where[] = 'model LIKE ?';
        $params[] = '%' . $model . '%';
    }
    if ($stockStatus === 'in_stock') {
        $where[] = 'stock_qty > 0';
    } elseif ($stockStatus === 'out_of_stock') {
        $where[] = 'stock_qty <= 0';
    }
    if (in_array($usedTireCondition, ['seminovo', 'meia_vida', 'abaixo_50_twi', 'seminovo_com_reparo'], true)) {
        $where[] = 'used_tire_condition = ?';
        $params[] = $usedTireCondition;
    }

    $whereSql = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM products $whereSql");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $sql = "SELECT id, name, category, item_condition, used_tire_condition, brand, model, width, profile, rim, location, cost_price, sale_price AS price, stock_qty, created_at
            FROM products
            $whereSql
            ORDER BY id DESC
            LIMIT $limit OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    apiResponse(200, ['ok' => true] + paginatedResponse($rows, $total, $page, $limit));
}

if ($segments[0] === 'products' && $method === 'POST' && count($segments) === 1) {
    $name = trim((string) ($body['name'] ?? ''));
    $category = trim((string) ($body['category'] ?? 'pneu'));
    $itemCondition = trim((string) ($body['item_condition'] ?? 'novo'));
    $usedTireCondition = trim((string) ($body['used_tire_condition'] ?? ''));
    $brand = trim((string) ($body['brand'] ?? ''));
    $model = trim((string) ($body['model'] ?? ''));
    $width = trim((string) ($body['width'] ?? ''));
    $profile = trim((string) ($body['profile'] ?? ''));
    $rim = trim((string) ($body['rim'] ?? ''));
    $location = trim((string) ($body['location'] ?? ''));
    $costPrice = (float) ($body['cost_price'] ?? 0);
    $salePrice = (float) ($body['price'] ?? ($body['sale_price'] ?? 0));
    $stockQty = (int) ($body['stock_qty'] ?? 0);

    if (!in_array($category, ['pneu', 'roda'], true) || !in_array($itemCondition, ['novo', 'usado'], true)) {
        apiResponse(422, ['ok' => false, 'error' => 'Categoria/estado invalidos para produto.']);
    }

    if ($category === 'pneu' && ($width === '' || $profile === '' || $rim === '')) {
        apiResponse(422, ['ok' => false, 'error' => 'Para pneu, informe width, profile e rim.']);
    }

    if ($category === 'roda' && $rim === '') {
        apiResponse(422, ['ok' => false, 'error' => 'Para roda, informe rim.']);
    }
    $validUsedTireConditions = ['seminovo', 'meia_vida', 'abaixo_50_twi', 'seminovo_com_reparo'];
    if ($category === 'pneu' && $itemCondition === 'usado' && !in_array($usedTireCondition, $validUsedTireConditions, true)) {
        apiResponse(422, ['ok' => false, 'error' => 'Para pneu usado, informe used_tire_condition valido.']);
    }

    if ($name === '' || $costPrice < 0 || $salePrice < 0 || $stockQty < 0) {
        apiResponse(422, ['ok' => false, 'error' => 'Campos invalidos para produto.']);
    }

    $stmt = $pdo->prepare('INSERT INTO products (name, category, item_condition, used_tire_condition, brand, model, width, profile, rim, location, cost_price, sale_price, stock_qty) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$name, $category, $itemCondition, ($category === 'pneu' && $itemCondition === 'usado') ? $usedTireCondition : null, $brand ?: null, $model ?: null, $width ?: null, $profile ?: null, $rim ?: null, $location ?: null, $costPrice, $salePrice, $stockQty]);
    $productId = (int) $pdo->lastInsertId();

    if ($stockQty > 0) {
        $mv = $pdo->prepare('INSERT INTO stock_movements (product_id, movement_type, quantity, note) VALUES (?, ?, ?, ?)');
        $mv->execute([$productId, 'in', $stockQty, 'Estoque inicial via API']);
    }

    apiResponse(201, ['ok' => true, 'data' => ['id' => $productId]]);
}

if ($segments[0] === 'products' && $method === 'POST' && count($segments) === 2 && $segments[1] === 'fill-location') {
    $location = trim((string) ($body['location'] ?? ''));
    $category = trim((string) ($body['category'] ?? ''));

    if ($location === '') {
        apiResponse(422, ['ok' => false, 'error' => 'Informe o local.']);
    }
    if ($category !== '' && !in_array($category, ['pneu', 'roda'], true)) {
        apiResponse(422, ['ok' => false, 'error' => 'Categoria invalida. Use pneu ou roda.']);
    }

    $where = "(location IS NULL OR TRIM(location) = '')";
    $params = [$location];
    if ($category !== '') {
        $where .= " AND category = ?";
        $params[] = $category;
    }

    $sql = "UPDATE products SET location = ? WHERE $where";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    apiResponse(200, [
        'ok' => true,
        'data' => [
            'location' => $location,
            'category' => $category !== '' ? $category : null,
            'updated_count' => $stmt->rowCount(),
        ],
    ]);
}

if ($segments[0] === 'products' && in_array($method, ['PATCH', 'POST'], true) && count($segments) === 3 && $segments[2] === 'cost') {
    $productId = (int) $segments[1];
    $costPrice = (float) ($body['cost_price'] ?? -1);

    if ($productId <= 0 || $costPrice < 0) {
        apiResponse(422, ['ok' => false, 'error' => 'Dados invalidos para atualizar custo.']);
    }

    $checkStmt = $pdo->prepare('SELECT id, name, cost_price FROM products WHERE id = ?');
    $checkStmt->execute([$productId]);
    $product = $checkStmt->fetch();
    if (!$product) {
        apiResponse(404, ['ok' => false, 'error' => 'Produto nao encontrado.']);
    }

    $upd = $pdo->prepare('UPDATE products SET cost_price = ? WHERE id = ?');
    $upd->execute([$costPrice, $productId]);

    apiResponse(200, [
        'ok' => true,
        'data' => [
            'id' => $productId,
            'name' => (string) $product['name'],
            'cost_price' => $costPrice,
        ],
    ]);
}

if ($segments[0] === 'products' && $method === 'PATCH' && count($segments) === 2) {
    $id = (int) $segments[1];
    if ($id <= 0) {
        apiResponse(422, ['ok' => false, 'error' => 'ID invalido.']);
    }

    $stmt = $pdo->prepare('SELECT id, name, brand, model, cost_price, sale_price, stock_qty FROM products WHERE id = ?');
    $stmt->execute([$id]);
    $current = $stmt->fetch();
    if (!$current) {
        apiResponse(404, ['ok' => false, 'error' => 'Produto nao encontrado.']);
    }

    $name = array_key_exists('name', $body) ? trim((string) $body['name']) : (string) $current['name'];
    $brand = array_key_exists('brand', $body) ? trim((string) $body['brand']) : (string) ($current['brand'] ?? '');
    $model = array_key_exists('model', $body) ? trim((string) $body['model']) : (string) ($current['model'] ?? '');

    if ($name === '') {
        apiResponse(422, ['ok' => false, 'error' => 'Nome do produto nao pode ser vazio.']);
    }

    $upd = $pdo->prepare('UPDATE products SET name = ?, brand = ?, model = ? WHERE id = ?');
    $upd->execute([$name, $brand ?: null, $model ?: null, $id]);

    apiResponse(200, [
        'ok' => true,
        'data' => [
            'id' => $id,
            'name' => $name,
            'brand' => $brand,
            'model' => $model,
        ],
    ]);
}

if ($segments[0] === 'stock-adjustments' && $method === 'POST' && count($segments) === 1) {
    $productId = (int) ($body['product_id'] ?? 0);
    $movementType = trim((string) ($body['movement_type'] ?? 'in'));
    $quantity = (int) ($body['quantity'] ?? 0);
    $note = trim((string) ($body['note'] ?? ''));

    if ($productId <= 0 || $quantity <= 0 || !in_array($movementType, ['in', 'out'], true)) {
        apiResponse(422, ['ok' => false, 'error' => 'Dados invalidos para ajuste de estoque.']);
    }

    $stmt = $pdo->prepare('SELECT stock_qty FROM products WHERE id = ?');
    $stmt->execute([$productId]);
    $current = $stmt->fetchColumn();
    if ($current === false) {
        apiResponse(404, ['ok' => false, 'error' => 'Produto nao encontrado.']);
    }

    $currentStock = (int) $current;
    $newStock = $movementType === 'in' ? $currentStock + $quantity : $currentStock - $quantity;
    if ($newStock < 0) {
        apiResponse(422, ['ok' => false, 'error' => 'Estoque insuficiente para saida.']);
    }

    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare('UPDATE products SET stock_qty = ? WHERE id = ?');
        $update->execute([$newStock, $productId]);

        $mv = $pdo->prepare('INSERT INTO stock_movements (product_id, movement_type, quantity, note) VALUES (?, ?, ?, ?)');
        $mv->execute([$productId, $movementType, $quantity, $note ?: 'Ajuste manual via API']);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        apiResponse(400, ['ok' => false, 'error' => 'Falha ao ajustar estoque: ' . $e->getMessage()]);
    }

    apiResponse(201, ['ok' => true, 'data' => ['product_id' => $productId, 'new_stock' => $newStock]]);
}

if ($segments[0] === 'customers' && $method === 'GET' && count($segments) === 1) {
    [$page, $limit, $offset] = getPaginationParams(20, 200);
    $q = trim((string) ($_GET['q'] ?? ''));
    $taxId = trim((string) ($_GET['tax_id'] ?? ''));
    $phone = trim((string) ($_GET['phone'] ?? ''));

    $where = [];
    $params = [];
    if ($q !== '') {
        $where[] = '(first_name LIKE ? OR last_name LIKE ? OR CONCAT(first_name, " ", last_name) LIKE ? OR phone LIKE ? OR tax_id LIKE ?)';
        $like = '%' . $q . '%';
        $params = array_merge($params, [$like, $like, $like, $like, $like]);
    }
    if ($taxId !== '') {
        $where[] = 'tax_id LIKE ?';
        $params[] = '%' . $taxId . '%';
    }
    if ($phone !== '') {
        $where[] = 'phone LIKE ?';
        $params[] = '%' . $phone . '%';
    }

    $whereSql = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM customers $whereSql");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $sql = "SELECT id, first_name, last_name, phone, tax_id, car, notes, created_at
            FROM customers
            $whereSql
            ORDER BY id DESC
            LIMIT $limit OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $customers = $stmt->fetchAll();

    foreach ($customers as &$customer) {
        $customer['full_name'] = customerFullName($customer);
    }
    apiResponse(200, ['ok' => true] + paginatedResponse($customers, $total, $page, $limit));
}

if ($segments[0] === 'customers' && $method === 'POST' && count($segments) === 1) {
    $firstName = trim((string) ($body['first_name'] ?? ''));
    $lastName = trim((string) ($body['last_name'] ?? ''));
    $phone = trim((string) ($body['phone'] ?? ''));
    $taxId = trim((string) ($body['tax_id'] ?? ''));
    $car = trim((string) ($body['car'] ?? ''));
    $notes = trim((string) ($body['notes'] ?? ''));

    if ($firstName === '' || $lastName === '' || $phone === '' || $taxId === '') {
        apiResponse(422, ['ok' => false, 'error' => 'Campos obrigatorios: first_name, last_name, phone, tax_id.']);
    }

    try {
        $stmt = $pdo->prepare('INSERT INTO customers (first_name, last_name, phone, tax_id, car, notes) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$firstName, $lastName, $phone, $taxId, $car ?: null, $notes ?: null]);
    } catch (Throwable $e) {
        apiResponse(409, ['ok' => false, 'error' => 'Nao foi possivel criar cliente. CPF/CNPJ pode ja existir.']);
    }

    apiResponse(201, ['ok' => true, 'data' => ['id' => (int) $pdo->lastInsertId()]]);
}

if ($segments[0] === 'customers' && $method === 'GET' && count($segments) === 2) {
    $id = (int) $segments[1];
    if ($id <= 0) {
        apiResponse(422, ['ok' => false, 'error' => 'ID invalido.']);
    }
    $stmt = $pdo->prepare('SELECT id, first_name, last_name, phone, tax_id, car, notes, created_at FROM customers WHERE id = ?');
    $stmt->execute([$id]);
    $customer = $stmt->fetch();
    if (!$customer) {
        apiResponse(404, ['ok' => false, 'error' => 'Cliente nao encontrado.']);
    }
    $customer['full_name'] = customerFullName($customer);
    apiResponse(200, ['ok' => true, 'data' => $customer]);
}

if ($segments[0] === 'sales' && $method === 'GET' && count($segments) === 1) {
    [$page, $limit, $offset] = getPaginationParams(20, 200);
    $customerId = (int) ($_GET['customer_id'] ?? 0);
    $paymentStatus = trim((string) ($_GET['payment_status'] ?? ''));
    $paymentMethod = trim((string) ($_GET['payment_method'] ?? ''));
    $dateFrom = trim((string) ($_GET['date_from'] ?? ''));
    $dateTo = trim((string) ($_GET['date_to'] ?? ''));
    $q = trim((string) ($_GET['q'] ?? ''));

    $where = [];
    $params = [];
    if ($customerId > 0) {
        $where[] = 'customer_id = ?';
        $params[] = $customerId;
    }
    if ($paymentStatus !== '') {
        $where[] = 'payment_status = ?';
        $params[] = $paymentStatus;
    }
    if ($paymentMethod !== '') {
        $where[] = 'payment_method = ?';
        $params[] = $paymentMethod;
    }
    if ($dateFrom !== '') {
        $where[] = 'DATE(created_at) >= ?';
        $params[] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[] = 'DATE(created_at) <= ?';
        $params[] = $dateTo;
    }
    if ($q !== '') {
        $where[] = '(customer_name LIKE ? OR CAST(id AS CHAR) LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
    }

    $whereSql = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM sales $whereSql");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $sql = "SELECT id, customer_id, customer_name, total_amount, payment_method, payment_status, created_at
            FROM sales
            $whereSql
            ORDER BY id DESC
            LIMIT $limit OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $sales = $stmt->fetchAll();

    apiResponse(200, ['ok' => true] + paginatedResponse($sales, $total, $page, $limit));
}

if ($segments[0] === 'sales' && $method === 'POST' && count($segments) === 1) {
    $saleId = createSale($pdo, $body);
    apiResponse(201, ['ok' => true, 'data' => ['sale_id' => $saleId]]);
}

if ($segments[0] === 'sales' && $method === 'GET' && count($segments) === 2) {
    $saleId = (int) $segments[1];
    if ($saleId <= 0) {
        apiResponse(422, ['ok' => false, 'error' => 'ID de venda invalido.']);
    }
    apiResponse(200, ['ok' => true, 'data' => fetchSaleWithItems($pdo, $saleId)]);
}

if ($segments[0] === 'costs' && $method === 'GET' && count($segments) === 1) {
    [$page, $limit, $offset] = getPaginationParams(20, 200);
    $q = trim((string) ($_GET['q'] ?? ''));
    $category = trim((string) ($_GET['category'] ?? ''));
    $dateFrom = trim((string) ($_GET['date_from'] ?? ''));
    $dateTo = trim((string) ($_GET['date_to'] ?? ''));
    $amountMin = trim((string) ($_GET['amount_min'] ?? ''));
    $amountMax = trim((string) ($_GET['amount_max'] ?? ''));

    $where = [];
    $params = [];
    if ($q !== '') {
        $where[] = '(description LIKE ? OR category LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
    }
    if ($category !== '') {
        $where[] = 'category LIKE ?';
        $params[] = '%' . $category . '%';
    }
    if ($dateFrom !== '') {
        $where[] = 'cost_date >= ?';
        $params[] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[] = 'cost_date <= ?';
        $params[] = $dateTo;
    }
    if ($amountMin !== '' && is_numeric($amountMin)) {
        $where[] = 'amount >= ?';
        $params[] = (float) $amountMin;
    }
    if ($amountMax !== '' && is_numeric($amountMax)) {
        $where[] = 'amount <= ?';
        $params[] = (float) $amountMax;
    }

    $whereSql = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM costs $whereSql");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $sql = "SELECT id, description, category, amount, cost_date, created_at
            FROM costs
            $whereSql
            ORDER BY cost_date DESC, id DESC
            LIMIT $limit OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    apiResponse(200, ['ok' => true] + paginatedResponse($rows, $total, $page, $limit));
}

if ($segments[0] === 'costs' && $method === 'POST' && count($segments) === 1) {
    $description = trim((string) ($body['description'] ?? ''));
    $category = trim((string) ($body['category'] ?? ''));
    $amount = (float) ($body['amount'] ?? 0);
    $costDate = trim((string) ($body['cost_date'] ?? ''));

    if ($description === '' || $amount <= 0 || $costDate === '') {
        apiResponse(422, ['ok' => false, 'error' => 'Campos obrigatorios: description, amount, cost_date.']);
    }

    $stmt = $pdo->prepare('INSERT INTO costs (description, category, amount, cost_date) VALUES (?, ?, ?, ?)');
    $stmt->execute([$description, $category ?: null, $amount, $costDate]);
    apiResponse(201, ['ok' => true, 'data' => ['id' => (int) $pdo->lastInsertId()]]);
}

if ($segments[0] === 'costs' && $method === 'GET' && count($segments) === 2) {
    $id = (int) $segments[1];
    if ($id <= 0) {
        apiResponse(422, ['ok' => false, 'error' => 'ID invalido.']);
    }
    $stmt = $pdo->prepare('SELECT id, description, category, amount, cost_date, created_at FROM costs WHERE id = ?');
    $stmt->execute([$id]);
    $cost = $stmt->fetch();
    if (!$cost) {
        apiResponse(404, ['ok' => false, 'error' => 'Custo nao encontrado.']);
    }
    apiResponse(200, ['ok' => true, 'data' => $cost]);
}

if ($segments[0] === 'costs' && in_array($method, ['PUT', 'PATCH'], true) && count($segments) === 2) {
    $id = (int) $segments[1];
    if ($id <= 0) {
        apiResponse(422, ['ok' => false, 'error' => 'ID invalido.']);
    }

    $stmt = $pdo->prepare('SELECT id, description, category, amount, cost_date FROM costs WHERE id = ?');
    $stmt->execute([$id]);
    $current = $stmt->fetch();
    if (!$current) {
        apiResponse(404, ['ok' => false, 'error' => 'Custo nao encontrado.']);
    }

    $description = array_key_exists('description', $body) ? trim((string) $body['description']) : (string) $current['description'];
    $category = array_key_exists('category', $body) ? trim((string) $body['category']) : (string) ($current['category'] ?? '');
    $amount = array_key_exists('amount', $body) ? (float) $body['amount'] : (float) $current['amount'];
    $costDate = array_key_exists('cost_date', $body) ? trim((string) $body['cost_date']) : (string) $current['cost_date'];

    if ($description === '' || $amount <= 0 || $costDate === '') {
        apiResponse(422, ['ok' => false, 'error' => 'Campos obrigatorios: description, amount, cost_date.']);
    }

    $upd = $pdo->prepare('UPDATE costs SET description = ?, category = ?, amount = ?, cost_date = ? WHERE id = ?');
    $upd->execute([$description, $category ?: null, $amount, $costDate, $id]);

    apiResponse(200, ['ok' => true, 'data' => ['id' => $id]]);
}

if ($segments[0] === 'costs' && $method === 'DELETE' && count($segments) === 2) {
    $id = (int) $segments[1];
    if ($id <= 0) {
        apiResponse(422, ['ok' => false, 'error' => 'ID invalido.']);
    }

    $del = $pdo->prepare('DELETE FROM costs WHERE id = ?');
    $del->execute([$id]);
    if ($del->rowCount() === 0) {
        apiResponse(404, ['ok' => false, 'error' => 'Custo nao encontrado.']);
    }

    apiResponse(200, ['ok' => true, 'data' => ['deleted' => true]]);
}

apiResponse(404, ['ok' => false, 'error' => 'Rota nao encontrada.']);
