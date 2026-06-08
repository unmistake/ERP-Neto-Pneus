<?php
function pdvEnsureCustomerSchema(PDO $pdo): void
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

    require_once __DIR__ . '/../includes/customer_schema.php';
    require_once __DIR__ . '/../includes/sale_schema.php';
    ensureCustomerAddressSchema($pdo);
    ensureSaleFiscalSchema($pdo);
}

pdvEnsureCustomerSchema($pdo);

$products = $pdo->query('SELECT id, name, sale_price AS price, stock_qty FROM products ORDER BY name')->fetchAll();
$customers = $pdo->query("SELECT id, first_name, last_name, CONCAT(first_name, ' ', last_name) AS full_name, email, phone, tax_id, car, notes, address_street, address_number, address_district, address_city, address_state, address_zip, address_country FROM customers ORDER BY first_name, last_name")->fetchAll();
$todaySales = $pdo->query('SELECT id, customer_name, seller_name, total_amount, payment_status, fiscal_document_type, fiscal_status, created_at FROM sales ORDER BY id DESC LIMIT 10')->fetchAll();
$salesDetails = [];
$sellers = ['Elias', 'Daniel', 'Felipe', 'Eriko'];
$saleRequestToken = bin2hex(random_bytes(32));

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS fiscal_documents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sale_id INT NOT NULL,
        document_type ENUM('nfce','nfe') NOT NULL DEFAULT 'nfe',
        reference_code VARCHAR(64) NOT NULL,
        environment ENUM('homologacao','producao') NOT NULL DEFAULT 'homologacao',
        status VARCHAR(30) NOT NULL DEFAULT 'pendente',
        focus_id VARCHAR(120) NULL,
            access_key VARCHAR(64) NULL,
            number VARCHAR(30) NULL,
            series VARCHAR(20) NULL,
            danfe_path VARCHAR(255) NULL,
            xml_path VARCHAR(255) NULL,
            message TEXT NULL,
        request_payload LONGTEXT NULL,
        response_payload LONGTEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_fiscal_reference (reference_code),
        KEY idx_fiscal_sale (sale_id),
        CONSTRAINT fk_fiscal_sale FOREIGN KEY (sale_id) REFERENCES sales(id)
    )"
);
$fiscalExtraColumns = [
    'danfe_path' => "ALTER TABLE fiscal_documents ADD COLUMN danfe_path VARCHAR(255) NULL AFTER series",
    'xml_path' => "ALTER TABLE fiscal_documents ADD COLUMN xml_path VARCHAR(255) NULL AFTER danfe_path",
];
foreach ($fiscalExtraColumns as $column => $sql) {
    $exists = (bool) $pdo->query("SHOW COLUMNS FROM fiscal_documents LIKE " . $pdo->quote($column))->fetch();
    if (!$exists) {
        $pdo->exec($sql);
    }
}
$pdo->exec("ALTER TABLE sales MODIFY fiscal_status ENUM('not_requested','pending','issued','failed','cancelled') NOT NULL DEFAULT 'not_requested'");
$pdo->exec("UPDATE fiscal_documents SET document_type = 'nfe' WHERE document_type = 'nfce' AND reference_code LIKE 'NFE%'");
$pdo->exec(
    "UPDATE sales s
     INNER JOIN fiscal_documents fd ON fd.sale_id = s.id
     INNER JOIN (
        SELECT sale_id, MAX(id) AS max_id
        FROM fiscal_documents
        GROUP BY sale_id
     ) latest ON latest.max_id = fd.id
     SET s.fiscal_status = 'failed'
     WHERE s.fiscal_document_type = 'nfe'
       AND s.fiscal_status = 'issued'
       AND fd.status IN ('rejeitado', 'erro_envio')"
);

