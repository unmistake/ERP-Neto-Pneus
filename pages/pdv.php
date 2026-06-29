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
$todaySales = [];
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

$todaySales = $pdo->query(
    "SELECT
        s.id,
        s.customer_name,
        s.seller_name,
        s.total_amount,
        s.payment_status,
        s.fiscal_document_type,
        s.fiscal_status,
        s.created_at,
        EXISTS (
            SELECT 1
            FROM fiscal_documents fd
            WHERE fd.sale_id = s.id AND fd.document_type = 'nfe'
        ) AS has_nfe_document,
        EXISTS (
            SELECT 1
            FROM fiscal_documents fd
            WHERE fd.sale_id = s.id
              AND fd.document_type = 'nfe'
              AND fd.status IN ('autorizado', 'processando', 'pendente')
        ) AS has_active_nfe_document
     FROM sales s
     WHERE s.created_at >= DATE_SUB(NOW(), INTERVAL 72 HOUR)
     ORDER BY s.id DESC"
)->fetchAll();

$recentSalesCount = count($todaySales);
$recentSalesTotal = array_reduce(
    $todaySales,
    static fn (float $carry, array $sale): float => $carry + (float) $sale['total_amount'],
    0.0
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

<section class="mb-6 overflow-hidden rounded-3xl bg-slate-950 text-white shadow-xl shadow-slate-950/10">
    <div class="grid gap-5 p-5 md:grid-cols-[1.5fr_1fr] md:p-7">
        <div>
            <p class="text-xs font-black uppercase tracking-[0.28em] text-emerald-300">PDV operacional</p>
            <h2 class="mt-2 text-3xl font-black tracking-tight md:text-4xl">Venda rapida, sem atrito</h2>
            <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-300">Registre vendas, clientes, itens e NF-e em uma tela unica. A lista abaixo agora mostra tudo que foi vendido nas ultimas 72 horas.</p>
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div class="rounded-2xl bg-white/10 p-4 ring-1 ring-white/10">
                <p class="text-xs uppercase tracking-wide text-slate-400">Vendas 72h</p>
                <p class="mt-1 text-3xl font-black"><?= $recentSalesCount ?></p>
            </div>
            <div class="rounded-2xl bg-emerald-400 p-4 text-slate-950">
                <p class="text-xs font-bold uppercase tracking-wide">Volume 72h</p>
                <p class="mt-1 text-2xl font-black"><?= money($recentSalesTotal) ?></p>
            </div>
        </div>
    </div>
</section>

<form method="post" action="actions/sale_finalize.php" data-sale-form class="mb-6 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-lg shadow-slate-200/70">
    <input type="hidden" name="request_token" value="<?= htmlspecialchars($saleRequestToken) ?>">

    <div class="border-b border-slate-100 bg-gradient-to-r from-white to-slate-50 p-5">
        <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.2em] text-emerald-700">Nova venda</p>
                <h3 class="text-xl font-black text-slate-950">Dados do cliente e pagamento</h3>
            </div>
            <span class="rounded-full bg-slate-950 px-3 py-1 text-xs font-bold text-white">Vendedor obrigatorio</span>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-5 p-5 xl:grid-cols-[1.15fr_0.85fr]">
        <div class="space-y-4">
            <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                <div class="relative md:col-span-3">
                    <input type="hidden" name="customer_id" id="customer_id">
                    <input id="customer_search" autocomplete="off" placeholder="Buscar cliente por nome, telefone ou CPF/CNPJ" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-emerald-500 focus:bg-white focus:ring-4 focus:ring-emerald-100">
                    <div id="customer_suggestions" class="hidden absolute z-20 mt-2 w-full max-h-56 overflow-auto rounded-2xl border border-slate-200 bg-white shadow-xl"></div>
                </div>
                <input name="customer_first_name" id="customer_first_name" placeholder="Nome" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
                <input name="customer_last_name" id="customer_last_name" placeholder="Sobrenome" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
                <input type="text" inputmode="email" name="customer_email" id="customer_email" placeholder="E-mail (opcional)" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
                <input name="customer_phone" id="customer_phone" placeholder="Telefone (xx xxxxx-xxxx)" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
                <input name="customer_tax_id" id="customer_tax_id" placeholder="CPF/CNPJ" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
                <input name="customer_car" id="customer_car" placeholder="Carro" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
                <input name="customer_notes" id="customer_notes" placeholder="Observacoes" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100 md:col-span-2">
                <label class="flex items-center gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-900">
                    <input type="checkbox" name="issue_nfe" id="issue_nfe" value="1" class="h-4 w-4 rounded border-emerald-400 text-emerald-600">
                    NF-e
                </label>
            </div>

            <div class="rounded-3xl border border-slate-200 bg-slate-50 p-4">
                <div class="mb-3 flex items-center justify-between">
                    <div>
                        <h4 class="font-bold text-slate-950">Endereco do cliente</h4>
                        <p class="text-xs text-slate-500">Opcional em venda comum. Obrigatorio quando NF-e estiver marcada.</p>
                    </div>
                    <span class="text-xs font-semibold text-slate-500">ViaCEP ativo</span>
                </div>
                <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                    <input name="customer_address_zip" id="customer_address_zip" placeholder="CEP" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
                    <input name="customer_address_street" id="customer_address_street" placeholder="Logradouro" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100 md:col-span-2">
                    <input name="customer_address_number" id="customer_address_number" placeholder="Numero" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
                    <input name="customer_address_district" id="customer_address_district" placeholder="Bairro" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
                    <input name="customer_address_city" id="customer_address_city" placeholder="Cidade" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
                    <input name="customer_address_state" id="customer_address_state" maxlength="2" placeholder="UF" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm uppercase outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
                    <input name="customer_address_country" id="customer_address_country" placeholder="Pais" value="Brasil" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100 md:col-span-2">
                </div>
            </div>
        </div>

        <div class="space-y-4">
            <div class="grid grid-cols-1 gap-3">
                <select required name="seller_name" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm font-semibold outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
                    <option value="">Selecione o vendedor</option>
                    <?php foreach ($sellers as $seller): ?>
                        <option value="<?= htmlspecialchars($seller) ?>"><?= htmlspecialchars($seller) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="rounded-3xl border border-slate-200 bg-white p-4">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-emerald-600">Pagamento</p>
                        <h4 class="text-lg font-black text-slate-900">Como o cliente pagou</h4>
                    </div>
                    <button type="button" onclick="addPayment()" class="rounded-full bg-slate-950 px-3 py-2 text-sm font-bold text-white transition hover:bg-slate-800">+ Pagamento</button>
                </div>
                <p class="mb-3 text-xs text-slate-500">Pode dividir em varios meios (ex.: pix + dinheiro). Para troca, escolha <strong>Troca</strong> e descreva o item recebido: ele entra no estoque como usado.</p>
                <div id="payments" class="space-y-3"></div>
                <div class="mt-4 grid grid-cols-3 gap-2 text-center">
                    <div class="rounded-2xl bg-slate-100 p-2">
                        <p class="text-[11px] font-semibold uppercase text-slate-500">Total</p>
                        <p id="pay_total" class="text-sm font-black text-slate-900">R$ 0,00</p>
                    </div>
                    <div class="rounded-2xl bg-slate-100 p-2">
                        <p class="text-[11px] font-semibold uppercase text-slate-500">Pago</p>
                        <p id="pay_paid" class="text-sm font-black text-slate-900">R$ 0,00</p>
                    </div>
                    <div id="pay_diff_box" class="rounded-2xl bg-amber-100 p-2">
                        <p id="pay_diff_label" class="text-[11px] font-semibold uppercase text-amber-700">Falta</p>
                        <p id="pay_diff" class="text-sm font-black text-amber-800">R$ 0,00</p>
                    </div>
                </div>
                <label class="mt-3 block">
                    <span class="text-xs font-semibold text-slate-500">Vencimento (parte a prazo)</span>
                    <input type="date" name="due_date" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
                </label>
            </div>

            <div class="rounded-3xl border border-slate-200 bg-slate-950 p-4 text-white">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-emerald-300">Itens</p>
                        <h4 class="text-lg font-black">Produtos da venda</h4>
                    </div>
                    <button type="button" onclick="addItem()" class="rounded-full bg-white px-3 py-2 text-sm font-bold text-slate-950 transition hover:bg-emerald-200">+ Item</button>
                </div>
                <div id="items" class="space-y-3"></div>
                <div class="mt-4 flex items-center justify-between rounded-2xl bg-white/10 p-4 ring-1 ring-white/10">
                    <span class="text-sm text-slate-300">Total da venda</span>
                    <span id="sale_total" class="text-2xl font-black">R$ 0,00</span>
                </div>
            </div>

            <button data-sale-submit class="w-full rounded-2xl bg-emerald-500 px-5 py-4 text-base font-black text-slate-950 shadow-lg shadow-emerald-900/20 transition hover:bg-emerald-400 disabled:cursor-not-allowed disabled:bg-emerald-300">Finalizar venda</button>
        </div>
    </div>
