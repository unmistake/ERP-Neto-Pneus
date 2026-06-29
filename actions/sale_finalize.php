<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/customer_schema.php';
require_once __DIR__ . '/../includes/sale_schema.php';

function saleEnsureCustomerSchema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS customers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            first_name VARCHAR(80) NOT NULL,
            last_name VARCHAR(80) NOT NULL,
            email VARCHAR(160) NULL,
            phone VARCHAR(20) NOT NULL,
            tax_id VARCHAR(18) NOT NULL,
            password_hash VARCHAR(255) NULL,
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

    ensureCustomerAddressSchema($pdo);
    ensureSaleFiscalSchema($pdo);
}

saleEnsureCustomerSchema($pdo);

$customerFirstName = trim($_POST['customer_first_name'] ?? '');
$customerLastName = trim($_POST['customer_last_name'] ?? '');
$customerName = trim($customerFirstName . ' ' . $customerLastName);
$customerId = (int) ($_POST['customer_id'] ?? 0);
$customerEmail = trim($_POST['customer_email'] ?? '');
$customerPhone = trim($_POST['customer_phone'] ?? '');
$customerTaxId = trim($_POST['customer_tax_id'] ?? '');
$customerCar = trim($_POST['customer_car'] ?? '');
$customerNotes = trim($_POST['customer_notes'] ?? '');
$customerAddressStreet = trim($_POST['customer_address_street'] ?? '');
$customerAddressNumber = trim($_POST['customer_address_number'] ?? '');
$customerAddressDistrict = trim($_POST['customer_address_district'] ?? '');
$customerAddressCity = trim($_POST['customer_address_city'] ?? '');
$customerAddressState = strtoupper(substr(trim($_POST['customer_address_state'] ?? ''), 0, 2));
$customerAddressZip = trim($_POST['customer_address_zip'] ?? '');
$customerAddressCountry = trim($_POST['customer_address_country'] ?? 'Brasil');
$issueNfe = ($_POST['issue_nfe'] ?? '') === '1';
$fiscalDocumentType = $issueNfe ? 'nfe' : 'none';
$fiscalStatus = $issueNfe ? 'pending' : 'not_requested';
$paymentMethod = $_POST['payment_method'] ?? 'dinheiro';
$paymentStatus = $_POST['payment_status'] ?? 'paid';
$sellerName = trim((string) ($_POST['seller_name'] ?? ''));
$allowedSellers = ['Elias', 'Daniel', 'Felipe', 'Eriko'];
$dueDate = $_POST['due_date'] ?? date('Y-m-d');
$requestToken = strtolower(trim((string) ($_POST['request_token'] ?? '')));
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
$productNames = $_POST['items']['product_name'] ?? [];
$quantities = $_POST['items']['quantity'] ?? [];
$unitPrices = $_POST['items']['unit_price'] ?? [];

if (!in_array($paymentStatus, ['paid', 'pending'], true)) {
    flash('error', 'Status de pagamento invalido.');
    redirect(saleReturnPath($returnPage));
}

if (!in_array($sellerName, $allowedSellers, true)) {
    flash('error', 'Selecione o vendedor antes de finalizar a venda.');
    redirect(saleReturnPath($returnPage));
}

if ($issueNfe && $customerId > 0) {
    $addressFallbackStmt = $pdo->prepare(
        'SELECT address_street, address_number, address_district, address_city, address_state, address_zip, address_country
         FROM customers
         WHERE id = ?
         LIMIT 1'
    );
    $addressFallbackStmt->execute([$customerId]);
    $addressFallback = $addressFallbackStmt->fetch();

    if ($addressFallback) {
        $customerAddressStreet = $customerAddressStreet !== '' ? $customerAddressStreet : (string) ($addressFallback['address_street'] ?? '');
        $customerAddressNumber = $customerAddressNumber !== '' ? $customerAddressNumber : (string) ($addressFallback['address_number'] ?? '');
        $customerAddressDistrict = $customerAddressDistrict !== '' ? $customerAddressDistrict : (string) ($addressFallback['address_district'] ?? '');
        $customerAddressCity = $customerAddressCity !== '' ? $customerAddressCity : (string) ($addressFallback['address_city'] ?? '');
        $customerAddressState = $customerAddressState !== '' ? $customerAddressState : strtoupper(substr((string) ($addressFallback['address_state'] ?? ''), 0, 2));
        $customerAddressZip = $customerAddressZip !== '' ? $customerAddressZip : (string) ($addressFallback['address_zip'] ?? '');
        $customerAddressCountry = $customerAddressCountry !== '' ? $customerAddressCountry : (string) ($addressFallback['address_country'] ?? 'Brasil');
    }
}