if (count($todaySales) > 0) {
    $saleIds = array_map(static fn ($sale) => (int) $sale['id'], $todaySales);
    $placeholders = implode(',', array_fill(0, count($saleIds), '?'));
    $salesByIdStmt = $pdo->prepare(
        "SELECT id, customer_name, seller_name, total_amount, payment_method, payment_status, fiscal_document_type, fiscal_status, created_at
         FROM sales
         WHERE id IN ($placeholders)"
    );
    $salesByIdStmt->execute($saleIds);
    $salesById = $salesByIdStmt->fetchAll();

    foreach ($salesById as $sale) {
        $id = (int) $sale['id'];
        $salesDetails[$id] = [
            'sale' => $sale,
            'items' => [],
        ];
    }

    $saleItemsStmt = $pdo->prepare(
        "SELECT si.sale_id, si.quantity, si.unit_price, si.line_total, p.name AS product_name, p.brand, p.model
         FROM sale_items si
         INNER JOIN products p ON p.id = si.product_id
         WHERE si.sale_id IN ($placeholders)
         ORDER BY si.sale_id DESC, si.id ASC"
    );
    $saleItemsStmt->execute($saleIds);
    $allItems = $saleItemsStmt->fetchAll();

    foreach ($allItems as $item) {
        $sid = (int) $item['sale_id'];
        if (!isset($salesDetails[$sid])) {
            continue;
        }
        $salesDetails[$sid]['items'][] = $item;
    }

}
?>

<h2 class="text-2xl font-bold mb-4">PDV Rapido</h2>

<form method="post" action="actions/sale_finalize.php" data-sale-form class="bg-white rounded-lg shadow p-4 mb-6">
    <input type="hidden" name="request_token" value="<?= htmlspecialchars($saleRequestToken) ?>">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
        <div class="relative">
            <input type="hidden" name="customer_id" id="customer_id">
            <input id="customer_search" autocomplete="off" placeholder="Buscar cliente cadastrado" class="w-full border rounded px-3 py-2">
            <div id="customer_suggestions" class="hidden absolute z-10 mt-1 w-full max-h-48 overflow-auto bg-white border rounded shadow"></div>
        </div>
        <input name="customer_first_name" id="customer_first_name" placeholder="Nome" class="border rounded px-3 py-2">
        <input name="customer_last_name" id="customer_last_name" placeholder="Sobrenome" class="border rounded px-3 py-2">
        <input type="text" inputmode="email" name="customer_email" id="customer_email" placeholder="E-mail (opcional)" class="border rounded px-3 py-2">
        <input name="customer_phone" id="customer_phone" placeholder="Telefone (xx xxxxx-xxxx)" class="border rounded px-3 py-2">
        <input name="customer_tax_id" id="customer_tax_id" placeholder="CPF/CNPJ" class="border rounded px-3 py-2">
        <input name="customer_car" id="customer_car" placeholder="Carro" class="border rounded px-3 py-2">
        <input name="customer_notes" id="customer_notes" placeholder="Observacoes" class="border rounded px-3 py-2">
        <label class="flex items-center gap-2 border rounded px-3 py-2 bg-slate-50">
            <input type="checkbox" name="issue_nfe" value="1" class="h-4 w-4">
            <span class="font-medium">NF-e</span>
        </label>
        <input name="customer_address_street" id="customer_address_street" placeholder="Logradouro" class="border rounded px-3 py-2">
        <input name="customer_address_number" id="customer_address_number" placeholder="Numero" class="border rounded px-3 py-2">
        <input name="customer_address_district" id="customer_address_district" placeholder="Bairro" class="border rounded px-3 py-2">
        <input name="customer_address_city" id="customer_address_city" placeholder="Cidade" class="border rounded px-3 py-2">
        <input name="customer_address_state" id="customer_address_state" maxlength="2" placeholder="UF" class="border rounded px-3 py-2 uppercase">
        <input name="customer_address_zip" id="customer_address_zip" placeholder="CEP" class="border rounded px-3 py-2">
        <input name="customer_address_country" id="customer_address_country" placeholder="Pais" value="Brasil" class="border rounded px-3 py-2">
        <select required name="seller_name" class="border rounded px-3 py-2">
            <option value="">Selecione o vendedor</option>
            <?php foreach ($sellers as $seller): ?>
                <option value="<?= htmlspecialchars($seller) ?>"><?= htmlspecialchars($seller) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="payment_method" class="border rounded px-3 py-2">
            <option value="dinheiro">Dinheiro</option>
            <option value="pix">PIX</option>
            <option value="cartao">Cartao</option>
            <option value="prazo">A prazo</option>
        </select>
        <select name="payment_status" class="border rounded px-3 py-2">
            <option value="paid">Pago</option>
            <option value="pending">Pendente</option>
        </select>
        <input type="date" name="due_date" class="border rounded px-3 py-2">
    </div>

    <div id="items" class="space-y-2"></div>
    <button type="button" onclick="addItem()" class="mb-4 bg-slate-200 rounded px-3 py-2 text-sm">+ Adicionar item</button>

    <button data-sale-submit class="w-full bg-emerald-600 text-white rounded px-4 py-2 disabled:cursor-not-allowed disabled:bg-emerald-400">Finalizar venda</button>