</form>

<div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-lg shadow-slate-200/70">
    <div class="flex flex-col gap-2 border-b border-slate-100 p-5 md:flex-row md:items-center md:justify-between">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Historico recente</p>
            <h3 class="text-xl font-black text-slate-950">Vendas das ultimas 72 horas</h3>
        </div>
        <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600"><?= $recentSalesCount ?> registro(s)</span>
    </div>
    <div class="overflow-auto">
        <table class="w-full min-w-[980px] text-sm">
            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="p-4 text-left">Data</th>
                    <th class="p-4 text-left">Cliente</th>
                    <th class="p-4 text-left">Vendedor</th>
                    <th class="p-4 text-right">Total</th>
                    <th class="p-4 text-left">Status</th>
                    <th class="p-4 text-left">Fiscal</th>
                    <th class="p-4 text-left">Acoes</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (count($todaySales) === 0): ?>
                    <tr>
                        <td colspan="7" class="p-8 text-center text-slate-500">Nenhuma venda registrada nas ultimas 72 horas.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($todaySales as $sale): ?>
                    <?php
                        $hasNfeDocument = (bool) ($sale['has_nfe_document'] ?? false);
                        $hasActiveNfeDocument = (bool) ($sale['has_active_nfe_document'] ?? false);
                        $fiscalStatus = (string) ($sale['fiscal_status'] ?? 'not_requested');
                        $isNfeSale = ($sale['fiscal_document_type'] ?? 'none') === 'nfe' || $hasNfeDocument;
                        $canIssueNfe = !$hasActiveNfeDocument && $fiscalStatus !== 'issued';
                    ?>
                    <tr class="align-top transition hover:bg-slate-50">
                        <td class="p-4 whitespace-nowrap text-slate-600"><?= htmlspecialchars(date('d/m H:i', strtotime((string) $sale['created_at']))) ?></td>
                        <td class="p-4 font-semibold text-slate-950"><?= htmlspecialchars($sale['customer_name'] ?: 'Consumidor final') ?></td>
                        <td class="p-4"><span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-700"><?= htmlspecialchars((string) ($sale['seller_name'] ?? '-')) ?></span></td>
                        <td class="p-4 text-right font-black text-slate-950"><?= money((float) $sale['total_amount']) ?></td>
                        <td class="p-4">
                            <?php if ($sale['payment_status'] === 'paid'): ?>
                                <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-800">Pago</span>
                            <?php else: ?>
                                <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-800">Pendente</span>
                            <?php endif; ?>
                        </td>
                        <td class="p-4">
                            <?php if ($isNfeSale): ?>
                                <span class="inline-flex rounded-full bg-blue-100 px-3 py-1 text-xs font-bold text-blue-800">NF-e</span>
                                <div class="mt-1 text-xs text-slate-500"><?= htmlspecialchars($fiscalStatus) ?></div>
                            <?php else: ?>
                                <span class="inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">Sem NF-e</span>
                            <?php endif; ?>
                        </td>
                        <td class="p-4">
                            <div class="flex flex-wrap gap-2">
                                <button type="button" class="rounded-full bg-blue-50 px-3 py-1.5 text-xs font-bold text-blue-700 transition hover:bg-blue-100" onclick="showSaleDetails(<?= (int) $sale['id'] ?>)">Detalhes</button>
                                <?php if ($canIssueNfe): ?>
                                    <form method="post" action="actions/fiscal_issue.php" class="inline" data-fiscal-issue-form>
                                        <input type="hidden" name="sale_id" value="<?= (int) $sale['id'] ?>">
                                        <input type="hidden" name="return_page" value="pdv">
                                        <button type="submit" class="rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-bold text-emerald-700 transition hover:bg-emerald-100 disabled:cursor-not-allowed disabled:text-slate-400">Emitir NF-e</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($hasNfeDocument): ?>
                                    <form method="post" action="actions/fiscal_sync.php" class="inline">
                                        <input type="hidden" name="sale_id" value="<?= (int) $sale['id'] ?>">
                                        <input type="hidden" name="return_page" value="pdv">
                                        <button type="submit" class="rounded-full bg-sky-50 px-3 py-1.5 text-xs font-bold text-sky-700 transition hover:bg-sky-100">Sincronizar</button>
                                    </form>
                                    <a href="actions/fiscal_download_pdf.php?sale_id=<?= (int) $sale['id'] ?>" class="rounded-full bg-slate-100 px-3 py-1.5 text-xs font-bold text-slate-700 transition hover:bg-slate-200">PDF</a>
                                    <?php if ($fiscalStatus === 'issued'): ?>
                                        <form method="post" action="actions/fiscal_cancel.php" class="flex flex-wrap gap-2" onsubmit="return confirm('Confirmar cancelamento da NF-e desta venda? Esta acao sera enviada para a SEFAZ.');">
                                            <input type="hidden" name="sale_id" value="<?= (int) $sale['id'] ?>">
                                            <input type="hidden" name="return_page" value="pdv">
                                            <input type="text" name="justification" minlength="15" maxlength="255" required value="Cancelamento por erro nos valores de quantidade e preco unitario." class="w-72 rounded-full border border-slate-200 px-3 py-1.5 text-xs">
                                            <button type="submit" class="rounded-full bg-orange-50 px-3 py-1.5 text-xs font-bold text-orange-700 transition hover:bg-orange-100">Cancelar</button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <form method="post" action="actions/sale_delete.php" class="inline" onsubmit="return confirm('Tem certeza que deseja excluir esta venda?');">
                                    <input type="hidden" name="sale_id" value="<?= (int) $sale['id'] ?>">
                                    <button type="submit" class="rounded-full bg-rose-50 px-3 py-1.5 text-xs font-bold text-rose-700 transition hover:bg-rose-100">Excluir</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="sale-details-panel" class="mt-6 hidden overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-lg shadow-slate-200/70">
    <div class="border-b border-slate-100 p-5">
        <p class="text-xs font-bold uppercase tracking-[0.2em] text-emerald-700">Conferencia</p>
        <h3 id="sale-details-title" class="text-xl font-black text-slate-950"></h3>
        <div id="sale-details-meta" class="mt-2 flex flex-wrap gap-2 text-sm text-slate-600"></div>
    </div>
    <div class="overflow-auto">
        <table class="w-full min-w-[760px] text-sm">
            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="p-4 text-left">Produto</th>
                    <th class="p-4 text-left">Marca</th>
                    <th class="p-4 text-left">Modelo</th>
                    <th class="p-4 text-right">Quantidade</th>
                    <th class="p-4 text-right">Preco unitario</th>
                    <th class="p-4 text-right">Total item</th>
                </tr>
            </thead>
            <tbody id="sale-details-items" class="divide-y divide-slate-100"></tbody>
        </table>
    </div>
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
const issueNfeInput = document.getElementById('issue_nfe');
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
const itemsWrapper = document.getElementById('items');
const saleTotalEl = document.getElementById('sale_total');
const nfeRequiredAddressInputs = [
    addressZipInput,
    addressStreetInput,
    addressNumberInput,
    addressDistrictInput,
    addressCityInput,
    addressStateInput,
    addressCountryInput,
];

