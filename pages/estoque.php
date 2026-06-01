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

function inventoryFilterValues(string $key): array
{
    $value = $_GET[$key] ?? [];
    if (!is_array($value)) {
        $value = [$value];
    }

    return array_values(array_unique(array_filter(array_map(static fn ($item) => trim((string) $item), $value), static fn ($item) => $item !== '')));
}

function inventoryDistinctOptions(array $products, string $column): array
{
    $values = [];
    foreach ($products as $product) {
        $value = trim((string) ($product[$column] ?? ''));
        if ($value !== '') {
            $values[$value] = $value;
        }
    }
    natcasesort($values);

    return array_values($values);
}

function inventoryRenderFilterDropdown(string $name, string $label, array $options, array $selected, array $labels = []): void
{
    $selectedCount = count($selected);
    ?>
    <div class="relative js-filter-dropdown">
        <button type="button" class="js-filter-toggle w-full border rounded px-3 py-2 bg-white text-left flex items-center justify-between gap-2">
            <span><?= htmlspecialchars($label) ?><?= $selectedCount > 0 ? ' (' . $selectedCount . ')' : '' ?></span>
            <span class="text-slate-500">▾</span>
        </button>
        <div class="js-filter-menu hidden absolute z-30 mt-2 w-72 max-h-80 overflow-auto bg-white border rounded-lg shadow-lg p-3">
            <?php if (count($options) === 0): ?>
                <p class="text-sm text-slate-500">Nenhuma opção disponível.</p>
            <?php endif; ?>
            <?php foreach ($options as $value): ?>
                <label class="flex items-center gap-2 py-1 text-sm cursor-pointer">
                    <input type="checkbox" name="<?= htmlspecialchars($name) ?>[]" value="<?= htmlspecialchars($value) ?>" <?= in_array((string) $value, $selected, true) ? 'checked' : '' ?>>
                    <span><?= htmlspecialchars($labels[$value] ?? (string) $value) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}

$search = trim((string) ($_GET['search'] ?? ''));
$stockFilters = inventoryFilterValues('stock_filter');
$categoryFilters = inventoryFilterValues('category_filter');
$conditionFilters = inventoryFilterValues('condition_filter');
$usedTireConditionFilters = inventoryFilterValues('used_tire_condition');
$brandFilters = inventoryFilterValues('brand_filter');
$modelFilters = inventoryFilterValues('model_filter');
$locationFilters = inventoryFilterValues('location_filter');

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

if (count($stockFilters) > 0) {
    $stockWhere = [];
    if (in_array('positive', $stockFilters, true)) {
        $stockWhere[] = 'stock_qty > 0';
    }
    if (in_array('zero', $stockFilters, true)) {
        $stockWhere[] = 'stock_qty = 0';
    }
    if (in_array('negative', $stockFilters, true)) {
        $stockWhere[] = 'stock_qty < 0';
    }
    if (count($stockWhere) > 0) {
        $where[] = '(' . implode(' OR ', $stockWhere) . ')';
    }
}

$validCategories = ['pneu', 'roda'];
if (count($categoryFilters) > 0) {
    $categoryFilters = array_values(array_intersect($categoryFilters, $validCategories));
    if (count($categoryFilters) > 0) {
        $where[] = 'category IN (' . implode(',', array_fill(0, count($categoryFilters), '?')) . ')';
        array_push($params, ...$categoryFilters);
    }
}

$validConditions = ['novo', 'usado'];
if (count($conditionFilters) > 0) {
    $conditionFilters = array_values(array_intersect($conditionFilters, $validConditions));
    if (count($conditionFilters) > 0) {
        $where[] = 'item_condition IN (' . implode(',', array_fill(0, count($conditionFilters), '?')) . ')';
        array_push($params, ...$conditionFilters);
    }
}

$validUsedTireConditions = ['seminovo', 'meia_vida', 'abaixo_50_twi', 'seminovo_com_reparo'];
if (count($usedTireConditionFilters) > 0) {
    $usedTireConditionFilters = array_values(array_intersect($usedTireConditionFilters, $validUsedTireConditions));
    if (count($usedTireConditionFilters) > 0) {
        $where[] = 'used_tire_condition IN (' . implode(',', array_fill(0, count($usedTireConditionFilters), '?')) . ')';
        array_push($params, ...$usedTireConditionFilters);
    }
}

if (count($brandFilters) > 0) {
    $where[] = 'brand IN (' . implode(',', array_fill(0, count($brandFilters), '?')) . ')';
    array_push($params, ...$brandFilters);
}