</form>

<div class="bg-white rounded-lg shadow overflow-auto">
    <h3 class="font-semibold p-4">Ultimas vendas</h3>
    <table class="w-full text-sm">
        <thead class="bg-slate-200">
            <tr>
                <th class="p-3 text-left">Data</th>
                <th class="p-3 text-left">Cliente</th>
                <th class="p-3 text-left">Vendedor</th>
                <th class="p-3 text-left">Total</th>
                <th class="p-3 text-left">Status</th>
                <th class="p-3 text-left">Fiscal</th>
                <th class="p-3 text-left">Acoes</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($todaySales as $sale): ?>
                <tr class="border-t">
                    <td class="p-3"><?= htmlspecialchars($sale['created_at']) ?></td>
                    <td class="p-3"><?= htmlspecialchars($sale['customer_name'] ?: 'Consumidor final') ?></td>
                    <td class="p-3"><?= htmlspecialchars((string) ($sale['seller_name'] ?? '-')) ?></td>
                    <td class="p-3"><?= money((float) $sale['total_amount']) ?></td>
                    <td class="p-3"><?= $sale['payment_status'] === 'paid' ? 'Pago' : 'Pendente' ?></td>
                    <td class="p-3">
                        <?php if (($sale['fiscal_document_type'] ?? 'none') === 'nfe'): ?>
                            <span class="inline-block px-2 py-1 rounded bg-emerald-100 text-emerald-800 text-xs font-semibold">NF-e</span>
                            <div class="text-xs text-slate-600 mt-1"><?= htmlspecialchars((string) ($sale['fiscal_status'] ?? 'pending')) ?></div>
                        <?php else: ?>
                            <span class="inline-block px-2 py-1 rounded bg-slate-100 text-slate-700 text-xs font-semibold">Sem NF-e</span>
                        <?php endif; ?>
                    </td>
                    <td class="p-3">
                        <button type="button" class="text-blue-700 underline mr-3" onclick="showSaleDetails(<?= (int) $sale['id'] ?>)">Detalhes</button>
                        <form method="post" action="actions/fiscal_issue.php" class="inline">
                            <input type="hidden" name="sale_id" value="<?= (int) $sale['id'] ?>">
                            <input type="hidden" name="return_page" value="pdv">
                            <button type="submit" class="text-emerald-700 underline mr-3">Emitir NF-e</button>
                        </form>
                        <?php if (($sale['fiscal_document_type'] ?? 'none') === 'nfe'): ?>
                            <form method="post" action="actions/fiscal_sync.php" class="inline">
                                <input type="hidden" name="sale_id" value="<?= (int) $sale['id'] ?>">
                                <input type="hidden" name="return_page" value="pdv">
                                <button type="submit" class="text-sky-700 underline mr-3">Sincronizar NF-e</button>
                            </form>
                            <a href="actions/fiscal_download_pdf.php?sale_id=<?= (int) $sale['id'] ?>" class="text-slate-700 underline mr-3">PDF NF-e</a>
                            <?php if (($sale['fiscal_status'] ?? '') === 'issued'): ?>
                                <form method="post" action="actions/fiscal_cancel.php" class="inline" onsubmit="return confirm('Confirmar cancelamento da NF-e desta venda? Esta acao sera enviada para a SEFAZ.');">
                                    <input type="hidden" name="sale_id" value="<?= (int) $sale['id'] ?>">
                                    <input type="hidden" name="return_page" value="pdv">
                                    <input type="text" name="justification" minlength="15" maxlength="255" required value="Cancelamento por erro nos valores de quantidade e preco unitario." class="border rounded px-2 py-1 text-xs w-80 mr-2">
                                    <button type="submit" class="text-orange-700 underline mr-3">Cancelar NF-e</button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                        <form method="post" action="actions/sale_delete.php" class="inline" onsubmit="return confirm('Tem certeza que deseja excluir esta venda?');">
                            <input type="hidden" name="sale_id" value="<?= (int) $sale['id'] ?>">
                            <button type="submit" class="text-rose-700 underline">Excluir</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div id="sale-details-panel" class="bg-white rounded-lg shadow overflow-auto mt-6 hidden">
    <h3 id="sale-details-title" class="font-semibold p-4"></h3>
    <div id="sale-details-meta" class="px-4 pb-2 text-sm text-slate-700"></div>
    <table class="w-full text-sm">
        <thead class="bg-slate-200">
            <tr>
                <th class="p-3 text-left">Produto</th>
                <th class="p-3 text-left">Marca</th>
                <th class="p-3 text-left">Modelo</th>
                <th class="p-3 text-left">Quantidade</th>
                <th class="p-3 text-left">Preco unitario</th>
                <th class="p-3 text-left">Total item</th>
            </tr>
        </thead>
        <tbody id="sale-details-items"></tbody>
    </table>
