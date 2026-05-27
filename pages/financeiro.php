<?php
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS costs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        description VARCHAR(160) NOT NULL,
        category VARCHAR(80) NULL,
        amount DECIMAL(10,2) NOT NULL,
        cost_date DATE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS bank_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bank ENUM('bb','santander','itau') NOT NULL,
        transaction_type ENUM('in','out') NOT NULL,
        description VARCHAR(160) NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        transaction_date DATE NOT NULL,
        reference VARCHAR(120) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )"
);

$payables = $pdo->query('SELECT * FROM accounts_payable ORDER BY due_date ASC')->fetchAll();
$receivables = $pdo->query('SELECT * FROM accounts_receivable ORDER BY due_date ASC')->fetchAll();
$costs = $pdo->query('SELECT * FROM costs ORDER BY cost_date DESC, id DESC')->fetchAll();
$bankTransactions = $pdo->query('SELECT * FROM bank_transactions ORDER BY transaction_date DESC, id DESC')->fetchAll();

$balancesRows = $pdo->query(
    "SELECT
        bank,
        COALESCE(SUM(CASE WHEN transaction_type = 'in' THEN amount ELSE 0 END), 0) AS total_in,
        COALESCE(SUM(CASE WHEN transaction_type = 'out' THEN amount ELSE 0 END), 0) AS total_out
     FROM bank_transactions
     GROUP BY bank"
)->fetchAll();

$bankLabels = [
    'bb' => 'Banco do Brasil',
    'santander' => 'Santander',
    'itau' => 'Itaú',
];
$bankBalances = [
    'bb' => ['in' => 0.0, 'out' => 0.0],
    'santander' => ['in' => 0.0, 'out' => 0.0],
    'itau' => ['in' => 0.0, 'out' => 0.0],
];
foreach ($balancesRows as $row) {
    $bank = (string) ($row['bank'] ?? '');
    if (!isset($bankBalances[$bank])) {
        continue;
    }
    $bankBalances[$bank]['in'] = (float) $row['total_in'];
    $bankBalances[$bank]['out'] = (float) $row['total_out'];
}
?>

<h2 class="text-2xl font-bold mb-4">Financeiro</h2>

