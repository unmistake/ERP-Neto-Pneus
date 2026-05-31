<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/customer_schema.php';

function crmEnsureSchema(PDO $pdo): void
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

    ensureCustomerAddressSchema($pdo);
}

crmEnsureSchema($pdo);

$editingCustomerId = (int) ($_GET['edit_id'] ?? 0);
$editingCustomer = null;

if ($editingCustomerId > 0) {
    $editStmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
    $editStmt->execute([$editingCustomerId]);
    $editingCustomer = $editStmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';

    if ($action === 'delete') {
        $customerId = (int) ($_POST['customer_id'] ?? 0);
        if ($customerId <= 0) {
            flash('error', 'Cliente invalido para exclusao.');
            redirect('index.php?page=crm');
        }

        $hasSalesStmt = $pdo->prepare('SELECT COUNT(*) FROM sales WHERE customer_id = ?');
        $hasSalesStmt->execute([$customerId]);
        $hasSales = (int) $hasSalesStmt->fetchColumn() > 0;

        if ($hasSales) {
            flash('error', 'Nao e possivel excluir cliente com vendas vinculadas.');
            redirect('index.php?page=crm');
        }

        $deleteStmt = $pdo->prepare('DELETE FROM customers WHERE id = ?');
        $deleteStmt->execute([$customerId]);
        flash('success', 'Cliente excluido com sucesso.');
        redirect('index.php?page=crm');
    }

    $customerId = (int) ($_POST['customer_id'] ?? 0);
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $taxId = trim($_POST['tax_id'] ?? '');
    $car = trim($_POST['car'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $addressStreet = trim($_POST['address_street'] ?? '');
    $addressNumber = trim($_POST['address_number'] ?? '');
    $addressDistrict = trim($_POST['address_district'] ?? '');
    $addressCity = trim($_POST['address_city'] ?? '');
    $addressState = strtoupper(substr(trim($_POST['address_state'] ?? ''), 0, 2));
    $addressZip = trim($_POST['address_zip'] ?? '');
    $addressCountry = trim($_POST['address_country'] ?? 'Brasil');

    if ($firstName === '' || $lastName === '' || $phone === '' || $taxId === '') {
        flash('error', 'Preencha nome, sobrenome, telefone e CPF/CNPJ.');
        redirect('index.php?page=crm');
    }

    try {
        if ($customerId > 0) {
            $stmt = $pdo->prepare(
                'UPDATE customers
                 SET first_name = ?, last_name = ?, phone = ?, tax_id = ?, car = ?, notes = ?,
                     address_street = ?, address_number = ?, address_district = ?, address_city = ?,
                     address_state = ?, address_zip = ?, address_country = ?
                 WHERE id = ?'
            );
            $stmt->execute([
                $firstName,
                $lastName,
                $phone,
                $taxId,
                $car ?: null,
                $notes ?: null,
                $addressStreet ?: null,
                $addressNumber ?: null,
                $addressDistrict ?: null,
                $addressCity ?: null,
                $addressState ?: null,
                $addressZip ?: null,
                $addressCountry ?: 'Brasil',
                $customerId,
            ]);
            flash('success', 'Cliente atualizado com sucesso.');
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO customers
                    (first_name, last_name, phone, tax_id, car, notes, address_street, address_number, address_district, address_city, address_state, address_zip, address_country)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $firstName,
                $lastName,
                $phone,
                $taxId,
                $car ?: null,
                $notes ?: null,
                $addressStreet ?: null,
                $addressNumber ?: null,
                $addressDistrict ?: null,
                $addressCity ?: null,
                $addressState ?: null,
                $addressZip ?: null,
                $addressCountry ?: 'Brasil',
            ]);
            flash('success', 'Cliente cadastrado com sucesso.');
        }
    } catch (Throwable $e) {
        flash('error', 'Nao foi possivel salvar cliente. CPF/CNPJ pode ja existir.');
    }

    redirect('index.php?page=crm');
}

$customers = $pdo->query(
    'SELECT c.*,
            COUNT(s.id) AS total_sales,
            COALESCE(SUM(s.total_amount), 0) AS total_spent
     FROM customers c
     LEFT JOIN sales s ON s.customer_id = c.id
     GROUP BY c.id
     ORDER BY c.id DESC'
)->fetchAll();

$selectedCustomerId = (int) ($_GET['customer_id'] ?? 0);
$selectedCustomer = null;
$customerSales = [];
$saleItemsBySaleId = [];