if ($issueNfe) {
    $missingAddressFields = [];
    if ($customerAddressZip === '') {
        $missingAddressFields[] = 'CEP';
    }
    if ($customerAddressStreet === '') {
        $missingAddressFields[] = 'logradouro';
    }
    if ($customerAddressNumber === '') {
        $missingAddressFields[] = 'numero';
    }
    if ($customerAddressDistrict === '') {
        $missingAddressFields[] = 'bairro';
    }
    if ($customerAddressCity === '') {
        $missingAddressFields[] = 'cidade';
    }
    if ($customerAddressState === '') {
        $missingAddressFields[] = 'UF';
    }
    if ($customerAddressCountry === '') {
        $missingAddressFields[] = 'pais';
    }

    if (count($missingAddressFields) > 0) {
        flash('error', 'Para emitir NF-e, preencha o endereco do cliente: ' . implode(', ', $missingAddressFields) . '.');
        redirect(saleReturnPath($returnPage));
    }
}

if (!preg_match('/^[a-f0-9]{64}$/', $requestToken)) {
    flash('error', 'A sessao da venda expirou. Recarregue o PDV e tente novamente.');
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
    $productName = trim((string) ($productNames[$i] ?? ''));
    $qty = (int) ($quantities[$i] ?? 0);
    $price = (float) ($unitPrices[$i] ?? 0);

    if ($qty <= 0 || $price < 0) {
        continue;
    }

    if ($productId <= 0 && $productName === '') {
        continue;
    }

    $lineTotal = $qty * $price;
    $items[] = [
        'product_id' => $productId,
        'product_name' => $productName,
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

// --- Linhas de pagamento (pagamento multiplo e troca) ---
// O PDV desktop envia payments[method][], payments[amount][] e, para troca,
// payments[troca_desc][] e payments[troca_qty][]. O PDV mobile e o link de venda
// continuam enviando o pagamento unico antigo (payment_method/payment_status).
$allowedPaymentMethods = ['dinheiro', 'pix', 'cartao', 'prazo', 'troca'];
$paymentMethodsIn = $_POST['payments']['method'] ?? [];
$paymentAmountsIn = $_POST['payments']['amount'] ?? [];
$trocaDescIn = $_POST['payments']['troca_desc'] ?? [];
$trocaQtyIn = $_POST['payments']['troca_qty'] ?? [];

$paymentLines = [];
if (is_array($paymentMethodsIn) && count($paymentMethodsIn) > 0) {
    for ($i = 0; $i < count($paymentMethodsIn); $i++) {
        $method = (string) ($paymentMethodsIn[$i] ?? '');
        $amount = round((float) ($paymentAmountsIn[$i] ?? 0), 2);
        if (!in_array($method, $allowedPaymentMethods, true) || $amount <= 0) {
            continue;
        }
        $paymentLines[] = [
            'method' => $method,
            'amount' => $amount,
            'troca_desc' => trim((string) ($trocaDescIn[$i] ?? '')),
            'troca_qty' => max(1, (int) ($trocaQtyIn[$i] ?? 1)),
        ];
    }
}

if (count($paymentLines) > 0) {
    $paidSum = array_sum(array_column($paymentLines, 'amount'));
    if (abs($paidSum - $totalAmount) > 0.01) {
        flash('error', 'Os pagamentos somam ' . money($paidSum) . ', mas o total da venda e ' . money($totalAmount) . '. Ajuste os valores.');
        redirect(saleReturnPath($returnPage));
    }

    $prazoTotal = 0.0;
    $methodsPresent = [];
    foreach ($paymentLines as $line) {
        if ($line['method'] === 'prazo') {
            $prazoTotal += $line['amount'];
        }
        $methodsPresent[$line['method']] = true;
    }
    $paymentStatus = $prazoTotal > 0.009 ? 'pending' : 'paid';
    $paymentMethod = substr(implode('+', array_keys($methodsPresent)), 0, 30);
} else {
    // Compatibilidade: sintetiza uma unica linha a partir do pagamento antigo.
    $singleMethod = in_array($paymentMethod, $allowedPaymentMethods, true) ? $paymentMethod : 'dinheiro';
    $paymentLines[] = [
        'method' => $singleMethod,
        'amount' => round($totalAmount, 2),
        'troca_desc' => '',
        'troca_qty' => 1,
    ];
    $prazoTotal = ($paymentStatus === 'pending') ? $totalAmount : 0.0;
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
                    'INSERT INTO customers
                        (first_name, last_name, email, phone, tax_id, car, notes, address_street, address_number, address_district, address_city, address_state, address_zip, address_country)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $createCustomerStmt->execute([
                    $firstName,
                    $lastName,
                    $customerEmail !== '' ? $customerEmail : null,
                    $phoneToSave,
                    $taxIdToSave,
                    $customerCar !== '' ? $customerCar : null,
                    $customerNotes !== '' ? $customerNotes : 'Criado automaticamente pelo PDV.',
                    $customerAddressStreet !== '' ? $customerAddressStreet : null,
                    $customerAddressNumber !== '' ? $customerAddressNumber : null,
                    $customerAddressDistrict !== '' ? $customerAddressDistrict : null,
                    $customerAddressCity !== '' ? $customerAddressCity : null,
                    $customerAddressState !== '' ? $customerAddressState : null,
                    $customerAddressZip !== '' ? $customerAddressZip : null,
                    $customerAddressCountry !== '' ? $customerAddressCountry : 'Brasil',
                ]);

                $customerId = (int) $pdo->lastInsertId();
                $customerName = trim($firstName . ' ' . $lastName);
            }
        }
    }

    if ($customerId !== null && $customerEmail !== '') {
        $emailUpdateStmt = $pdo->prepare('UPDATE customers SET email = ? WHERE id = ?');
        $emailUpdateStmt->execute([$customerEmail, $customerId]);
    }

    if ($customerId !== null && (
        $customerAddressStreet !== '' ||
        $customerAddressNumber !== '' ||
        $customerAddressDistrict !== '' ||
        $customerAddressCity !== '' ||
        $customerAddressState !== '' ||
        $customerAddressZip !== ''
    )) {
        $addressUpdateStmt = $pdo->prepare(
            'UPDATE customers
             SET address_street = ?, address_number = ?, address_district = ?, address_city = ?, address_state = ?, address_zip = ?, address_country = ?
             WHERE id = ?'
        );
        $addressUpdateStmt->execute([
            $customerAddressStreet !== '' ? $customerAddressStreet : null,
            $customerAddressNumber !== '' ? $customerAddressNumber : null,
            $customerAddressDistrict !== '' ? $customerAddressDistrict : null,
            $customerAddressCity !== '' ? $customerAddressCity : null,
            $customerAddressState !== '' ? $customerAddressState : null,
            $customerAddressZip !== '' ? $customerAddressZip : null,
            $customerAddressCountry !== '' ? $customerAddressCountry : 'Brasil',
            $customerId,
        ]);
    }

    $saleStmt = $pdo->prepare('INSERT INTO sales (request_token, customer_id, customer_name, seller_name, total_amount, payment_method, payment_status, fiscal_document_type, fiscal_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $saleStmt->execute([$requestToken, $customerId, $customerName ?: null, $sellerName, $totalAmount, $paymentMethod, $paymentStatus, $fiscalDocumentType, $fiscalStatus]);
    $saleId = (int) $pdo->lastInsertId();

    $stockStmt = $pdo->prepare('SELECT name, stock_qty FROM products WHERE id = ? FOR UPDATE');
    $findProductByNameStmt = $pdo->prepare('SELECT id, name, stock_qty FROM products WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1 FOR UPDATE');
    $createProductStmt = $pdo->prepare(
        "INSERT INTO products
            (name, cost_price, sale_price, stock_qty)
         VALUES
            (?, 0, ?, ?)"
    );
    $saleItemStmt = $pdo->prepare('INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, line_total) VALUES (?, ?, ?, ?, ?)');
    $updateStockStmt = $pdo->prepare('UPDATE products SET stock_qty = ? WHERE id = ?');
    $movementStmt = $pdo->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, note) VALUES (?, 'out', ?, ?)");
    $initialMovementStmt = $pdo->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, note) VALUES (?, 'in', ?, ?)");

    foreach ($items as $item) {
        if ((int) $item['product_id'] <= 0) {
            $findProductByNameStmt->execute([$item['product_name']]);
            $existingProduct = $findProductByNameStmt->fetch();

            if ($existingProduct) {
                $item['product_id'] = (int) $existingProduct['id'];
            } else {
                $createProductStmt->execute([$item['product_name'], $item['unit_price'], $item['quantity']]);
                $item['product_id'] = (int) $pdo->lastInsertId();
                $initialMovementStmt->execute([$item['product_id'], $item['quantity'], 'Cadastro automatico pelo PDV na venda #' . $saleId]);
            }
        }

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

    // Linhas de pagamento; itens recebidos em troca entram no estoque como usados.
    $insertPaymentStmt = $pdo->prepare('INSERT INTO sale_payments (sale_id, method, amount, note) VALUES (?, ?, ?, ?)');
    $createUsedProductStmt = $pdo->prepare("INSERT INTO products (name, item_condition, cost_price, sale_price, stock_qty) VALUES (?, 'usado', ?, 0, ?)");

    foreach ($paymentLines as $line) {
        $note = null;
        if ($line['method'] === 'troca') {
            $desc = $line['troca_desc'];
            if ($desc !== '') {
                $qty = $line['troca_qty'];
                $unitCost = $qty > 0 ? round($line['amount'] / $qty, 2) : $line['amount'];
                $createUsedProductStmt->execute([$desc . ' (usado - troca venda #' . $saleId . ')', $unitCost, $qty]);
                $tradeProductId = (int) $pdo->lastInsertId();
                $initialMovementStmt->execute([$tradeProductId, $qty, 'Entrada por troca na venda #' . $saleId]);
                $note = $desc . ' (qtd ' . $qty . ')';
            } else {
                $note = 'Troca';
            }
        }
        $insertPaymentStmt->execute([$saleId, $line['method'], $line['amount'], $note]);
    }

    if ($prazoTotal > 0.009) {
        $desc = 'Venda #' . $saleId . ' - ' . ($customerName ?: 'Consumidor final');
        $recStmt = $pdo->prepare("INSERT INTO accounts_receivable (description, amount, due_date, status, sale_id) VALUES (?, ?, ?, 'pending', ?)");
        $recStmt->execute([$desc, $prazoTotal, $dueDate, $saleId]);
    }

    $pdo->commit();
    flash('success', 'Venda finalizada com sucesso.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $isRepeatedRequest = $e instanceof PDOException
        && (int) ($e->errorInfo[1] ?? 0) === 1062
        && str_contains($e->getMessage(), 'uk_sales_request_token');

    if ($isRepeatedRequest) {
        flash('success', 'Esta venda ja havia sido finalizada. O envio repetido foi ignorado.');
    } else {
        flash('error', 'Erro ao finalizar venda: ' . $e->getMessage());
    }
}

redirect(saleReturnPath($returnPage));
