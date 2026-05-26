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

$payables = $pdo->query('SELECT * FROM accounts_payable ORDER BY due_date ASC')->fetchAll();
$receivables = $pdo->query('SELECT * FROM accounts_receivable ORDER BY due_date ASC')->fetchAll();
$costs = $pdo->query('SELECT * FROM costs ORDER BY cost_date DESC, id DESC')->fetchAll();
?>

<h2 class="text-2xl font-bold mb-4">Financeiro</h2>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
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