<div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-4 gap-6 mb-6">
    <form method="post" action="actions/payable_save.php" class="bg-white rounded-lg shadow p-4 space-y-3">
        <h3 class="font-semibold">Nova conta a pagar</h3>
        <input required name="description" placeholder="Descricao" class="w-full border rounded px-3 py-2">
        <input required type="number" step="0.01" min="0" name="amount" placeholder="Valor" class="w-full border rounded px-3 py-2">
        <input required type="date" name="due_date" class="w-full border rounded px-3 py-2">
        <select name="status" class="w-full border rounded px-3 py-2">
            <option value="pending">Pendente</option>
            <option value="paid">Pago</option>
        </select>
        <button class="w-full bg-rose-600 text-white rounded px-4 py-2">Salvar conta</button>
    </form>

    <form method="post" action="actions/receivable_save.php" class="bg-white rounded-lg shadow p-4 space-y-3">
        <h3 class="font-semibold">Nova conta a receber</h3>
        <input required name="description" placeholder="Descricao" class="w-full border rounded px-3 py-2">
        <input required type="number" step="0.01" min="0" name="amount" placeholder="Valor" class="w-full border rounded px-3 py-2">
        <input required type="date" name="due_date" class="w-full border rounded px-3 py-2">
        <select name="status" class="w-full border rounded px-3 py-2">
            <option value="pending">Pendente</option>
            <option value="paid">Pago</option>
        </select>
        <button class="w-full bg-emerald-600 text-white rounded px-4 py-2">Salvar conta</button>
    </form>

    <form method="post" action="actions/cost_save.php" class="bg-white rounded-lg shadow p-4 space-y-3">
        <h3 class="font-semibold">Novo custo</h3>
        <input required name="description" placeholder="Descricao do custo" class="w-full border rounded px-3 py-2">
        <input name="category" placeholder="Categoria (ex: Operacional, Marketing)" class="w-full border rounded px-3 py-2">
        <input required type="number" step="0.01" min="0" name="amount" placeholder="Valor" class="w-full border rounded px-3 py-2">
        <input required type="date" name="cost_date" class="w-full border rounded px-3 py-2">
        <button class="w-full bg-slate-900 text-white rounded px-4 py-2">Salvar custo</button>
    </form>

    <form method="post" action="actions/bank_txn_save.php" class="bg-white rounded-lg shadow p-4 space-y-3">
        <h3 class="font-semibold">Conciliação bancaria</h3>
        <select required name="bank" class="w-full border rounded px-3 py-2">
            <option value="">Selecione o banco</option>
            <option value="bb">Banco do Brasil</option>
            <option value="santander">Santander</option>
            <option value="itau">Itaú</option>
        </select>
        <select required name="transaction_type" class="w-full border rounded px-3 py-2">
            <option value="in">Entrada</option>
            <option value="out">Saida</option>
        </select>
        <input required name="description" placeholder="Descricao do lancamento" class="w-full border rounded px-3 py-2">
        <input name="reference" placeholder="Referencia (ex: TED, PIX, boleto)" class="w-full border rounded px-3 py-2">
        <input required type="number" step="0.01" min="0" name="amount" placeholder="Valor" class="w-full border rounded px-3 py-2">
        <input required type="date" name="transaction_date" class="w-full border rounded px-3 py-2">
        <button class="w-full bg-indigo-600 text-white rounded px-4 py-2">Salvar lancamento</button>
    </form>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
    <?php foreach ($bankBalances as $bank => $totals): ?>
        <?php $balance = $totals['in'] - $totals['out']; ?>
        <div class="bg-white rounded-lg shadow p-4">
            <p class="text-sm text-slate-500"><?= htmlspecialchars($bankLabels[$bank]) ?></p>
            <p class="text-xs text-emerald-700">Entradas: <?= money($totals['in']) ?></p>
            <p class="text-xs text-rose-700">Saidas: <?= money($totals['out']) ?></p>
            <p class="text-lg font-bold mt-2 <?= $balance >= 0 ? 'text-emerald-700' : 'text-rose-700' ?>">
                Saldo: <?= money($balance) ?>
            </p>
        </div>
    <?php endforeach; ?>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
    <div class="bg-white rounded-lg shadow overflow-auto">
        <h3 class="font-semibold p-4">Contas a pagar</h3>
        <table class="w-full text-sm">
            <thead class="bg-slate-200"><tr><th class="p-3 text-left">Descricao</th><th class="p-3 text-left">Vencimento</th><th class="p-3 text-left">Valor</th><th class="p-3 text-left">Status</th></tr></thead>
            <tbody>
            <?php foreach ($payables as $item): ?>
                <tr class="border-t"><td class="p-3"><?= htmlspecialchars($item['description']) ?></td><td class="p-3"><?= htmlspecialchars($item['due_date']) ?></td><td class="p-3"><?= money((float) $item['amount']) ?></td><td class="p-3"><?= $item['status'] === 'paid' ? 'Pago' : 'Pendente' ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="bg-white rounded-lg shadow overflow-auto">
        <h3 class="font-semibold p-4">Contas a receber</h3>
        <table class="w-full text-sm">
            <thead class="bg-slate-200"><tr><th class="p-3 text-left">Descricao</th><th class="p-3 text-left">Vencimento</th><th class="p-3 text-left">Valor</th><th class="p-3 text-left">Status</th></tr></thead>
            <tbody>
            <?php foreach ($receivables as $item): ?>
                <tr class="border-t"><td class="p-3"><?= htmlspecialchars($item['description']) ?></td><td class="p-3"><?= htmlspecialchars($item['due_date']) ?></td><td class="p-3"><?= money((float) $item['amount']) ?></td><td class="p-3"><?= $item['status'] === 'paid' ? 'Pago' : 'Pendente' ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="bg-white rounded-lg shadow overflow-auto">
        <h3 class="font-semibold p-4">Custos</h3>
        <table class="w-full text-sm">
            <thead class="bg-slate-200"><tr><th class="p-3 text-left">Descricao</th><th class="p-3 text-left">Categoria</th><th class="p-3 text-left">Data</th><th class="p-3 text-left">Valor</th><th class="p-3 text-left">Acoes</th></tr></thead>
            <tbody>
            <?php foreach ($costs as $item): ?>
                <tr class="border-t">
                    <td class="p-3"><?= htmlspecialchars($item['description']) ?></td>
                    <td class="p-3"><?= htmlspecialchars((string) ($item['category'] ?? '-')) ?></td>
                    <td class="p-3"><?= htmlspecialchars($item['cost_date']) ?></td>
                    <td class="p-3"><?= money((float) $item['amount']) ?></td>
                    <td class="p-3">
                        <form method="post" action="actions/cost_delete.php" onsubmit="return confirm('Tem certeza que deseja excluir este custo?');">
                            <input type="hidden" name="cost_id" value="<?= (int) $item['id'] ?>">
                            <button type="submit" class="text-rose-700 underline">Excluir</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="bg-white rounded-lg shadow overflow-auto mt-6">
    <h3 class="font-semibold p-4">Movimentações bancarias</h3>
    <table class="w-full text-sm">
        <thead class="bg-slate-200">
            <tr>
                <th class="p-3 text-left">Data</th>
                <th class="p-3 text-left">Banco</th>
                <th class="p-3 text-left">Tipo</th>
                <th class="p-3 text-left">Descricao</th>
                <th class="p-3 text-left">Referencia</th>
                <th class="p-3 text-left">Valor</th>
                <th class="p-3 text-left">Acoes</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($bankTransactions) === 0): ?>
                <tr class="border-t">
                    <td class="p-3" colspan="7">Nenhuma movimentação bancaria registrada.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($bankTransactions as $txn): ?>
                <?php
                $bankCode = (string) $txn['bank'];
                $isIn = ((string) $txn['transaction_type']) === 'in';
                ?>
                <tr class="border-t">
                    <td class="p-3"><?= htmlspecialchars((string) $txn['transaction_date']) ?></td>
                    <td class="p-3"><?= htmlspecialchars($bankLabels[$bankCode] ?? strtoupper($bankCode)) ?></td>
                    <td class="p-3"><?= $isIn ? 'Entrada' : 'Saida' ?></td>
                    <td class="p-3"><?= htmlspecialchars((string) $txn['description']) ?></td>
                    <td class="p-3"><?= htmlspecialchars((string) ($txn['reference'] ?? '-')) ?></td>
                    <td class="p-3 <?= $isIn ? 'text-emerald-700' : 'text-rose-700' ?>">
                        <?= money((float) $txn['amount']) ?>
                    </td>
                    <td class="p-3">
                        <form method="post" action="actions/bank_txn_delete.php" onsubmit="return confirm('Tem certeza que deseja excluir este lançamento?');">
                            <input type="hidden" name="txn_id" value="<?= (int) $txn['id'] ?>">
                            <button type="submit" class="text-rose-700 underline">Excluir</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

