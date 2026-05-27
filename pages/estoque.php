<?php
$hasLocationColumn = (bool) $pdo->query("SHOW COLUMNS FROM products LIKE 'location'")->fetch();
if (!$hasLocationColumn) {
    $pdo->exec("ALTER TABLE products ADD COLUMN location VARCHAR(120) NULL AFTER model");
}
$hasCategoryColumn = (bool) $pdo->query("SHOW COLUMNS FROM products LIKE 'category'")->fetch();
if (!$hasCategoryColumn) {
    $pdo->exec("ALTER TABLE products ADD COLUMN category ENUM('pneu','roda') NOT NULL DEFAULT 'pneu' AFTER name");
}
$hasConditionColumn = (bool) $pdo->query("SHOW COLUMNS FROM products LIKE 'item_condition'")->fetch();
if (!$hasConditionColumn) {
    $pdo->exec("ALTER TABLE products ADD COLUMN item_condition ENUM('novo','usado') NOT NULL DEFAULT 'novo' AFTER category");
}
$hasUsedTireConditionColumn = (bool) $pdo->query("SHOW COLUMNS FROM products LIKE 'used_tire_condition'")->fetch();
if (!$hasUsedTireConditionColumn) {
    $pdo->exec("ALTER TABLE products ADD COLUMN used_tire_condition ENUM('seminovo','meia_vida','abaixo_50_twi','seminovo_com_reparo') NULL AFTER item_condition");
}
$hasWidthColumn = (bool) $pdo->query("SHOW COLUMNS FROM products LIKE 'width'")->fetch();
if (!$hasWidthColumn) {
    $pdo->exec("ALTER TABLE products ADD COLUMN width VARCHAR(10) NULL AFTER model");
}
$hasProfileColumn = (bool) $pdo->query("SHOW COLUMNS FROM products LIKE 'profile'")->fetch();
if (!$hasProfileColumn) {
    $pdo->exec("ALTER TABLE products ADD COLUMN profile VARCHAR(10) NULL AFTER width");
}
$hasRimColumn = (bool) $pdo->query("SHOW COLUMNS FROM products LIKE 'rim'")->fetch();
if (!$hasRimColumn) {
    $pdo->exec("ALTER TABLE products ADD COLUMN rim VARCHAR(10) NULL AFTER profile");
}
$pdo->exec("UPDATE products SET location = 'Depósito' WHERE LOWER(TRIM(location)) = 'no andar de cima da loja' OR LOWER(TRIM(location)) = 'andar de cima da loja'");

$search = trim((string) ($_GET['search'] ?? ''));
$stockFilter = (string) ($_GET['stock_filter'] ?? 'all');
$usedTireConditionFilter = (string) ($_GET['used_tire_condition'] ?? '');

