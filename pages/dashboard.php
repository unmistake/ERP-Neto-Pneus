<?php
$totals = [
    'produtos' => (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn(),
    'estoque' => (int) $pdo->query('SELECT COALESCE(SUM(stock_qty),0) FROM products')->fetchColumn(),
    'vendas' => (float) $pdo->query('SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE DATE(created_at)=CURDATE()')->fetchColumn(),
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

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-4">
    <div class="bg-white rounded-lg p-4 shadow"><p class="text-sm">Produtos</p><p class="text-2xl font-bold"><?= $totals['produtos'] ?></p></div>
    <div class="bg-white rounded-lg p-4 shadow"><p class="text-sm">Itens em estoque</p><p class="text-2xl font-bold"><?= $totals['estoque'] ?></p></div>
    <div class="bg-white rounded-lg p-4 shadow"><p class="text-sm">Vendas hoje</p><p class="text-2xl font-bold"><?= money($totals['vendas']) ?></p></div>
    <div class="bg-white rounded-lg p-4 shadow"><p class="text-sm">Custo medio</p><p class="text-2xl font-bold"><?= money($totals['custo_medio']) ?></p></div>
    <div class="bg-white rounded-lg p-4 shadow"><p class="text-sm">Contas a receber</p><p class="text-2xl font-bold"><?= money($totals['receber']) ?></p></div>
    <div class="bg-white rounded-lg p-4 shadow"><p class="text-sm">Contas a pagar</p><p class="text-2xl font-bold"><?= money($totals['pagar']) ?></p></div>
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