</div>

<script>
const products = <?= json_encode($products, JSON_UNESCAPED_UNICODE) ?>;
const customers = <?= json_encode($customers, JSON_UNESCAPED_UNICODE) ?>;
const salesDetails = <?= json_encode($salesDetails, JSON_UNESCAPED_UNICODE) ?>;
const customerIdInput = document.getElementById('customer_id');
const customerSearchInput = document.getElementById('customer_search');
const customerSuggestions = document.getElementById('customer_suggestions');
const firstNameInput = document.getElementById('customer_first_name');
const lastNameInput = document.getElementById('customer_last_name');
const emailInput = document.getElementById('customer_email');
const phoneInput = document.getElementById('customer_phone');
const taxIdInput = document.getElementById('customer_tax_id');
const carInput = document.getElementById('customer_car');
const notesInput = document.getElementById('customer_notes');
const addressStreetInput = document.getElementById('customer_address_street');
const addressNumberInput = document.getElementById('customer_address_number');
const addressDistrictInput = document.getElementById('customer_address_district');
const addressCityInput = document.getElementById('customer_address_city');
const addressStateInput = document.getElementById('customer_address_state');
const addressZipInput = document.getElementById('customer_address_zip');
const addressCountryInput = document.getElementById('customer_address_country');
const saleDetailsPanel = document.getElementById('sale-details-panel');
const saleDetailsTitle = document.getElementById('sale-details-title');
const saleDetailsMeta = document.getElementById('sale-details-meta');
const saleDetailsItems = document.getElementById('sale-details-items');
const saleForm = document.querySelector('[data-sale-form]');
const saleSubmitButton = document.querySelector('[data-sale-submit]');

saleForm.addEventListener('submit', (event) => {
    if (saleForm.dataset.submitting === '1') {
        event.preventDefault();
        return;
    }

    saleForm.dataset.submitting = '1';
    saleSubmitButton.disabled = true;
    saleSubmitButton.textContent = 'Finalizando venda...';
});

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    }[char]));
}

