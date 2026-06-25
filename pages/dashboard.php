<?php
$totals = [
    'produtos' => (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn(),
    'estoque' => (int) $pdo->query('SELECT COALESCE(SUM(stock_qty),0) FROM products')->fetchColumn(),
    'vendas' => (float) $pdo->query('SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE DATE(created_at)=CURDATE()')->fetchColumn(),
    'vendas_semana' => (float) $pdo->query(
        'SELECT COALESCE(SUM(total_amount),0)
         FROM sales
         WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
           AND created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)'
    )->fetchColumn(),
    'quantidade_vendas_semana' => (int) $pdo->query(
        'SELECT COUNT(*)
         FROM sales
         WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
           AND created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)'
    )->fetchColumn(),
    'receber' => (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM accounts_receivable WHERE status='pending'")->fetchColumn(),
    'pagar' => (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM accounts_payable WHERE status='pending'")->fetchColumn(),
    'custo_medio' => (float) $pdo->query('SELECT COALESCE(AVG(cost_price),0) FROM products WHERE cost_price > 0')->fetchColumn(),
];

$dailyRows = $pdo->query(
    "SELECT
        daily_sales.sale_date,
        daily_sales.revenue,
        COALESCE(daily_costs.cost_total, 0) AS cost_total
     FROM (
        SELECT DATE(created_at) AS sale_date, COALESCE(SUM(total_amount), 0) AS revenue
        FROM sales
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
        GROUP BY DATE(created_at)
     ) daily_sales
     LEFT JOIN (
        SELECT DATE(s.created_at) AS sale_date, COALESCE(SUM(si.quantity * p.cost_price), 0) AS cost_total
        FROM sales s
        INNER JOIN sale_items si ON si.sale_id = s.id
        INNER JOIN products p ON p.id = si.product_id
        WHERE s.created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
        GROUP BY DATE(s.created_at)
     ) daily_costs ON daily_costs.sale_date = daily_sales.sale_date
     ORDER BY daily_sales.sale_date ASC"
)->fetchAll();

$sellerRankingRows = $pdo->query(
    "SELECT
        COALESCE(NULLIF(TRIM(seller_name), ''), 'Sem vendedor') AS seller_name,
        COUNT(*) AS sales_count,
        COALESCE(SUM(total_amount), 0) AS sales_volume,
        COALESCE(AVG(total_amount), 0) AS avg_ticket,
        COALESCE(MAX(total_amount), 0) AS biggest_sale
     FROM sales
     WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
       AND created_at < DATE_ADD(LAST_DAY(CURDATE()), INTERVAL 1 DAY)
     GROUP BY COALESCE(NULLIF(TRIM(seller_name), ''), 'Sem vendedor')
     ORDER BY sales_volume DESC, sales_count DESC, avg_ticket DESC
     LIMIT 20"
)->fetchAll();

$sellerHighlights = [
    'volume' => null,
    'count' => null,
    'ticket' => null,
    'biggest' => null,
];

foreach ($sellerRankingRows as $row) {
    if ($sellerHighlights['volume'] === null || (float) $row['sales_volume'] > (float) $sellerHighlights['volume']['sales_volume']) {
        $sellerHighlights['volume'] = $row;
    }
    if ($sellerHighlights['count'] === null || (int) $row['sales_count'] > (int) $sellerHighlights['count']['sales_count']) {
        $sellerHighlights['count'] = $row;
    }
    if ($sellerHighlights['ticket'] === null || (float) $row['avg_ticket'] > (float) $sellerHighlights['ticket']['avg_ticket']) {
        $sellerHighlights['ticket'] = $row;
    }
    if ($sellerHighlights['biggest'] === null || (float) $row['biggest_sale'] > (float) $sellerHighlights['biggest']['biggest_sale']) {
        $sellerHighlights['biggest'] = $row;
    }
}

$dailyMap = [];
foreach ($dailyRows as $row) {
    $dailyMap[$row['sale_date']] = [
        'revenue' => (float) $row['revenue'],
        'cost_total' => (float) $row['cost_total'],
    ];
}

$labels = [];
$revenues = [];
$profits = [];
for ($i = 29; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $labels[] = date('d/m', strtotime($date));
    $revenue = $dailyMap[$date]['revenue'] ?? 0.0;
    $cost = $dailyMap[$date]['cost_total'] ?? 0.0;
    $revenues[] = round($revenue, 2);
    $profits[] = round($revenue - $cost, 2);
}
?>

<h2 class="text-2xl font-bold mb-6">Dashboard</h2>

<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-7">
    <div class="bg-white rounded-lg p-4 shadow"><p class="text-sm">Produtos</p><p class="text-2xl font-bold"><?= $totals['produtos'] ?></p></div>
    <div class="bg-white rounded-lg p-4 shadow"><p class="text-sm">Itens em estoque</p><p class="text-2xl font-bold"><?= $totals['estoque'] ?></p></div>
    <div class="bg-white rounded-lg p-4 shadow"><p class="text-sm">Vendas hoje</p><p class="text-2xl font-bold"><?= money($totals['vendas']) ?></p></div>
    <div class="rounded-lg bg-white p-4 shadow">
        <p class="text-sm">Vendas nos ultimos 7 dias</p>
        <p class="text-2xl font-bold"><?= money($totals['vendas_semana']) ?></p>
        <p class="mt-1 text-xs text-slate-500"><?= $totals['quantidade_vendas_semana'] ?> venda(s)</p>
    </div>
    <div class="bg-white rounded-lg p-4 shadow"><p class="text-sm">Custo medio</p><p class="text-2xl font-bold"><?= money($totals['custo_medio']) ?></p></div>
    <div class="bg-white rounded-lg p-4 shadow"><p class="text-sm">Contas a receber</p><p class="text-2xl font-bold"><?= money($totals['receber']) ?></p></div>
    <div class="bg-white rounded-lg p-4 shadow"><p class="text-sm">Contas a pagar</p><p class="text-2xl font-bold"><?= money($totals['pagar']) ?></p></div>
</div>

<div class="mt-6 rounded-lg bg-white p-4 shadow">
    <div class="mb-4 flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h3 class="text-lg font-semibold">Ranking de vendedores do mes</h3>
            <p class="text-sm text-slate-500">Baseado nas vendas do mes atual registradas no PDV.</p>
        </div>
        <span class="text-xs font-medium uppercase tracking-wide text-slate-400">Top <?= count($sellerRankingRows) ?></span>
    </div>

    <?php if (count($sellerRankingRows) === 0): ?>
        <div class="rounded-lg border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
            Nenhuma venda encontrada no mes atual.
        </div>
    <?php else: ?>
        <div class="mb-4 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-lg border border-blue-100 bg-blue-50 p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-blue-700">Maior volume</p>
                <p class="mt-1 text-lg font-bold text-slate-900"><?= htmlspecialchars((string) $sellerHighlights['volume']['seller_name']) ?></p>
                <p class="text-sm text-slate-600"><?= money((float) $sellerHighlights['volume']['sales_volume']) ?></p>
            </div>
            <div class="rounded-lg border border-emerald-100 bg-emerald-50 p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Mais vendas</p>
                <p class="mt-1 text-lg font-bold text-slate-900"><?= htmlspecialchars((string) $sellerHighlights['count']['seller_name']) ?></p>
                <p class="text-sm text-slate-600"><?= (int) $sellerHighlights['count']['sales_count'] ?> venda(s)</p>
            </div>
            <div class="rounded-lg border border-amber-100 bg-amber-50 p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-amber-700">Maior ticket medio</p>
                <p class="mt-1 text-lg font-bold text-slate-900"><?= htmlspecialchars((string) $sellerHighlights['ticket']['seller_name']) ?></p>
                <p class="text-sm text-slate-600"><?= money((float) $sellerHighlights['ticket']['avg_ticket']) ?></p>
            </div>
            <div class="rounded-lg border border-fuchsia-100 bg-fuchsia-50 p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-fuchsia-700">Maior venda</p>
                <p class="mt-1 text-lg font-bold text-slate-900"><?= htmlspecialchars((string) $sellerHighlights['biggest']['seller_name']) ?></p>
                <p class="text-sm text-slate-600"><?= money((float) $sellerHighlights['biggest']['biggest_sale']) ?></p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="p-3 text-left">#</th>
                        <th class="p-3 text-left">Vendedor</th>
                        <th class="p-3 text-right">Volume vendido</th>
                        <th class="p-3 text-right">Qtd. vendas</th>
                        <th class="p-3 text-right">Ticket medio</th>
                        <th class="p-3 text-right">Maior venda</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($sellerRankingRows as $index => $seller): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="p-3 font-semibold text-slate-500"><?= $index + 1 ?></td>
                            <td class="p-3 font-medium text-slate-900"><?= htmlspecialchars((string) $seller['seller_name']) ?></td>
                            <td class="p-3 text-right font-semibold"><?= money((float) $seller['sales_volume']) ?></td>
                            <td class="p-3 text-right"><?= (int) $seller['sales_count'] ?></td>
                            <td class="p-3 text-right"><?= money((float) $seller['avg_ticket']) ?></td>
                            <td class="p-3 text-right"><?= money((float) $seller['biggest_sale']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="bg-white rounded-lg p-4 shadow mt-6">
    <h3 class="text-lg font-semibold mb-3">Faturamento e Lucro Diario (ultimos 30 dias)</h3>
    <div class="relative h-80">
        <canvas id="dailyRevenueChart"></canvas>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const labels = <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>;
const revenues = <?= json_encode($revenues, JSON_UNESCAPED_UNICODE) ?>;
const profits = <?= json_encode($profits, JSON_UNESCAPED_UNICODE) ?>;

const ctx = document.getElementById('dailyRevenueChart');
new Chart(ctx, {
    type: 'line',
    data: {
        labels,
        datasets: [
            {
                label: 'Faturamento',
                data: revenues,
                borderColor: '#2563eb',
                backgroundColor: 'rgba(37, 99, 235, 0.15)',
                borderWidth: 2,
                tension: 0.25,
                fill: true
            },
            {
                label: 'Lucro',
                data: profits,
                borderColor: '#059669',
                backgroundColor: 'rgba(5, 150, 105, 0.1)',
                borderWidth: 2,
                tension: 0.25,
                fill: false
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: {
                ticks: {
                    callback: (value) => 'R$ ' + Number(value).toFixed(2).replace('.', ',')
                }
            }
        },
        plugins: {
            tooltip: {
                callbacks: {
                    label: (ctx) => `${ctx.dataset.label}: R$ ${Number(ctx.raw).toFixed(2).replace('.', ',')}`
                }
            }
        }
    }
});
</script>