$allProducts = $pdo->query('SELECT * FROM products ORDER BY name')->fetchAll();

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(name LIKE ? OR brand LIKE ? OR model LIKE ? OR location LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($stockFilter === 'positive') {
    $where[] = 'stock_qty > 0';
} elseif ($stockFilter === 'zero') {
    $where[] = 'stock_qty = 0';
} elseif ($stockFilter === 'negative') {
    $where[] = 'stock_qty < 0';
}

$validUsedTireConditions = ['seminovo', 'meia_vida', 'abaixo_50_twi', 'seminovo_com_reparo'];
if (in_array($usedTireConditionFilter, $validUsedTireConditions, true)) {
    $where[] = 'used_tire_condition = ?';
    $params[] = $usedTireConditionFilter;
}

$sql = 'SELECT * FROM products';
if (count($where) > 0) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY created_at DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();
?>

<h2 class="text-2xl font-bold mb-4">Gestao de Estoque</h2>

<form method="get" class="bg-white p-4 rounded-lg shadow mb-4 grid grid-cols-1 md:grid-cols-5 gap-3">
    <input type="hidden" name="page" value="estoque">
    <input name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Buscar por produto, marca, modelo ou local" class="w-full border rounded px-3 py-2">
    <select name="stock_filter" class="w-full border rounded px-3 py-2">
        <option value="all" <?= $stockFilter === 'all' ? 'selected' : '' ?>>Todos os estoques</option>
        <option value="positive" <?= $stockFilter === 'positive' ? 'selected' : '' ?>>Maior que zero</option>
        <option value="zero" <?= $stockFilter === 'zero' ? 'selected' : '' ?>>Zerado</option>
        <option value="negative" <?= $stockFilter === 'negative' ? 'selected' : '' ?>>Negativo</option>
    </select>
    <select name="used_tire_condition" class="w-full border rounded px-3 py-2">
        <option value="">Todos os usados</option>
        <option value="seminovo" <?= $usedTireConditionFilter === 'seminovo' ? 'selected' : '' ?>>Seminovo</option>
        <option value="meia_vida" <?= $usedTireConditionFilter === 'meia_vida' ? 'selected' : '' ?>>Meia vida</option>
        <option value="abaixo_50_twi" <?= $usedTireConditionFilter === 'abaixo_50_twi' ? 'selected' : '' ?>>Abaixo de 50% do TWI</option>
        <option value="seminovo_com_reparo" <?= $usedTireConditionFilter === 'seminovo_com_reparo' ? 'selected' : '' ?>>Seminovo com reparo</option>
    </select>
    <button class="bg-slate-900 text-white rounded px-4 py-2">Filtrar</button>
    <a href="index.php?page=estoque" class="bg-slate-200 text-slate-800 rounded px-4 py-2 text-center">Limpar</a>
</form>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
    <form class="bg-white p-4 rounded-lg shadow space-y-3" method="post" action="actions/product_save.php">
        <h3 class="font-semibold">Novo produto</h3>
        <input required name="name" placeholder="Nome do pneu" class="w-full border rounded px-3 py-2">
        <select name="category" id="category" class="w-full border rounded px-3 py-2">
            <option value="pneu">Pneu</option>
            <option value="roda">Roda</option>
        </select>
        <select name="item_condition" id="item_condition" class="w-full border rounded px-3 py-2">
            <option value="novo">Novo</option>
            <option value="usado">Usado</option>
        </select>
        <div id="used-tire-condition-wrap" class="hidden">
            <select name="used_tire_condition" id="used_tire_condition" class="w-full border rounded px-3 py-2">
                <option value="">Classificacao do usado</option>
                <option value="seminovo">Seminovo</option>
                <option value="meia_vida">Meia vida</option>
                <option value="abaixo_50_twi">Abaixo de 50% do TWI</option>
                <option value="seminovo_com_reparo">Seminovo com reparo</option>
            </select>
        </div>
        <input name="brand" placeholder="Marca" class="w-full border rounded px-3 py-2">
        <input name="model" placeholder="Modelo" class="w-full border rounded px-3 py-2">
        <div id="pneu-fields" class="grid grid-cols-3 gap-2">
            <input name="width" placeholder="Largura" class="w-full border rounded px-3 py-2">
            <input name="profile" placeholder="Perfil" class="w-full border rounded px-3 py-2">
            <input name="rim_pneu" placeholder="Aro" class="w-full border rounded px-3 py-2">
        </div>
        <div id="roda-fields" class="hidden">
            <input name="rim_roda" placeholder="Aro da roda" class="w-full border rounded px-3 py-2">
        </div>
        <input name="location" placeholder="Local (ex: Prateleira A1)" class="w-full border rounded px-3 py-2">
        <input min="0" step="0.01" type="number" name="cost_price" placeholder="Preco custo (opcional)" class="w-full border rounded px-3 py-2">
        <input min="0" step="0.01" type="number" name="price" placeholder="Preco (opcional)" class="w-full border rounded px-3 py-2">
        <input required min="0" type="number" name="stock_qty" placeholder="Quantidade inicial" class="w-full border rounded px-3 py-2">
        <button class="bg-slate-900 text-white rounded px-4 py-2 w-full">Salvar produto</button>
    </form>

    <form class="bg-white p-4 rounded-lg shadow space-y-3" method="post" action="actions/stock_adjust.php">
        <h3 class="font-semibold">Ajuste rapido de estoque</h3>
        <select name="product_id" required class="w-full border rounded px-3 py-2">
            <option value="">Selecione o produto</option>
            <?php foreach ($allProducts as $product): ?>
                <option value="<?= (int) $product['id'] ?>"><?= htmlspecialchars($product['name']) ?> (Estoque: <?= (int) $product['stock_qty'] ?>)</option>
            <?php endforeach; ?>
        </select>
        <select name="movement_type" class="w-full border rounded px-3 py-2">
            <option value="in">Entrada</option>
            <option value="out">Saida</option>
        </select>
        <input required min="1" type="number" name="quantity" placeholder="Quantidade" class="w-full border rounded px-3 py-2">
        <input name="note" placeholder="Observacao" class="w-full border rounded px-3 py-2">
        <button class="bg-amber-600 text-white rounded px-4 py-2 w-full">Aplicar ajuste</button>
    </form>
</div>

<div class="bg-white rounded-lg shadow overflow-auto">
    <table class="w-full text-sm">
        <thead class="bg-slate-200">
            <tr>
                <th class="p-3 text-left">Produto</th>
                <th class="p-3 text-left">Categoria</th>
                <th class="p-3 text-left">Estado</th>
                <th class="p-3 text-left">Classificacao Usado</th>
                <th class="p-3 text-left">Marca</th>
                <th class="p-3 text-left">Modelo</th>
                <th class="p-3 text-left">Medidas</th>
                <th class="p-3 text-left">Local</th>
                <th class="p-3 text-left">Custo</th>
                <th class="p-3 text-left">Preco</th>
                <th class="p-3 text-left">Estoque</th>
                <th class="p-3 text-left">Acoes</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($products) === 0): ?>
                <tr class="border-t">
                    <td class="p-3" colspan="12">Nenhum produto encontrado com os filtros informados.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($products as $product): ?>
                <tr class="border-t">
                    <td class="p-3"><?= htmlspecialchars($product['name']) ?></td>
                    <td class="p-3"><?= ($product['category'] ?? 'pneu') === 'roda' ? 'Roda' : 'Pneu' ?></td>
                    <td class="p-3"><?= ($product['item_condition'] ?? 'novo') === 'usado' ? 'Usado' : 'Novo' ?></td>
                    <td class="p-3">
                        <?php
                        $u = (string) ($product['used_tire_condition'] ?? '');
                        $labels = [
                            'seminovo' => 'Seminovo',
                            'meia_vida' => 'Meia vida',
                            'abaixo_50_twi' => 'Abaixo de 50% do TWI',
                            'seminovo_com_reparo' => 'Seminovo com reparo',
                        ];
                        echo htmlspecialchars($labels[$u] ?? '-');
                        ?>
                    </td>
                    <td class="p-3"><?= htmlspecialchars((string) $product['brand']) ?></td>
                    <td class="p-3"><?= htmlspecialchars((string) $product['model']) ?></td>
                    <td class="p-3">
                        <?php if (($product['category'] ?? 'pneu') === 'pneu'): ?>
                            <?= htmlspecialchars(trim(((string) ($product['width'] ?? '')) . '|' . ((string) ($product['profile'] ?? '')) . '|' . ((string) ($product['rim'] ?? '')))) ?>
                        <?php else: ?>
                            <?= htmlspecialchars((string) ($product['rim'] ?? '-')) ?>
                        <?php endif; ?>
                    </td>
                    <td class="p-3"><?= htmlspecialchars((string) ($product['location'] ?? '-')) ?></td>
                    <td class="p-3">
                        <input form="price-form-<?= (int) $product['id'] ?>" min="0" step="0.01" type="number" name="cost_price" value="<?= htmlspecialchars((string) $product['cost_price']) ?>" class="w-28 border rounded px-2 py-1">
                    </td>
                    <td class="p-3">
                        <input form="price-form-<?= (int) $product['id'] ?>" min="0" step="0.01" type="number" name="price" value="<?= htmlspecialchars((string) $product['sale_price']) ?>" class="w-28 border rounded px-2 py-1">
                    </td>
                    <td class="p-3 font-bold"><?= (int) $product['stock_qty'] ?></td>
                    <td class="p-3">
                        <div class="flex items-center gap-3">
                            <form id="price-form-<?= (int) $product['id'] ?>" class="js-price-form" method="post" action="actions/product_update_prices.php">
                                <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                                <button type="submit" class="text-sky-700 underline">Salvar preco</button>
                                <span class="ml-2 text-xs text-emerald-700 hidden js-price-saved">Salvo</span>
                            </form>
                            <form method="post" action="actions/product_delete.php" onsubmit="return confirm('Tem certeza que deseja excluir este produto?');">
                                <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                                <button type="submit" class="text-rose-700 underline">Excluir</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