if ($selectedCustomerId > 0) {
    $selStmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
    $selStmt->execute([$selectedCustomerId]);
    $selectedCustomer = $selStmt->fetch();

    if ($selectedCustomer) {
        $salesStmt = $pdo->prepare(
            'SELECT s.id, s.created_at, s.total_amount, s.payment_method, s.payment_status
             FROM sales s
             WHERE s.customer_id = ?
             ORDER BY s.id DESC'
        );
        $salesStmt->execute([$selectedCustomerId]);
        $customerSales = $salesStmt->fetchAll();

        if (count($customerSales) > 0) {
            $saleIds = array_map(static fn ($sale) => (int) $sale['id'], $customerSales);
            $placeholders = implode(',', array_fill(0, count($saleIds), '?'));
            $itemsStmt = $pdo->prepare(
                "SELECT si.sale_id, si.quantity, p.name AS product_name, p.brand, p.model
                 FROM sale_items si
                 INNER JOIN products p ON p.id = si.product_id
                 WHERE si.sale_id IN ($placeholders)
                 ORDER BY si.sale_id DESC, si.id ASC"
            );
            $itemsStmt->execute($saleIds);
            $saleItems = $itemsStmt->fetchAll();

            foreach ($saleItems as $item) {
                $sid = (int) $item['sale_id'];
                if (!isset($saleItemsBySaleId[$sid])) {
                    $saleItemsBySaleId[$sid] = [];
                }
                $saleItemsBySaleId[$sid][] = $item;
            }
        }
    }
}
?>

<h2 class="text-2xl font-bold mb-4">CRM</h2>

