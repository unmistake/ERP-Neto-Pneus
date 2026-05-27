<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

function saleEnsureCustomerSchema(PDO $pdo): void
{
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
}

saleEnsureCustomerSchema($pdo);

$customerFirstName = trim($_POST['customer_first_name'] ?? '');
$customerLastName = trim($_POST['customer_last_name'] ?? '');
$customerName = trim($customerFirstName . ' ' . $customerLastName);
$customerId = (int) ($_POST['customer_id'] ?? 0);
$customerPhone = trim($_POST['customer_phone'] ?? '');
$customerTaxId = trim($_POST['customer_tax_id'] ?? '');
$customerCar = trim($_POST['customer_car'] ?? '');
$customerNotes = trim($_POST['customer_notes'] ?? '');
$paymentMethod = $_POST['payment_method'] ?? 'dinheiro';
$paymentStatus = $_POST['payment_status'] ?? 'paid';
$dueDate = $_POST['due_date'] ?? date('Y-m-d');
$returnPage = $_POST['return_page'] ?? 'pdv';
$allowedReturnPages = ['pdv', 'pdv_mobile', 'pdv_mobile_link'];
if (!in_array($returnPage, $allowedReturnPages, true)) {
    $returnPage = 'pdv';
}

function saleReturnPath(string $returnPage): string
{
    if ($returnPage === 'pdv_mobile_link') {
        return '../vendas/index.php';
    }
    return '../index.php?page=' . $returnPage;
}

$productIds = $_POST['items']['product_id'] ?? [];
$quantities = $_POST['items']['quantity'] ?? [];
$unitPrices = $_POST['items']['unit_price'] ?? [];

if (!in_array($paymentStatus, ['paid', 'pending'], true)) {
    flash('error', 'Status de pagamento invalido.');
    redirect(saleReturnPath($returnPage));
}

if (count($productIds) === 0) {
    flash('error', 'Adicione ao menos um item na venda.');
    redirect(saleReturnPath($returnPage));
}

$items = [];
$totalAmount = 0.0;

for ($i = 0; $i < count($productIds); $i++) {
    $productId = (int) $productIds[$i];
    $qty = (int) ($quantities[$i] ?? 0);
    $price = (float) ($unitPrices[$i] ?? 0);

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

if ($totalAmount <= 0 || count($items) === 0) {
    flash('error', 'Itens invalidos na venda.');
    redirect(saleReturnPath($returnPage));
}

try {
    $pdo->beginTransaction();

    if ($customerId > 0) {
        $customerStmt = $pdo->prepare('SELECT CONCAT(first_name, " ", last_name) AS full_name FROM customers WHERE id = ?');
        $customerStmt->execute([$customerId]);
        $customer = $customerStmt->fetch();

        if (!$customer) {
            throw new RuntimeException('Cliente selecionado nao foi encontrado.');
        }

        $customerName = (string) $customer['full_name'];
    } else {
        $customerId = null;

        if ($customerTaxId !== '') {
            $findByTaxIdStmt = $pdo->prepare(
                'SELECT id, CONCAT(first_name, " ", last_name) AS full_name FROM customers WHERE tax_id = ? LIMIT 1'
            );
            $findByTaxIdStmt->execute([$customerTaxId]);
            $existingByTaxId = $findByTaxIdStmt->fetch();

            if ($existingByTaxId) {
                $customerId = (int) $existingByTaxId['id'];
                $customerName = (string) $existingByTaxId['full_name'];
            }
        }

        if ($customerId === null && $customerName !== '') {
            $findByNameStmt = $pdo->prepare(
                'SELECT id, CONCAT(first_name, " ", last_name) AS full_name
                 FROM customers
                 WHERE LOWER(TRIM(CONCAT(first_name, " ", last_name))) = LOWER(TRIM(?))
                 LIMIT 1'
            );
            $findByNameStmt->execute([$customerName]);
            $existingCustomer = $findByNameStmt->fetch();

            if ($existingCustomer) {
                $customerId = (int) $existingCustomer['id'];
                $customerName = (string) $existingCustomer['full_name'];
            } else {
                $parts = preg_split('/\s+/', $customerName) ?: [];
                $parts = array_values(array_filter($parts, static fn ($p) => $p !== ''));
                $firstName = $customerFirstName !== '' ? $customerFirstName : ($parts[0] ?? 'Cliente');
                $lastName = $customerLastName !== '' ? $customerLastName : (count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : 'PDV');

                $taxIdToSave = $customerTaxId !== '' ? $customerTaxId : ('AUTO' . time() . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT));
                $phoneToSave = $customerPhone !== '' ? $customerPhone : '00 00000-0000';
                $createCustomerStmt = $pdo->prepare(
                    'INSERT INTO customers (first_name, last_name, phone, tax_id, car, notes) VALUES (?, ?, ?, ?, ?, ?)'
                );
                $createCustomerStmt->execute([
                    $firstName,
                    $lastName,
                    $phoneToSave,
                    $taxIdToSave,
                    $customerCar !== '' ? $customerCar : null,
                    $customerNotes !== '' ? $customerNotes : 'Criado automaticamente pelo PDV.',
                ]);

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
        $movementStmt->execute([$item['product_id'], $item['quantity'], 'Venda #' . $saleId]);
    }

    if ($paymentStatus === 'pending') {
        $desc = 'Venda #' . $saleId . ' - ' . ($customerName ?: 'Consumidor final');
        $recStmt = $pdo->prepare("INSERT INTO accounts_receivable (description, amount, due_date, status, sale_id) VALUES (?, ?, ?, 'pending', ?)");
        $recStmt->execute([$desc, $totalAmount, $dueDate, $saleId]);
    }

    $pdo->commit();
    flash('success', 'Venda finalizada com sucesso.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('error', 'Erro ao finalizar venda: ' . $e->getMessage());
}

redirect(saleReturnPath($returnPage));