if (count($modelFilters) > 0) {
    $where[] = 'model IN (' . implode(',', array_fill(0, count($modelFilters), '?')) . ')';
    array_push($params, ...$modelFilters);
}

if (count($locationFilters) > 0) {
    $where[] = 'location IN (' . implode(',', array_fill(0, count($locationFilters), '?')) . ')';
    array_push($params, ...$locationFilters);
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

<form method="get" class="bg-white p-4 rounded-lg shadow mb-4 space-y-3">
    <input type="hidden" name="page" value="estoque">
    <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
        <input name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Buscar por produto, marca, modelo ou local" class="w-full border rounded px-3 py-2 md:col-span-2">
        <button class="bg-slate-900 text-white rounded px-4 py-2">Aplicar filtros</button>
        <a href="index.php?page=estoque" class="bg-slate-200 text-slate-800 rounded px-4 py-2 text-center">Limpar</a>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3">
        <?php
        inventoryRenderFilterDropdown('brand_filter', 'Marca', inventoryDistinctOptions($allProducts, 'brand'), $brandFilters);
        inventoryRenderFilterDropdown('model_filter', 'Modelo', inventoryDistinctOptions($allProducts, 'model'), $modelFilters);
        inventoryRenderFilterDropdown('location_filter', 'Local', inventoryDistinctOptions($allProducts, 'location'), $locationFilters);
        inventoryRenderFilterDropdown('category_filter', 'Categoria', ['pneu', 'roda'], $categoryFilters, ['pneu' => 'Pneu', 'roda' => 'Roda']);
        inventoryRenderFilterDropdown('condition_filter', 'Estado', ['novo', 'usado'], $conditionFilters, ['novo' => 'Novo', 'usado' => 'Usado']);
        inventoryRenderFilterDropdown('used_tire_condition', 'Classificacao usado', $validUsedTireConditions, $usedTireConditionFilters, [
            'seminovo' => 'Seminovo',
            'meia_vida' => 'Meia vida',
            'abaixo_50_twi' => 'Abaixo de 50% do TWI',
            'seminovo_com_reparo' => 'Seminovo com reparo',
        ]);
        inventoryRenderFilterDropdown('stock_filter', 'Estoque', ['positive', 'zero', 'negative'], $stockFilters, [
            'positive' => 'Maior que zero',
            'zero' => 'Zerado',
            'negative' => 'Negativo',
        ]);
        ?>
    </div>
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
                    <td class="p-3">
                        <input form="price-form-<?= (int) $product['id'] ?>" required name="name" value="<?= htmlspecialchars((string) $product['name']) ?>" class="w-56 border rounded px-2 py-1">
                    </td>
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
                    <td class="p-3">
                        <input form="price-form-<?= (int) $product['id'] ?>" name="brand" value="<?= htmlspecialchars((string) $product['brand']) ?>" class="w-36 border rounded px-2 py-1">
                    </td>
                    <td class="p-3">
                        <input form="price-form-<?= (int) $product['id'] ?>" name="model" value="<?= htmlspecialchars((string) $product['model']) ?>" class="w-36 border rounded px-2 py-1">
                    </td>
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
                                <button type="submit" class="text-sky-700 underline">Salvar</button>
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

document.querySelectorAll('.js-filter-dropdown').forEach((dropdown) => {
    const toggle = dropdown.querySelector('.js-filter-toggle');
    const menu = dropdown.querySelector('.js-filter-menu');
    if (!toggle || !menu) {
        return;
    }

    toggle.addEventListener('click', () => {
        document.querySelectorAll('.js-filter-menu').forEach((otherMenu) => {
            if (otherMenu !== menu) {
                otherMenu.classList.add('hidden');
            }
        });
        menu.classList.toggle('hidden');
    });
});

document.addEventListener('click', (event) => {
    if (event.target.closest('.js-filter-dropdown')) {
        return;
    }
    document.querySelectorAll('.js-filter-menu').forEach((menu) => menu.classList.add('hidden'));
});

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
                throw new Error(payload.error || 'Falha ao salvar produto');
            }
            if (savedLabel) {
                savedLabel.classList.remove('hidden');
                setTimeout(() => savedLabel.classList.add('hidden'), 2000);
            }
        } catch (error) {
            alert(error.message || 'Erro ao salvar produto');
        } finally {
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = originalText;
            }
        }
    });
});
</script>
