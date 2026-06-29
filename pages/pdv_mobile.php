<?php
if (!isset($pdo) || !$pdo instanceof PDO) {
    require_once __DIR__ . '/../config/db.php';
}

function pdvMobileEnsureCustomerSchema(PDO $pdo): void
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

pdvMobileEnsureCustomerSchema($pdo);

$products = $pdo->query('SELECT id, name, sale_price AS price, stock_qty FROM products ORDER BY name')->fetchAll();
$customers = $pdo->query("SELECT id, first_name, last_name, CONCAT(first_name, ' ', last_name) AS full_name, email, phone, tax_id, car, notes, address_street, address_number, address_district, address_city, address_state, address_zip, address_country FROM customers ORDER BY first_name, last_name")->fetchAll();
$pdvFormAction = isset($pdvFormAction) ? (string) $pdvFormAction : 'actions/sale_finalize.php';
$pdvReturnPage = isset($pdvReturnPage) ? (string) $pdvReturnPage : 'pdv_mobile';
$sellers = ['Elias', 'Daniel', 'Felipe', 'Eriko'];
$saleRequestToken = bin2hex(random_bytes(32));
?>

<div class="mx-auto max-w-4xl">
    <div class="mb-5 overflow-hidden rounded-3xl bg-slate-950 p-5 text-white shadow-xl shadow-slate-950/10">
        <p class="text-xs font-black uppercase tracking-[0.24em] text-emerald-300">PDV Mobile</p>
        <h2 class="mt-2 text-3xl font-black tracking-tight">Venda em campo</h2>
        <p class="mt-2 text-sm leading-6 text-slate-300">Tela publica e responsiva para vendedores registrarem vendas rapidamente pelo celular.</p>
    </div>

    <form method="post" action="<?= htmlspecialchars($pdvFormAction) ?>" data-sale-form class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-lg shadow-slate-200/70">
        <input type="hidden" name="return_page" value="<?= htmlspecialchars($pdvReturnPage) ?>">
        <input type="hidden" name="request_token" value="<?= htmlspecialchars($saleRequestToken) ?>">

        <div class="border-b border-slate-100 bg-gradient-to-r from-white to-slate-50 p-4">
            <p class="text-xs font-bold uppercase tracking-[0.2em] text-emerald-700">Nova venda</p>
            <h3 class="text-xl font-black text-slate-950">Cliente, itens e pagamento</h3>
        </div>

        <div class="grid grid-cols-1 gap-3 p-4 md:grid-cols-2">
            <div class="md:col-span-2 relative">
                <input type="hidden" name="customer_id" id="customer_id">
                <input id="customer_search" autocomplete="off" placeholder="Buscar cliente por nome, telefone ou CPF/CNPJ" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100">
                <div id="customer_suggestions" class="hidden absolute z-20 mt-2 w-full max-h-48 overflow-auto rounded-2xl border border-slate-200 bg-white shadow-xl"></div>
            </div>
            <input name="customer_first_name" id="customer_first_name" placeholder="Nome" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
            <input name="customer_last_name" id="customer_last_name" placeholder="Sobrenome" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
            <input type="text" inputmode="email" name="customer_email" id="customer_email" placeholder="E-mail (opcional)" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100 md:col-span-2">
            <input name="customer_phone" id="customer_phone" placeholder="Telefone (xx xxxxx-xxxx)" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
            <input name="customer_tax_id" id="customer_tax_id" placeholder="CPF/CNPJ" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
            <input name="customer_car" id="customer_car" placeholder="Carro" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
            <input name="customer_notes" id="customer_notes" placeholder="Observacoes" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
            <label class="md:col-span-2 flex items-center gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-900">
                <input type="checkbox" name="issue_nfe" id="issue_nfe" value="1" class="h-4 w-4">
                <span>NF-e</span>
            </label>
            <div id="nfe_address_fields" class="hidden md:col-span-2 grid grid-cols-1 gap-3 rounded-3xl border border-slate-200 bg-slate-50 p-3 md:grid-cols-2">
                <input name="customer_address_street" id="customer_address_street" placeholder="Logradouro" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
                <input name="customer_address_number" id="customer_address_number" placeholder="Numero" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
                <input name="customer_address_district" id="customer_address_district" placeholder="Bairro" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
                <input name="customer_address_city" id="customer_address_city" placeholder="Cidade" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
                <input name="customer_address_state" id="customer_address_state" maxlength="2" placeholder="UF" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm uppercase outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
                <input name="customer_address_zip" id="customer_address_zip" placeholder="CEP" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
                <input name="customer_address_country" id="customer_address_country" placeholder="Pais" value="Brasil" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100 md:col-span-2">
            </div>
            <select required name="seller_name" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm font-semibold outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100 md:col-span-2">
                <option value="">Selecione o vendedor</option>
                <?php foreach ($sellers as $seller): ?>
                    <option value="<?= htmlspecialchars($seller) ?>"><?= htmlspecialchars($seller) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="payment_method" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
                <option value="dinheiro">Dinheiro</option>
                <option value="pix">PIX</option>
                <option value="cartao">Cartao</option>
                <option value="prazo">A prazo</option>
            </select>
            <select name="payment_status" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
                <option value="paid">Pago</option>
                <option value="pending">Pendente</option>
            </select>
            <input type="date" name="due_date" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100 md:col-span-2">
        </div>

        <div class="mx-4 mb-2 flex items-center justify-between rounded-2xl bg-slate-950 p-3 text-white">
            <div>
                <p class="text-xs font-bold uppercase tracking-wide text-emerald-300">Itens</p>
                <h3 class="font-black">Produtos da venda</h3>
            </div>
            <button type="button" onclick="addItem()" class="rounded-full bg-white px-3 py-2 text-sm font-bold text-slate-950">+ Item</button>
        </div>

        <div id="items" class="space-y-3 px-4"></div>

        <div class="mx-4 mt-4 flex items-center justify-between rounded-2xl bg-slate-100 p-4">
            <span class="text-sm text-slate-600">Total da venda</span>
            <span id="sale_total" class="text-xl font-bold">R$ 0,00</span>
        </div>

        <div class="p-4">
            <button data-sale-submit class="w-full rounded-2xl bg-emerald-500 px-4 py-4 font-black text-slate-950 shadow-lg shadow-emerald-900/20 transition hover:bg-emerald-400 disabled:cursor-not-allowed disabled:bg-emerald-300">Finalizar venda</button>
        </div>
    </form>