<div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-6">
    <form method="post" class="bg-white rounded-lg shadow p-4 space-y-3">
        <h3 class="font-semibold"><?= $editingCustomer ? 'Editar cliente' : 'Novo cliente' ?></h3>
        <?php if ($editingCustomer): ?>
            <input type="hidden" name="customer_id" value="<?= (int) $editingCustomer['id'] ?>">
        <?php endif; ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <input required name="first_name" placeholder="Nome" value="<?= htmlspecialchars((string) ($editingCustomer['first_name'] ?? '')) ?>" class="w-full border rounded px-3 py-2">
            <input required name="last_name" placeholder="Sobrenome" value="<?= htmlspecialchars((string) ($editingCustomer['last_name'] ?? '')) ?>" class="w-full border rounded px-3 py-2">
        </div>
        <input required name="phone" id="phone" placeholder="Telefone (xx xxxxx-xxxx)" value="<?= htmlspecialchars((string) ($editingCustomer['phone'] ?? '')) ?>" class="w-full border rounded px-3 py-2">
        <input required name="tax_id" id="tax_id" placeholder="CPF/CNPJ" value="<?= htmlspecialchars((string) ($editingCustomer['tax_id'] ?? '')) ?>" class="w-full border rounded px-3 py-2">
        <input name="car" placeholder="Carro" value="<?= htmlspecialchars((string) ($editingCustomer['car'] ?? '')) ?>" class="w-full border rounded px-3 py-2">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <input name="address_street" placeholder="Logradouro" value="<?= htmlspecialchars((string) ($editingCustomer['address_street'] ?? '')) ?>" class="w-full border rounded px-3 py-2">
            <input name="address_number" placeholder="Numero" value="<?= htmlspecialchars((string) ($editingCustomer['address_number'] ?? '')) ?>" class="w-full border rounded px-3 py-2">
            <input name="address_district" placeholder="Bairro" value="<?= htmlspecialchars((string) ($editingCustomer['address_district'] ?? '')) ?>" class="w-full border rounded px-3 py-2">
            <input name="address_city" placeholder="Cidade" value="<?= htmlspecialchars((string) ($editingCustomer['address_city'] ?? '')) ?>" class="w-full border rounded px-3 py-2">
            <input name="address_state" maxlength="2" placeholder="UF" value="<?= htmlspecialchars((string) ($editingCustomer['address_state'] ?? '')) ?>" class="w-full border rounded px-3 py-2 uppercase">
            <input name="address_zip" placeholder="CEP" value="<?= htmlspecialchars((string) ($editingCustomer['address_zip'] ?? '')) ?>" class="w-full border rounded px-3 py-2">
            <input name="address_country" placeholder="Pais" value="<?= htmlspecialchars((string) ($editingCustomer['address_country'] ?? 'Brasil')) ?>" class="w-full border rounded px-3 py-2 md:col-span-2">
        </div>
        <textarea name="notes" placeholder="Observacoes" class="w-full border rounded px-3 py-2 h-24"><?= htmlspecialchars((string) ($editingCustomer['notes'] ?? '')) ?></textarea>
        <button class="w-full bg-emerald-600 text-white rounded px-4 py-2"><?= $editingCustomer ? 'Atualizar cliente' : 'Salvar cliente' ?></button>
        <?php if ($editingCustomer): ?>
            <a href="index.php?page=crm" class="block text-center bg-slate-200 rounded px-4 py-2">Cancelar edicao</a>
        <?php endif; ?>
    </form>

    <div class="bg-white rounded-lg shadow overflow-auto">
        <h3 class="font-semibold p-4">Clientes cadastrados</h3>
        <table class="w-full text-sm">
            <thead class="bg-slate-200">
            <tr>
                <th class="p-3 text-left">Cliente</th>
                <th class="p-3 text-left">Tipo</th>
                <th class="p-3 text-left">Telefone</th>
                <th class="p-3 text-left">CPF/CNPJ</th>
                <th class="p-3 text-left">Compras</th>
                <th class="p-3 text-left">Acoes</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($customers as $customer): ?>
                <?php $isClient = (int) $customer['total_sales'] > 0; ?>
                <tr class="border-t">
                    <td class="p-3"><?= htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']) ?></td>
                    <td class="p-3">
                        <?php if ($isClient): ?>
                            <span class="inline-block px-2 py-1 rounded bg-emerald-100 text-emerald-800 text-xs font-semibold">Cliente</span>
                        <?php else: ?>
                            <span class="inline-block px-2 py-1 rounded bg-amber-100 text-amber-800 text-xs font-semibold">Lead</span>
                        <?php endif; ?>
                    </td>
                    <td class="p-3"><?= htmlspecialchars($customer['phone']) ?></td>
                    <td class="p-3"><?= htmlspecialchars($customer['tax_id']) ?></td>
                    <td class="p-3"><?= (int) $customer['total_sales'] ?> (<?= money((float) $customer['total_spent']) ?>)</td>
                    <td class="p-3 space-x-2">
                        <a class="text-blue-700 underline" href="index.php?page=crm&customer_id=<?= (int) $customer['id'] ?>">Ver historico</a>
                        <a class="text-amber-700 underline" href="index.php?page=crm&edit_id=<?= (int) $customer['id'] ?>">Editar</a>
                        <form method="post" class="inline" onsubmit="return confirm('Tem certeza que deseja excluir este cliente?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="customer_id" value="<?= (int) $customer['id'] ?>">
                            <button type="submit" class="text-rose-700 underline">Excluir</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="bg-white rounded-lg shadow overflow-auto">
    <h3 class="font-semibold p-4">
        Historico de compras
        <?php if ($selectedCustomer): ?>
            - <?= htmlspecialchars($selectedCustomer['first_name'] . ' ' . $selectedCustomer['last_name']) ?>
        <?php endif; ?>
    </h3>
    <table class="w-full text-sm">
        <thead class="bg-slate-200">
        <tr>
            <th class="p-3 text-left">Data</th>
            <th class="p-3 text-left">Venda</th>
            <th class="p-3 text-left">Forma pagamento</th>
            <th class="p-3 text-left">Status</th>
            <th class="p-3 text-left">Total</th>
            <th class="p-3 text-left">Itens (marca/modelo)</th>
        </tr>
        </thead>
        <tbody>
        <?php if (!$selectedCustomer): ?>
            <tr class="border-t"><td class="p-3" colspan="6">Selecione um cliente para ver as compras antigas.</td></tr>
        <?php elseif (count($customerSales) === 0): ?>
            <tr class="border-t"><td class="p-3" colspan="6">Nenhuma compra registrada para este cliente.</td></tr>
        <?php else: ?>
            <?php foreach ($customerSales as $sale): ?>
                <tr class="border-t">
                    <td class="p-3"><?= htmlspecialchars($sale['created_at']) ?></td>
                    <td class="p-3">#<?= (int) $sale['id'] ?></td>
                    <td class="p-3"><?= htmlspecialchars($sale['payment_method']) ?></td>
                    <td class="p-3"><?= $sale['payment_status'] === 'paid' ? 'Pago' : 'Pendente' ?></td>
                    <td class="p-3"><?= money((float) $sale['total_amount']) ?></td>
                    <td class="p-3">
                        <?php
                        $saleItems = $saleItemsBySaleId[(int) $sale['id']] ?? [];
                        if (count($saleItems) === 0):
                            echo '-';
                        else:
                            foreach ($saleItems as $item):
                                $brand = trim((string) ($item['brand'] ?? ''));
                                $model = trim((string) ($item['model'] ?? ''));
                                $brandModel = trim($brand . ($model !== '' ? ' / ' . $model : ''));
                                ?>
                                <div><?= htmlspecialchars($item['product_name']) ?> x<?= (int) $item['quantity'] ?><?= $brandModel !== '' ? ' - ' . htmlspecialchars($brandModel) : '' ?></div>
                            <?php
                            endforeach;
                        endif;
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
const phoneInput = document.getElementById('phone');
const taxIdInput = document.getElementById('tax_id');

phoneInput.addEventListener('input', () => {
    const digits = phoneInput.value.replace(/\D/g, '').slice(0, 11);
    if (digits.length <= 2) {
        phoneInput.value = digits;
        return;
    }
    if (digits.length <= 7) {
        phoneInput.value = `${digits.slice(0, 2)} ${digits.slice(2)}`;
        return;
    }
    phoneInput.value = `${digits.slice(0, 2)} ${digits.slice(2, 7)}-${digits.slice(7)}`;
});

taxIdInput.addEventListener('input', () => {
    const digits = taxIdInput.value.replace(/\D/g, '').slice(0, 14);
    if (digits.length <= 11) {
        let v = digits;
        v = v.replace(/(\d{3})(\d)/, '$1.$2');
        v = v.replace(/(\d{3})(\d)/, '$1.$2');
        v = v.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
        taxIdInput.value = v;
        return;
    }

    let v = digits;
    v = v.replace(/^(\d{2})(\d)/, '$1.$2');
    v = v.replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3');
    v = v.replace(/\.(\d{3})(\d)/, '.$1/$2');
    v = v.replace(/(\d{4})(\d)/, '$1-$2');
    taxIdInput.value = v;
});
</script>
