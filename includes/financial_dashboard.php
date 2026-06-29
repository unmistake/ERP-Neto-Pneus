<?php

declare(strict_types=1);

function financialTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Mix de pagamento por meio no periodo, somando as linhas de sale_payments.
 * Vendas antigas sem linhas usam o payment_method unico como fallback.
 *
 * @return array<string,float> metodo => valor
 */
function financialPaymentMix(PDO $pdo, string $start, string $end): array
{
    $mix = [];

    if (financialTableExists($pdo, 'sale_payments')) {
        $stmt = $pdo->prepare(
            'SELECT sp.method AS method, SUM(sp.amount) AS total
             FROM sale_payments sp
             INNER JOIN sales s ON s.id = sp.sale_id
             WHERE s.created_at >= ? AND s.created_at < ?
             GROUP BY sp.method'
        );
        $stmt->execute([$start, $end]);
        foreach ($stmt->fetchAll() as $row) {
            $method = (string) ($row['method'] ?: 'nao informado');
            $mix[$method] = ($mix[$method] ?? 0.0) + (float) $row['total'];
        }

        $legacy = $pdo->prepare(
            'SELECT COALESCE(NULLIF(TRIM(s.payment_method), \'\'), \'nao informado\') AS method, SUM(s.total_amount) AS total
             FROM sales s
             LEFT JOIN sale_payments sp ON sp.sale_id = s.id
             WHERE s.created_at >= ? AND s.created_at < ? AND sp.id IS NULL
             GROUP BY method'
        );
        $legacy->execute([$start, $end]);
        foreach ($legacy->fetchAll() as $row) {
            $method = (string) $row['method'];
            $mix[$method] = ($mix[$method] ?? 0.0) + (float) $row['total'];
        }

        return $mix;
    }

    $stmt = $pdo->prepare(
        'SELECT COALESCE(NULLIF(TRIM(s.payment_method), \'\'), \'nao informado\') AS method, SUM(s.total_amount) AS total
         FROM sales s
         WHERE s.created_at >= ? AND s.created_at < ?
         GROUP BY method'
    );
    $stmt->execute([$start, $end]);
    foreach ($stmt->fetchAll() as $row) {
        $mix[(string) $row['method']] = (float) $row['total'];
    }

    return $mix;
}

function financialNormalizeText(string $value): string
{
    $value = mb_strtolower(trim($value));
    $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if (is_string($converted)) {
        $value = $converted;
    }
    $value = preg_replace('/\b(pneu|pneus|roda|rodas|novo|nova|usado|usada|seminovo|seminova|imp|xl|tl|r)\b/i', ' ', $value) ?? $value;
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;
    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
}

function financialProductMatchScore(array $product, string $itemText): int
{
    $score = 0;
    foreach (['brand' => 4, 'model' => 4, 'width' => 3, 'profile' => 3, 'rim' => 3] as $field => $weight) {
        $value = financialNormalizeText((string) ($product[$field] ?? ''));
        if ($value !== '' && str_contains($itemText, $value)) {
            $score += $weight;
        }
    }

    $nameTokens = array_filter(explode(' ', financialNormalizeText((string) ($product['name'] ?? ''))));
    foreach ($nameTokens as $token) {
        if (strlen($token) >= 3 && str_contains($itemText, $token)) {
            $score += 1;
        }
    }

    return $score;
}

function financialBuildInboundCostLookup(PDO $pdo): array
{
    if (!financialTableExists($pdo, 'inbound_nfe_items')) {
        return ['items' => [], 'global_avg' => 0.0, 'count' => 0];
    }

    $rows = $pdo->query(
        'SELECT description, unit_price, quantity, total_amount
         FROM inbound_nfe_items
         WHERE (unit_price IS NOT NULL AND unit_price > 0)
            OR (total_amount IS NOT NULL AND total_amount > 0 AND quantity IS NOT NULL AND quantity > 0)'
    )->fetchAll();

    $items = [];
    $sum = 0.0;
    $count = 0;
    foreach ($rows as $row) {
        $unit = (float) ($row['unit_price'] ?? 0);
        $qty = (float) ($row['quantity'] ?? 0);
        $total = (float) ($row['total_amount'] ?? 0);
        if ($unit <= 0 && $qty > 0 && $total > 0) {
            $unit = $total / $qty;
        }
        if ($unit <= 0) {
            continue;
        }

        $items[] = [
            'description' => (string) ($row['description'] ?? ''),
            'normalized' => financialNormalizeText((string) ($row['description'] ?? '')),
            'unit_price' => $unit,
        ];
        $sum += $unit;
        $count++;
    }

    return [
        'items' => $items,
        'global_avg' => $count > 0 ? $sum / $count : 0.0,
        'count' => $count,
    ];
}