function addItem() {
    const wrapper = document.createElement('div');
    wrapper.className = 'grid grid-cols-1 md:grid-cols-4 gap-2';

    wrapper.innerHTML = `
        <div class="relative md:col-span-2">
            <input type="hidden" name="items[product_id][]">
            <input type="hidden" name="items[product_name][]">
            <input type="text" data-role="product-search" autocomplete="off" placeholder="Buscar produto ou digitar novo produto" class="w-full border rounded px-3 py-2">
            <div data-role="product-suggestions" class="hidden absolute z-10 mt-1 w-full max-h-48 overflow-auto bg-white border rounded shadow"></div>
        </div>
        <input required min="1" type="number" name="items[quantity][]" placeholder="Qtd" class="border rounded px-3 py-2">
        <input required min="0" step="0.01" type="number" name="items[unit_price][]" placeholder="Preco unitario" class="border rounded px-3 py-2">
        <button type="button" class="bg-rose-500 text-white rounded px-2">Remover</button>
    `;

    const productIdInput = wrapper.querySelector('input[name="items[product_id][]"]');
    const productNameInput = wrapper.querySelector('input[name="items[product_name][]"]');
    const productSearch = wrapper.querySelector('input[data-role="product-search"]');
    const productSuggestions = wrapper.querySelector('div[data-role="product-suggestions"]');
    const unitInput = wrapper.querySelector('input[name="items[unit_price][]"]');
    const removeBtn = wrapper.querySelector('button');

    function renderProductSuggestions(query) {
        const q = query.trim().toLowerCase();
        if (q.length < 1) {
            productSuggestions.classList.add('hidden');
            productSuggestions.innerHTML = '';
            return;
        }

        const filtered = products.filter((p) =>
            (p.name || '').toLowerCase().includes(q)
        ).slice(0, 10);

        const rows = filtered.map((p) => `
            <button type="button" data-id="${p.id}" class="w-full text-left px-3 py-2 hover:bg-slate-100 border-b last:border-b-0">
                <div class="font-medium">${escapeHtml(p.name)}</div>
                <div class="text-xs text-slate-500">Est: ${p.stock_qty} | R$ ${Number(p.price).toFixed(2)}</div>
            </button>
        `);

        if (!filtered.some((p) => (p.name || '').toLowerCase() === q)) {
            rows.push(`
                <button type="button" data-new-product="1" class="w-full text-left px-3 py-2 hover:bg-emerald-50 text-emerald-800">
                    <div class="font-medium">Cadastrar novo: ${escapeHtml(query.trim())}</div>
                    <div class="text-xs">Sera criado automaticamente ao finalizar a venda</div>
                </button>
            `);
        }

        productSuggestions.innerHTML = rows.join('');

        productSuggestions.classList.remove('hidden');

        productSuggestions.querySelectorAll('button[data-id]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const id = Number(btn.getAttribute('data-id'));
                const product = products.find((p) => Number(p.id) === id);
                if (!product) return;
                productIdInput.value = String(product.id);
                productNameInput.value = product.name;
                productSearch.value = product.name;
                if (!unitInput.value || Number(unitInput.value) === 0) {
                    unitInput.value = Number(product.price).toFixed(2);
                }
                productSuggestions.classList.add('hidden');
            });
        });

        const newProductButton = productSuggestions.querySelector('button[data-new-product]');
        if (newProductButton) {
            newProductButton.addEventListener('click', () => {
                productIdInput.value = '';
                productNameInput.value = query.trim();
                productSearch.value = query.trim();
                productSuggestions.classList.add('hidden');
            });
        }
    }

    productSearch.addEventListener('input', () => {
        productIdInput.value = '';
        productNameInput.value = productSearch.value.trim();
        renderProductSuggestions(productSearch.value);
    });
    productSearch.addEventListener('focus', () => renderProductSuggestions(productSearch.value));
    productSearch.addEventListener('blur', () => setTimeout(() => productSuggestions.classList.add('hidden'), 150));
    removeBtn.addEventListener('click', () => wrapper.remove());

    document.getElementById('items').appendChild(wrapper);
}

addItem();

function clearCustomerSelection() {
    customerIdInput.value = '';
}

function selectCustomer(customer) {
    customerIdInput.value = String(customer.id);
    customerSearchInput.value = customer.full_name;
    firstNameInput.value = customer.first_name || '';
    lastNameInput.value = customer.last_name || '';
    emailInput.value = customer.email || '';
    phoneInput.value = customer.phone || '';
    taxIdInput.value = customer.tax_id || '';
    carInput.value = customer.car || '';
    notesInput.value = customer.notes || '';
    addressStreetInput.value = customer.address_street || '';
    addressNumberInput.value = customer.address_number || '';
    addressDistrictInput.value = customer.address_district || '';
    addressCityInput.value = customer.address_city || '';
    addressStateInput.value = customer.address_state || '';
    addressZipInput.value = customer.address_zip || '';
    addressCountryInput.value = customer.address_country || 'Brasil';
    customerSuggestions.classList.add('hidden');
}