document.querySelectorAll('[data-fiscal-issue-form]').forEach((form) => {
    form.addEventListener('submit', () => {
        const button = form.querySelector('button[type="submit"]');
        if (button) {
            button.disabled = true;
            button.textContent = 'Enviando...';
        }
    });
});

saleForm.addEventListener('submit', (event) => {
    if (saleForm.dataset.submitting === '1') {
        event.preventDefault();
        return;
    }

    const paidRows = paymentsWrapper.querySelectorAll('[data-payment-row]');
    if (paidRows.length === 0) {
        event.preventDefault();
        alert('Adicione ao menos uma forma de pagamento.');
        return;
    }

    const paid = paymentsPaidSum();
    if (Math.abs(paid - currentSaleTotal) > 0.01) {
        event.preventDefault();
        alert('Os pagamentos (' + brMoney(paid) + ') nao fecham com o total da venda (' + brMoney(currentSaleTotal) + '). Ajuste os valores.');
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

let currentSaleTotal = 0;
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
    currentSaleTotal = total;
    saleTotalEl.textContent = brMoney(total);
    recalcPayments();
}

function addItem() {
    const wrapper = document.createElement('div');
    wrapper.setAttribute('data-item-row', '1');
    wrapper.className = 'rounded-2xl bg-white p-3 text-slate-900';

    wrapper.innerHTML = `
        <div class="grid grid-cols-1 gap-2 md:grid-cols-4">
            <div class="relative md:col-span-2">
                <input type="hidden" name="items[product_id][]">
                <input type="hidden" name="items[product_name][]">
                <input type="text" data-role="product-search" autocomplete="off" placeholder="Buscar produto ou digitar novo produto" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
                <div data-role="product-suggestions" class="hidden absolute z-20 mt-2 w-full max-h-48 overflow-auto rounded-2xl border border-slate-200 bg-white shadow-xl"></div>
            </div>
            <input required min="1" type="number" name="items[quantity][]" placeholder="Qtd" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
            <input required min="0" step="0.01" type="number" name="items[unit_price][]" placeholder="Preco unitario" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100">
        </div>
        <div class="mt-2 text-right">
            <button type="button" class="text-xs font-bold text-rose-700 underline">Remover item</button>
        </div>
    `;

    const productIdInput = wrapper.querySelector('input[name="items[product_id][]"]');
    const productNameInput = wrapper.querySelector('input[name="items[product_name][]"]');
    const productSearch = wrapper.querySelector('input[data-role="product-search"]');
    const productSuggestions = wrapper.querySelector('div[data-role="product-suggestions"]');
    const qtyInput = wrapper.querySelector('input[name="items[quantity][]"]');
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
            <button type="button" data-id="${p.id}" class="w-full border-b px-3 py-2 text-left hover:bg-slate-100 last:border-b-0">
                <div class="font-medium">${escapeHtml(p.name)}</div>
                <div class="text-xs text-slate-500">Est: ${p.stock_qty} | R$ ${Number(p.price).toFixed(2)}</div>
            </button>
        `);

        if (!filtered.some((p) => (p.name || '').toLowerCase() === q)) {
            rows.push(`
                <button type="button" data-new-product="1" class="w-full px-3 py-2 text-left text-emerald-800 hover:bg-emerald-50">
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
    productSearch.addEventListener('focus', () => renderProductSuggestions(productSearch.value));
    productSearch.addEventListener('blur', () => setTimeout(() => productSuggestions.classList.add('hidden'), 150));
    qtyInput.addEventListener('input', recalcTotal);
    unitInput.addEventListener('input', recalcTotal);
    removeBtn.addEventListener('click', () => {
        wrapper.remove();
        recalcTotal();
    });

    itemsWrapper.appendChild(wrapper);
}

const paymentsWrapper = document.getElementById('payments');
const payTotalEl = document.getElementById('pay_total');
const payPaidEl = document.getElementById('pay_paid');
const payDiffEl = document.getElementById('pay_diff');
const payDiffLabel = document.getElementById('pay_diff_label');
const payDiffBox = document.getElementById('pay_diff_box');
const paymentMethodOptions = [
    ['dinheiro', 'Dinheiro'],
    ['pix', 'PIX'],
    ['cartao', 'Cartao'],
    ['prazo', 'A prazo'],
    ['troca', 'Troca (mercadoria)'],
];

function paymentsPaidSum() {
    let sum = 0;
    paymentsWrapper.querySelectorAll('input[name="payments[amount][]"]').forEach((input) => {
        sum += Number(input.value || 0);
    });
    return sum;
}

function recalcPayments() {
    const paid = paymentsPaidSum();
    const diff = Math.round((currentSaleTotal - paid) * 100) / 100;
    payTotalEl.textContent = brMoney(currentSaleTotal);
    payPaidEl.textContent = brMoney(paid);

    if (Math.abs(diff) < 0.005) {
        payDiffLabel.textContent = 'Fechado';
        payDiffEl.textContent = 'OK';
        payDiffBox.className = 'rounded-2xl bg-emerald-100 p-2';
        payDiffLabel.className = 'text-[11px] font-semibold uppercase text-emerald-700';
        payDiffEl.className = 'text-sm font-black text-emerald-800';
    } else if (diff > 0) {
        payDiffLabel.textContent = 'Falta';
        payDiffEl.textContent = brMoney(diff);
        payDiffBox.className = 'rounded-2xl bg-amber-100 p-2';
        payDiffLabel.className = 'text-[11px] font-semibold uppercase text-amber-700';
        payDiffEl.className = 'text-sm font-black text-amber-800';
    } else {
        payDiffLabel.textContent = 'Troco';
        payDiffEl.textContent = brMoney(-diff);
        payDiffBox.className = 'rounded-2xl bg-sky-100 p-2';
        payDiffLabel.className = 'text-[11px] font-semibold uppercase text-sky-700';
        payDiffEl.className = 'text-sm font-black text-sky-800';
    }
}

function addPayment(prefillRemaining = true) {
    const remaining = Math.round((currentSaleTotal - paymentsPaidSum()) * 100) / 100;
    const wrapper = document.createElement('div');
    wrapper.setAttribute('data-payment-row', '1');
    wrapper.className = 'rounded-2xl border border-slate-200 p-3';
    wrapper.innerHTML = `
        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
            <select name="payments[method][]" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm outline-none focus:border-emerald-500">
                ${paymentMethodOptions.map(([v, l]) => `<option value="${v}">${l}</option>`).join('')}
            </select>
            <input type="number" min="0" step="0.01" name="payments[amount][]" placeholder="Valor" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm outline-none focus:border-emerald-500">
        </div>
        <div data-troca-fields class="mt-2 hidden grid-cols-1 gap-2 sm:grid-cols-3">
            <input type="text" name="payments[troca_desc][]" placeholder="Item recebido (ex.: Roda aro 15 usada)" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm outline-none focus:border-emerald-500 sm:col-span-2">
            <input type="number" min="1" step="1" value="1" name="payments[troca_qty][]" placeholder="Qtd" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm outline-none focus:border-emerald-500">
        </div>
        <div class="mt-2 flex items-center justify-between">
            <button type="button" data-fill-remaining class="text-xs font-bold text-emerald-700 underline">= restante</button>
            <button type="button" data-remove-payment class="text-xs font-bold text-rose-700 underline">Remover</button>
        </div>
    `;

    const methodSelect = wrapper.querySelector('select[name="payments[method][]"]');
    const amountInput = wrapper.querySelector('input[name="payments[amount][]"]');
    const trocaFields = wrapper.querySelector('[data-troca-fields]');
    const trocaDesc = wrapper.querySelector('input[name="payments[troca_desc][]"]');

    if (prefillRemaining && remaining > 0) {
        amountInput.value = remaining.toFixed(2);
    }

    function syncTroca() {
        const isTroca = methodSelect.value === 'troca';
        trocaFields.classList.toggle('hidden', !isTroca);
        trocaFields.classList.toggle('grid', isTroca);
        trocaDesc.required = isTroca;
    }

    methodSelect.addEventListener('change', syncTroca);
    amountInput.addEventListener('input', recalcPayments);
    wrapper.querySelector('[data-fill-remaining]').addEventListener('click', () => {
        const rem = Math.round((currentSaleTotal - paymentsPaidSum() + Number(amountInput.value || 0)) * 100) / 100;
        amountInput.value = (rem > 0 ? rem : 0).toFixed(2);
        recalcPayments();
    });
    wrapper.querySelector('[data-remove-payment]').addEventListener('click', () => {
        wrapper.remove();
        recalcPayments();
    });

    paymentsWrapper.appendChild(wrapper);
    syncTroca();
    recalcPayments();
}

addItem();
addPayment(false);
recalcTotal();

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
    updateNfeAddressRequirement();
}

function updateNfeAddressRequirement() {
    const shouldRequireAddress = issueNfeInput.checked;
    nfeRequiredAddressInputs.forEach((input) => {
        input.required = shouldRequireAddress;
        input.classList.toggle('border-emerald-400', shouldRequireAddress);
        input.classList.toggle('bg-emerald-50', shouldRequireAddress);
    });
}

issueNfeInput.addEventListener('change', updateNfeAddressRequirement);
updateNfeAddressRequirement();

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