</div>

<script>
const products = <?= json_encode($products, JSON_UNESCAPED_UNICODE) ?>;
const customers = <?= json_encode($customers, JSON_UNESCAPED_UNICODE) ?>;
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
const issueNfeInput = document.getElementById('issue_nfe');
const nfeAddressFields = document.getElementById('nfe_address_fields');
const addressStreetInput = document.getElementById('customer_address_street');
const addressNumberInput = document.getElementById('customer_address_number');
const addressDistrictInput = document.getElementById('customer_address_district');
const addressCityInput = document.getElementById('customer_address_city');
const addressStateInput = document.getElementById('customer_address_state');
const addressZipInput = document.getElementById('customer_address_zip');
const addressCountryInput = document.getElementById('customer_address_country');
const itemsWrapper = document.getElementById('items');
const saleTotalEl = document.getElementById('sale_total');
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

function brMoney(value) {
    return `R$ ${Number(value).toFixed(2).replace('.', ',')}`;
}

function recalcTotal() {
    const rows = itemsWrapper.querySelectorAll('[data-item-row]');
    let total = 0;
    rows.forEach((row) => {
        const qty = Number(row.querySelector('input[name="items[quantity][]"]').value || 0);
        const unit = Number(row.querySelector('input[name="items[unit_price][]"]').value || 0);
        if (qty > 0 && unit >= 0) {
            total += qty * unit;
        }
    });
    saleTotalEl.textContent = brMoney(total);
}