function financialEstimateUnitCost(array $product, array $lookup, array &$cache): array
{
    $productId = (int) ($product['product_id'] ?? 0);
    if ($productId > 0 && isset($cache[$productId])) {
        return $cache[$productId];
    }

    $bestScore = 0;
    $bestCosts = [];
    foreach ($lookup['items'] as $item) {
        $score = financialProductMatchScore($product, (string) $item['normalized']);
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestCosts = [(float) $item['unit_price']];
        } elseif ($score > 0 && $score === $bestScore) {
            $bestCosts[] = (float) $item['unit_price'];
        }
    }

    if ($bestScore >= 6 && $bestCosts) {
        $result = [array_sum($bestCosts) / count($bestCosts), 'NF-e fornecedor'];
    } elseif ((float) ($product['cost_price'] ?? 0) > 0) {
        $result = [(float) $product['cost_price'], 'Estoque'];
    } elseif ((float) ($lookup['global_avg'] ?? 0) > 0) {
        $result = [(float) $lookup['global_avg'], 'Media NF-e'];
    } else {
        $result = [0.0, 'Sem custo'];
    }

    if ($productId > 0) {
        $cache[$productId] = $result;
    }
    return $result;
}

function financialDashboardData(PDO $pdo): array
{
    $start30 = (new DateTimeImmutable('today'))->modify('-29 days');
    $today = new DateTimeImmutable('today');
    $tomorrow = $today->modify('+1 day');
    $monthStart = new DateTimeImmutable(date('Y-m-01'));

    $lookup = financialBuildInboundCostLookup($pdo);
    $costCache = [];

    $stmt = $pdo->prepare(
        'SELECT
            s.id AS sale_id,
            s.created_at,
            COALESCE(NULLIF(TRIM(s.seller_name), \'\'), \'Sem vendedor\') AS seller_name,
            s.total_amount AS sale_total,
            s.payment_method,
            si.product_id,
            si.quantity,
            si.unit_price,
            si.line_total,
            p.name AS product_name,
            p.brand,
            p.model,
            p.width,
            p.profile,
            p.rim,
            p.cost_price
         FROM sales s
         INNER JOIN sale_items si ON si.sale_id = s.id
         LEFT JOIN products p ON p.id = si.product_id
         WHERE s.created_at >= ? AND s.created_at < ?
         ORDER BY s.created_at ASC, s.id ASC'
    );
    $stmt->execute([$start30->format('Y-m-d 00:00:00'), $tomorrow->format('Y-m-d 00:00:00')]);
    $rows30 = $stmt->fetchAll();

    $monthStmt = $pdo->prepare(
        'SELECT
            s.id AS sale_id,
            s.created_at,
            COALESCE(NULLIF(TRIM(s.seller_name), \'\'), \'Sem vendedor\') AS seller_name,
            s.total_amount AS sale_total,
            s.payment_method,
            si.product_id,
            si.quantity,
            si.unit_price,
            si.line_total,
            p.name AS product_name,
            p.brand,
            p.model,
            p.width,
            p.profile,
            p.rim,
            p.cost_price
         FROM sales s
         INNER JOIN sale_items si ON si.sale_id = s.id
         LEFT JOIN products p ON p.id = si.product_id
         WHERE s.created_at >= ? AND s.created_at < ?
         ORDER BY s.created_at ASC, s.id ASC'
    );
    $monthStmt->execute([$monthStart->format('Y-m-d 00:00:00'), $tomorrow->format('Y-m-d 00:00:00')]);
    $rowsMonth = $monthStmt->fetchAll();

    $salariedSellers = ['Daniel' => 800.0, 'Felipe' => 800.0, 'Eriko' => 800.0];
    $dailySalary = array_sum($salariedSellers) / 7;

    $labels = [];
    $dates = [];
    $daily = [];
    for ($cursor = $start30; $cursor <= $today; $cursor = $cursor->modify('+1 day')) {
        $key = $cursor->format('Y-m-d');
        $dates[] = $key;
        $labels[] = $cursor->format('d/m');
        $daily[$key] = ['revenue' => 0.0, 'cogs' => 0.0, 'net_profit' => -$dailySalary, 'sales' => []];
    }

    $sellerSeries = [];
    $sellerTotals30 = [];
    foreach ($rows30 as $row) {
        $date = substr((string) $row['created_at'], 0, 10);
        if (!isset($daily[$date])) {
            continue;
        }
        $seller = (string) $row['seller_name'];
        if (!isset($sellerSeries[$seller])) {
            $sellerSeries[$seller] = array_fill_keys($dates, 0.0);
            $sellerTotals30[$seller] = 0.0;
        }

        $lineTotal = (float) $row['line_total'];
        [$unitCost] = financialEstimateUnitCost($row, $lookup, $costCache);
        $estimatedCost = $unitCost * (float) $row['quantity'];

        $daily[$date]['revenue'] += $lineTotal;
        $daily[$date]['cogs'] += $estimatedCost;
        $daily[$date]['net_profit'] += $lineTotal - $estimatedCost;
        $sellerSeries[$seller][$date] += $lineTotal;
        $sellerTotals30[$seller] += $lineTotal;
    }

    arsort($sellerTotals30);
    $sellerSeries = array_intersect_key($sellerSeries, $sellerTotals30);

    $monthSales = [];
    $sellerMonth = [];
    $paymentMix = [];
    $monthRevenue = 0.0;
    $monthCogs = 0.0;
    $uncostedItems = 0;
    $costSourceCounts = ['NF-e fornecedor' => 0, 'Estoque' => 0, 'Media NF-e' => 0, 'Sem custo' => 0];

    foreach ($rowsMonth as $row) {
        $saleId = (int) $row['sale_id'];
        $seller = (string) $row['seller_name'];
        $payment = (string) ($row['payment_method'] ?: 'nao informado');
        $lineTotal = (float) $row['line_total'];
        [$unitCost, $costSource] = financialEstimateUnitCost($row, $lookup, $costCache);
        $estimatedCost = $unitCost * (float) $row['quantity'];

        if (!isset($monthSales[$saleId])) {
            $monthSales[$saleId] = [
                'total' => (float) $row['sale_total'],
                'seller' => $seller,
                'payment' => $payment,
            ];
        }

        if (!isset($sellerMonth[$seller])) {
            $sellerMonth[$seller] = ['revenue' => 0.0, 'cogs' => 0.0, 'sales' => [], 'biggest' => 0.0];
        }
        $sellerMonth[$seller]['revenue'] += $lineTotal;
        $sellerMonth[$seller]['cogs'] += $estimatedCost;
        $sellerMonth[$seller]['sales'][$saleId] = true;
        $sellerMonth[$seller]['biggest'] = max($sellerMonth[$seller]['biggest'], (float) $row['sale_total']);

        $monthRevenue += $lineTotal;
        $monthCogs += $estimatedCost;
        $costSourceCounts[$costSource] = ($costSourceCounts[$costSource] ?? 0) + 1;
        if ($unitCost <= 0) {
            $uncostedItems++;
        }
    }

    $paymentMix = financialPaymentMix(
        $pdo,
        $monthStart->format('Y-m-d 00:00:00'),
        $tomorrow->format('Y-m-d 00:00:00')
    );

    $elapsedDays = (int) $monthStart->diff($today)->days + 1;
    $salaryMonth = $dailySalary * $elapsedDays;
    $monthNetProfit = $monthRevenue - $monthCogs - $salaryMonth;
    $salesCount = count($monthSales);
    $avgTicket = $salesCount > 0 ? array_sum(array_column($monthSales, 'total')) / $salesCount : 0.0;

    uasort($sellerMonth, static fn ($a, $b) => ($b['revenue'] <=> $a['revenue']));
    $sellerRanking = [];
    foreach ($sellerMonth as $seller => $stats) {
        $salesQty = count($stats['sales']);
        $sellerSalary = isset($salariedSellers[$seller]) ? ($salariedSellers[$seller] / 7) * $elapsedDays : 0.0;
        $sellerRanking[] = [
            'seller' => $seller,
            'revenue' => $stats['revenue'],
            'sales_count' => $salesQty,
            'avg_ticket' => $salesQty > 0 ? $stats['revenue'] / $salesQty : 0.0,
            'biggest_sale' => $stats['biggest'],
            'estimated_profit' => $stats['revenue'] - $stats['cogs'] - $sellerSalary,
        ];
    }

    $paymentRows = [];
    foreach ($paymentMix as $method => $amount) {
        $paymentRows[] = [
            'method' => $method,
            'amount' => $amount,
            'share' => $monthRevenue > 0 ? ($amount / $monthRevenue) * 100 : 0,
        ];
    }
    usort($paymentRows, static fn ($a, $b) => $b['amount'] <=> $a['amount']);

    $revenues = [];
    $cogs = [];
    $netProfits = [];
    foreach ($dates as $date) {
        $revenues[] = round($daily[$date]['revenue'], 2);
        $cogs[] = round($daily[$date]['cogs'], 2);
        $netProfits[] = round($daily[$date]['net_profit'], 2);
    }

    $sellerDatasets = [];
    $palette = ['#0f766e', '#2563eb', '#f97316', '#7c3aed', '#be123c', '#0891b2', '#65a30d', '#9333ea'];
    $idx = 0;
    foreach ($sellerSeries as $seller => $series) {
        $sellerDatasets[] = [
            'label' => $seller,
            'data' => array_map(static fn ($value) => round((float) $value, 2), array_values($series)),
            'borderColor' => $palette[$idx % count($palette)],
            'backgroundColor' => $palette[$idx % count($palette)],
            'hidden' => $idx >= 4,
        ];
        $idx++;
    }

    $margin = $monthRevenue > 0 ? ($monthNetProfit / $monthRevenue) * 100 : 0.0;
    $breakEvenDaily = $dailySalary;
    $bestSeller = $sellerRanking[0] ?? null;
    $topPayment = $paymentRows[0] ?? null;

    $insights = [];
    if ($bestSeller) {
        $insights[] = [
            'title' => 'Replicar o playbook do vendedor lider',
            'body' => $bestSeller['seller'] . ' lidera o mes com ' . money((float) $bestSeller['revenue']) . '. Vale mapear abordagem, produtos mais vendidos e ticket para treinar o restante do time.',
        ];
    }
    $insights[] = [
        'title' => 'Ponto de equilibrio comercial',
        'body' => 'A folha dos vendedores considerados no painel custa cerca de ' . money($breakEvenDaily) . ' por dia. O dashboard precisa ser usado para garantir que margem diaria supere esse piso antes de escalar descontos.',
    ];
    if ($margin < 15 && $monthRevenue > 0) {
        $insights[] = [
            'title' => 'Margem liquida pressionada',
            'body' => 'A margem liquida estimada esta em ' . number_format($margin, 1, ',', '.') . '%. Prioridade: revisar precos dos itens sem custo confiavel e limitar descontos em produtos de baixo giro.',
        ];
    } else {
        $insights[] = [
            'title' => 'Margem estimada saudavel, mas precisa de custo completo',
            'body' => 'A margem atual parece positiva, porem ' . $uncostedItems . ' item(ns) vendido(s) ainda ficaram sem custo direto. Melhorar match das NF-e aumenta precisao da decisao.',
        ];
    }
    if ($topPayment) {
        $insights[] = [
            'title' => 'Mix de pagamento como alavanca de caixa',
            'body' => ucfirst((string) $topPayment['method']) . ' representa ' . number_format((float) $topPayment['share'], 1, ',', '.') . '% do volume do mes. Use isso para negociar taxas, antecipacao e descontos por pagamento a vista.',
        ];
    }

    return [
        'labels' => $labels,
        'revenues' => $revenues,
        'cogs' => $cogs,
        'net_profits' => $netProfits,
        'seller_datasets' => $sellerDatasets,
        'seller_ranking' => $sellerRanking,
        'payment_rows' => $paymentRows,
        'insights' => $insights,
        'kpis' => [
            'month_revenue' => $monthRevenue,
            'month_cogs' => $monthCogs,
            'salary_month' => $salaryMonth,
            'month_net_profit' => $monthNetProfit,
            'margin' => $margin,
            'sales_count' => $salesCount,
            'avg_ticket' => $avgTicket,
            'inbound_cost_count' => (int) $lookup['count'],
            'inbound_global_avg' => (float) $lookup['global_avg'],
            'uncosted_items' => $uncostedItems,
            'cost_source_counts' => $costSourceCounts,
        ],
    ];
}

function renderFinancialIntelligencePanel(PDO $pdo, string $title = 'Dashboard financeiro'): void
{
    $data = financialDashboardData($pdo);
    $chartId = 'financialChart_' . bin2hex(random_bytes(4));
    ?>
    <div class="space-y-6">
        <div class="overflow-hidden rounded-3xl bg-slate-950 text-white shadow-xl">
            <div class="grid gap-6 p-6 lg:grid-cols-[1.2fr_0.8fr] lg:p-8">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.3em] text-emerald-300">Inteligencia financeira</p>
                    <h2 class="mt-3 text-3xl font-black tracking-tight sm:text-4xl"><?= htmlspecialchars($title) ?></h2>
                    <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-300">
                        Faturamento, vendedores, custo estimado por NF-e de fornecedores e lucro liquido considerando folha semanal de Daniel, Felipe e Eriko.
                    </p>
                </div>
                <div class="rounded-3xl border border-white/10 bg-white/10 p-5 backdrop-blur">
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-300">Lucro liquido estimado no mes</p>
                    <p class="mt-2 text-4xl font-black <?= $data['kpis']['month_net_profit'] >= 0 ? 'text-emerald-300' : 'text-rose-300' ?>">
                        <?= money((float) $data['kpis']['month_net_profit']) ?>
                    </p>
                    <p class="mt-2 text-sm text-slate-300">Margem liquida estimada: <?= number_format((float) $data['kpis']['margin'], 1, ',', '.') ?>%</p>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-2xl bg-white p-5 shadow"><p class="text-sm text-slate-500">Faturamento do mes</p><p class="mt-2 text-2xl font-black text-slate-900"><?= money((float) $data['kpis']['month_revenue']) ?></p><p class="text-xs text-slate-400"><?= (int) $data['kpis']['sales_count'] ?> venda(s)</p></div>
            <div class="rounded-2xl bg-white p-5 shadow"><p class="text-sm text-slate-500">Ticket medio</p><p class="mt-2 text-2xl font-black text-slate-900"><?= money((float) $data['kpis']['avg_ticket']) ?></p><p class="text-xs text-slate-400">Base: mes atual</p></div>
            <div class="rounded-2xl bg-white p-5 shadow"><p class="text-sm text-slate-500">CMV estimado</p><p class="mt-2 text-2xl font-black text-slate-900"><?= money((float) $data['kpis']['month_cogs']) ?></p><p class="text-xs text-slate-400"><?= (int) $data['kpis']['inbound_cost_count'] ?> custos de NF-e na base</p></div>
            <div class="rounded-2xl bg-white p-5 shadow"><p class="text-sm text-slate-500">Folha comercial estimada</p><p class="mt-2 text-2xl font-black text-slate-900"><?= money((float) $data['kpis']['salary_month']) ?></p><p class="text-xs text-slate-400">Daniel, Felipe e Eriko: R$ 800/semana</p></div>
        </div>

        <div class="rounded-3xl bg-white p-4 shadow lg:p-6">
            <div class="mb-4 flex flex-col gap-2 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <h3 class="text-xl font-black text-slate-900">Faturamento, lucro liquido e vendedores</h3>
                    <p class="text-sm text-slate-500">Clique nas legendas ou nos vendedores para exibir/tirar series do grafico.</p>
                </div>
                <div class="flex flex-wrap gap-2" id="<?= $chartId ?>_toggles"></div>
            </div>
            <div class="relative h-[420px]">
                <canvas id="<?= $chartId ?>"></canvas>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
            <div class="rounded-3xl bg-white p-5 shadow xl:col-span-2">
                <h3 class="text-lg font-black text-slate-900">Ranking de vendedores no mes</h3>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr><th class="p-3 text-left">Vendedor</th><th class="p-3 text-right">Volume</th><th class="p-3 text-right">Qtd.</th><th class="p-3 text-right">Ticket</th><th class="p-3 text-right">Maior venda</th><th class="p-3 text-right">Lucro est.</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (!$data['seller_ranking']): ?>
                                <tr><td colspan="6" class="p-4 text-center text-slate-500">Nenhuma venda no mes atual.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($data['seller_ranking'] as $seller): ?>
                                <tr class="hover:bg-slate-50">
                                    <td class="p-3 font-bold text-slate-900"><?= htmlspecialchars((string) $seller['seller']) ?></td>
                                    <td class="p-3 text-right font-semibold"><?= money((float) $seller['revenue']) ?></td>
                                    <td class="p-3 text-right"><?= (int) $seller['sales_count'] ?></td>
                                    <td class="p-3 text-right"><?= money((float) $seller['avg_ticket']) ?></td>
                                    <td class="p-3 text-right"><?= money((float) $seller['biggest_sale']) ?></td>
                                    <td class="p-3 text-right font-semibold <?= $seller['estimated_profit'] >= 0 ? 'text-emerald-700' : 'text-rose-700' ?>"><?= money((float) $seller['estimated_profit']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="rounded-3xl bg-white p-5 shadow">
                <h3 class="text-lg font-black text-slate-900">Metodos de pagamento</h3>
                <div class="mt-4 space-y-3">
                    <?php if (!$data['payment_rows']): ?>
                        <p class="text-sm text-slate-500">Nenhuma venda no mes atual.</p>
                    <?php endif; ?>
                    <?php foreach ($data['payment_rows'] as $row): ?>
                        <div>
                            <div class="flex justify-between text-sm"><span class="font-semibold capitalize text-slate-700"><?= htmlspecialchars((string) $row['method']) ?></span><span><?= number_format((float) $row['share'], 1, ',', '.') ?>%</span></div>
                            <div class="mt-1 h-2 rounded-full bg-slate-100"><div class="h-2 rounded-full bg-emerald-500" style="width: <?= max(2, min(100, (float) $row['share'])) ?>%"></div></div>
                            <p class="mt-1 text-xs text-slate-500"><?= money((float) $row['amount']) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <?php foreach ($data['insights'] as $insight): ?>
                <div class="rounded-3xl border border-emerald-100 bg-emerald-50 p-5">
                    <p class="text-sm font-black uppercase tracking-wide text-emerald-800"><?= htmlspecialchars((string) $insight['title']) ?></p>
                    <p class="mt-2 text-sm leading-6 text-slate-700"><?= htmlspecialchars((string) $insight['body']) ?></p>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="rounded-3xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">
            <p class="font-black">Nota de precisao</p>
            <p class="mt-1">Lucro liquido e CMV sao estimativas gerenciais. O match de custo usa NF-e recebidas quando possivel, depois custo cadastrado no estoque e, por ultimo, media geral de NF-e. Itens sem custo direto: <?= (int) $data['kpis']['uncosted_items'] ?>.</p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
    (() => {
        const labels = <?= json_encode($data['labels'], JSON_UNESCAPED_UNICODE) ?>;
        const sellerDatasets = <?= json_encode($data['seller_datasets'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const chart = new Chart(document.getElementById('<?= $chartId ?>'), {
            data: {
                labels,
                datasets: [
                    {type: 'bar', label: 'Faturamento total', data: <?= json_encode($data['revenues']) ?>, backgroundColor: 'rgba(15, 118, 110, 0.18)', borderColor: '#0f766e', borderWidth: 1, order: 3},
                    {type: 'line', label: 'Lucro liquido estimado', data: <?= json_encode($data['net_profits']) ?>, borderColor: '#16a34a', backgroundColor: 'rgba(22, 163, 74, 0.12)', borderWidth: 3, tension: 0.3, fill: false, order: 1},
                    {type: 'line', label: 'CMV estimado', data: <?= json_encode($data['cogs']) ?>, borderColor: '#f97316', backgroundColor: 'rgba(249, 115, 22, 0.12)', borderDash: [6, 5], borderWidth: 2, tension: 0.3, fill: false, hidden: true, order: 2},
                    ...sellerDatasets.map((dataset) => ({type: 'line', ...dataset, borderWidth: 2, tension: 0.25, pointRadius: 2, fill: false, order: 2}))
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {mode: 'index', intersect: false},
                scales: {
                    y: {ticks: {callback: (value) => 'R$ ' + Number(value).toLocaleString('pt-BR')}}
                },
                plugins: {
                    legend: {position: 'bottom'},
                    tooltip: {callbacks: {label: (ctx) => `${ctx.dataset.label}: ${Number(ctx.raw || 0).toLocaleString('pt-BR', {style: 'currency', currency: 'BRL'})}`}}
                }
            }
        });

        const toggles = document.getElementById('<?= $chartId ?>_toggles');
        chart.data.datasets.forEach((dataset, index) => {
            if (index < 3) return;
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'rounded-full border px-3 py-1 text-xs font-bold transition';
            button.textContent = dataset.label;
            const refresh = () => {
                const visible = chart.isDatasetVisible(index);
                button.style.borderColor = dataset.borderColor;
                button.style.color = visible ? '#fff' : dataset.borderColor;
                button.style.background = visible ? dataset.borderColor : '#fff';
            };
            button.addEventListener('click', () => {
                chart.setDatasetVisibility(index, !chart.isDatasetVisible(index));
                chart.update();
                refresh();
            });
            toggles.appendChild(button);
            refresh();
        });
    })();
    </script>
    <?php
}