function renderCustomerSuggestions(query) {
    const q = query.trim().toLowerCase();
    if (q.length < 1) {
        customerSuggestions.classList.add('hidden');
        customerSuggestions.innerHTML = '';
        return;
    }

    const filtered = customers.filter(c =>
        c.full_name.toLowerCase().includes(q) ||
        (c.email || '').toLowerCase().includes(q) ||
        (c.phone || '').toLowerCase().includes(q) ||
        (c.tax_id || '').toLowerCase().includes(q)
    ).slice(0, 8);

    if (filtered.length === 0) {
        customerSuggestions.classList.add('hidden');
        customerSuggestions.innerHTML = '';
        return;
    }

    customerSuggestions.innerHTML = filtered.map(c => `
        <button type="button" data-id="${c.id}" class="w-full text-left px-3 py-2 hover:bg-slate-100 border-b last:border-b-0">
            <div class="font-medium">${c.full_name}</div>
            <div class="text-xs text-slate-500">${c.email || '-'} | ${c.phone || '-'} | ${c.tax_id || '-'}</div>
        </button>
    `).join('');

    customerSuggestions.classList.remove('hidden');

    customerSuggestions.querySelectorAll('button[data-id]').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = Number(btn.getAttribute('data-id'));
            const customer = customers.find(c => Number(c.id) === id);
            if (customer) {
                selectCustomer(customer);
            }
        });
    });
}

customerSearchInput.addEventListener('input', () => {
    clearCustomerSelection();
    renderCustomerSuggestions(customerSearchInput.value);
});

customerSearchInput.addEventListener('blur', () => {
    setTimeout(() => customerSuggestions.classList.add('hidden'), 150);
});

customerSearchInput.addEventListener('focus', () => {
    renderCustomerSuggestions(customerSearchInput.value);
});

function brMoney(value) {
    return `R$ ${Number(value).toFixed(2).replace('.', ',')}`;
}

function showSaleDetails(saleId) {
    const data = salesDetails[String(saleId)] || salesDetails[saleId];
    if (!data || !data.sale) {
        return;
    }

    const sale = data.sale;
    const customer = sale.customer_name || 'Consumidor final';
    const status = sale.payment_status === 'paid' ? 'Pago' : 'Pendente';
    saleDetailsTitle.textContent = `Detalhes da venda #${sale.id} - ${customer}`;
    saleDetailsMeta.innerHTML = `<p>Data: ${sale.created_at}</p><p>Vendedor: ${sale.seller_name || '-'}</p><p>Pagamento: ${sale.payment_method} (${status})</p>`;

    const items = data.items || [];
    let rows = '';
    items.forEach(item => {
        rows += `
            <tr class="border-t">
                <td class="p-3">${item.product_name ?? ''}</td>
                <td class="p-3">${item.brand ?? ''}</td>
                <td class="p-3">${item.model ?? ''}</td>
                <td class="p-3">${Number(item.quantity)}</td>
                <td class="p-3">${brMoney(item.unit_price)}</td>
                <td class="p-3">${brMoney(item.line_total)}</td>
            </tr>
        `;
    });

    rows += `
        <tr class="border-t font-semibold">
            <td class="p-3" colspan="5">Total da venda</td>
            <td class="p-3">${brMoney(sale.total_amount)}</td>
        </tr>
    `;

    saleDetailsItems.innerHTML = rows;
    saleDetailsPanel.classList.remove('hidden');
}

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

async function fillAddressByCep() {
    const cep = addressZipInput.value.replace(/\D/g, '').slice(0, 8);
    if (cep.length !== 8) {
        return;
    }

    try {
        const response = await fetch(`https://viacep.com.br/ws/${cep}/json/`);
        if (!response.ok) {
            return;
        }

        const data = await response.json();
        if (data.erro) {
            return;
        }

        addressStreetInput.value = data.logradouro || addressStreetInput.value;
        addressDistrictInput.value = data.bairro || addressDistrictInput.value;
        addressCityInput.value = data.localidade || addressCityInput.value;
        addressStateInput.value = data.uf || addressStateInput.value;
        addressCountryInput.value = 'Brasil';
    } catch (error) {
        console.warn('Nao foi possivel consultar o CEP no ViaCEP.', error);
    }
}

addressZipInput.addEventListener('input', () => {
    const digits = addressZipInput.value.replace(/\D/g, '').slice(0, 8);
    if (digits.length <= 5) {
        addressZipInput.value = digits;
    } else {
        addressZipInput.value = `${digits.slice(0, 5)}-${digits.slice(5)}`;
    }

    if (digits.length === 8) {
        fillAddressByCep();
    }
});

addressZipInput.addEventListener('blur', fillAddressByCep);

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