function addItem() {
    const wrapper = document.createElement('div');
    wrapper.setAttribute('data-item-row', '1');
    wrapper.className = 'border rounded-lg p-3';

    wrapper.innerHTML = `
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
            <div class="relative sm:col-span-2">
                <input type="hidden" name="items[product_id][]">
                <input type="hidden" name="items[product_name][]">
                <input type="text" data-role="product-search" autocomplete="off" placeholder="Buscar produto ou digitar novo produto" class="w-full border rounded px-3 py-3">
                <div data-role="product-suggestions" class="hidden absolute z-10 mt-1 w-full max-h-48 overflow-auto bg-white border rounded shadow"></div>
            </div>
            <input required min="1" type="number" name="items[quantity][]" placeholder="Quantidade" class="border rounded px-3 py-3">
            <input required min="0" step="0.01" type="number" name="items[unit_price][]" placeholder="Preco unitario" class="border rounded px-3 py-3">
        </div>
        <div class="mt-2 text-right">
            <button type="button" class="text-rose-700 text-sm underline">Remover item</button>
        </div>
    `;

    const productIdInput = wrapper.querySelector('input[name="items[product_id][]"]');
    const productNameInput = wrapper.querySelector('input[name="items[product_name][]"]');
    const productSearch = wrapper.querySelector('input[data-role="product-search"]');
    const productSuggestions = wrapper.querySelector('div[data-role="product-suggestions"]');
    const qty = wrapper.querySelector('input[name="items[quantity][]"]');
    const unit = wrapper.querySelector('input[name="items[unit_price][]"]');
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
                if (!unit.value || Number(unit.value) === 0) {
                    unit.value = Number(product.price).toFixed(2);
                }
                productSuggestions.classList.add('hidden');
                recalcTotal();
            });
        });

        const newProductButton = productSuggestions.querySelector('button[data-new-product]');
        if (newProductButton) {
            newProductButton.addEventListener('click', () => {
                productIdInput.value = '';
                productNameInput.value = query.trim();
                productSearch.value = query.trim();
                productSuggestions.classList.add('hidden');
                recalcTotal();
            });
        }
    }

    productSearch.addEventListener('input', () => {
        productIdInput.value = '';
        productNameInput.value = productSearch.value.trim();
        renderProductSuggestions(productSearch.value);
    });

    productSearch.addEventListener('focus', () => {
        renderProductSuggestions(productSearch.value);
    });

    productSearch.addEventListener('blur', () => {
        setTimeout(() => productSuggestions.classList.add('hidden'), 150);
    });

    qty.addEventListener('input', recalcTotal);
    unit.addEventListener('input', recalcTotal);
    removeBtn.addEventListener('click', () => {
        wrapper.remove();
        recalcTotal();
    });

    itemsWrapper.appendChild(wrapper);
}

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

function toggleNfeAddressFields() {
    nfeAddressFields.classList.toggle('hidden', !issueNfeInput.checked);
}

issueNfeInput.addEventListener('change', toggleNfeAddressFields);
toggleNfeAddressFields();

function renderCustomerSuggestions(query) {
    const q = query.trim().toLowerCase();
    if (q.length < 1) {
        customerSuggestions.classList.add('hidden');
        customerSuggestions.innerHTML = '';
        return;
    }

    const filtered = customers.filter((c) =>
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

    customerSuggestions.innerHTML = filtered.map((c) => `
        <button type="button" data-id="${c.id}" class="w-full text-left px-3 py-2 hover:bg-slate-100 border-b last:border-b-0">
            <div class="font-medium">${c.full_name}</div>
            <div class="text-xs text-slate-500">${c.email || '-'} | ${c.phone || '-'} | ${c.tax_id || '-'}</div>
        </button>
    `).join('');

    customerSuggestions.classList.remove('hidden');

    customerSuggestions.querySelectorAll('button[data-id]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const id = Number(btn.getAttribute('data-id'));
            const customer = customers.find((c) => Number(c.id) === id);
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

addItem();
recalcTotal();
</script>