const categorySelect = document.getElementById('category');
const itemConditionSelect = document.getElementById('item_condition');
const pneuFields = document.getElementById('pneu-fields');
const rodaFields = document.getElementById('roda-fields');
const usedTireConditionWrap = document.getElementById('used-tire-condition-wrap');
const usedTireConditionSelect = document.getElementById('used_tire_condition');

function toggleCategoryFields() {
    const isPneu = categorySelect.value === 'pneu';
    pneuFields.classList.toggle('hidden', !isPneu);
    rodaFields.classList.toggle('hidden', isPneu);
    toggleUsedTireCondition();
}

function toggleUsedTireCondition() {
    const isPneuUsed = categorySelect.value === 'pneu' && itemConditionSelect.value === 'usado';
    usedTireConditionWrap.classList.toggle('hidden', !isPneuUsed);
    usedTireConditionSelect.required = isPneuUsed;
    if (!isPneuUsed) {
        usedTireConditionSelect.value = '';
    }
}

categorySelect.addEventListener('change', toggleCategoryFields);
itemConditionSelect.addEventListener('change', toggleUsedTireCondition);
toggleCategoryFields();
toggleUsedTireCondition();

document.querySelectorAll('.js-price-form').forEach((form) => {
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const submitButton = form.querySelector('button[type="submit"]');
        const savedLabel = form.querySelector('.js-price-saved');
        const originalText = submitButton ? submitButton.textContent : '';

        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = 'Salvando...';
        }
        if (savedLabel) {
            savedLabel.classList.add('hidden');
        }

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: new FormData(form),
            });
            const payload = await response.json();
            if (!response.ok || !payload.ok) {
                throw new Error(payload.error || 'Falha ao salvar preco');
            }
            if (savedLabel) {
                savedLabel.classList.remove('hidden');
                setTimeout(() => savedLabel.classList.add('hidden'), 2000);
            }
        } catch (error) {
            alert(error.message || 'Erro ao salvar preco');
        } finally {
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = originalText;
            }
        }
    });
});
</script>
