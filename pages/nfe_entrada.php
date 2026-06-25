<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/inbound_nfe_focus.php';

inboundNfeEnsureSchema($pdo);

$search = trim((string) ($_GET['q'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(supplier_name LIKE ? OR supplier_cnpj LIKE ? OR access_key LIKE ? OR number LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, '%' . inboundNfeOnlyDigits($search) . '%', $like, $like);
}
if ($status !== '') {
    $where[] = 'status = ?';
    $params[] = $status;
}
if ($dateFrom !== '') {
    $where[] = 'DATE(issue_date) >= ?';
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = 'DATE(issue_date) <= ?';
    $params[] = $dateTo;
}

$sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$stmt = $pdo->prepare(
    "SELECT n.*, COUNT(i.id) AS item_count
     FROM inbound_nfes n
     LEFT JOIN inbound_nfe_items i ON i.inbound_nfe_id = n.id
     $sqlWhere
     GROUP BY n.id
     ORDER BY COALESCE(n.issue_date, n.created_at) DESC, n.id DESC
     LIMIT 200"
);
$stmt->execute($params);
$notes = $stmt->fetchAll();

$statuses = $pdo->query("SELECT DISTINCT status FROM inbound_nfes WHERE status IS NOT NULL AND status <> '' ORDER BY status")->fetchAll(PDO::FETCH_COLUMN);
$state = $pdo->query('SELECT * FROM inbound_nfe_sync_state ORDER BY last_synced_at DESC LIMIT 1')->fetch();
$returnQueryParams = $_GET;
unset($returnQueryParams['page']);
$returnQuery = http_build_query($returnQueryParams);

function inboundNfeFormatCnpj(?string $cnpj): string
{
    $digits = inboundNfeOnlyDigits((string) $cnpj);
    if (strlen($digits) !== 14) {
        return $digits;
    }

    return substr($digits, 0, 2) . '.' . substr($digits, 2, 3) . '.' . substr($digits, 5, 3) . '/' . substr($digits, 8, 4) . '-' . substr($digits, 12, 2);
}

function inboundNfeStatusClass(?string $status): string
{
    $status = strtolower((string) $status);
    if (str_contains($status, 'autoriz') || str_contains($status, 'resum')) {
        return 'bg-emerald-100 text-emerald-800';
    }
    if (str_contains($status, 'cancel')) {
        return 'bg-rose-100 text-rose-800';
    }
    return 'bg-slate-100 text-slate-700';
}
?>

<div class="mb-6 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
    <div>
        <p class="text-sm font-bold uppercase tracking-[0.22em] text-emerald-600">Fiscal de entrada</p>
        <h2 class="text-3xl font-black text-slate-950">NF-e recebidas</h2>
        <p class="mt-1 max-w-3xl text-sm text-slate-600">
            Notas fiscais emitidas por fornecedores contra o CNPJ da empresa, sincronizadas pela Focus para conferencia de compra e DANFE.
        </p>
    </div>

    <form method="post" action="actions/inbound_nfe_sync.php" class="flex flex-col gap-2 rounded-2xl bg-white p-3 shadow sm:flex-row">
        <input type="hidden" name="return_query" value="<?= htmlspecialchars($returnQuery) ?>">
        <button name="mode" value="incremental" class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-bold text-white shadow hover:bg-emerald-700">
            Sincronizar novas
        </button>
        <button name="mode" value="full" class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-bold text-white shadow hover:bg-slate-800" onclick="return confirm('Buscar novamente as primeiras notas da Focus?');">
            Sincronizacao completa
        </button>
    </form>
</div>

<div class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-4">
    <div class="rounded-2xl bg-white p-4 shadow">
        <p class="text-xs font-bold uppercase text-slate-500">Notas na base</p>
        <p class="mt-1 text-2xl font-black text-slate-950"><?= count($notes) ?></p>
        <p class="text-xs text-slate-500">Exibindo ate 200 registros</p>
    </div>
    <div class="rounded-2xl bg-white p-4 shadow">
        <p class="text-xs font-bold uppercase text-slate-500">Ultima versao Focus</p>
        <p class="mt-1 text-2xl font-black text-slate-950"><?= htmlspecialchars((string) ($state['last_version'] ?? '0')) ?></p>
    </div>
    <div class="rounded-2xl bg-white p-4 shadow lg:col-span-2">
        <p class="text-xs font-bold uppercase text-slate-500">Ultima sincronizacao</p>
        <p class="mt-1 text-lg font-black text-slate-950"><?= htmlspecialchars((string) ($state['last_synced_at'] ?? 'Ainda nao sincronizado')) ?></p>
        <p class="text-xs text-slate-500">A Focus retorna lotes de ate 100 notas; execute novamente se houver mais registros pendentes.</p>
    </div>
</div>

<form method="get" class="mb-6 grid grid-cols-1 gap-3 rounded-2xl bg-white p-4 shadow lg:grid-cols-5">
    <input type="hidden" name="page" value="nfe_entrada">
    <input name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Fornecedor, CNPJ, numero ou chave" class="rounded-xl border border-slate-300 px-3 py-2 lg:col-span-2">
    <select name="status" class="rounded-xl border border-slate-300 px-3 py-2">
        <option value="">Todos os status</option>
        <?php foreach ($statuses as $statusOption): ?>
            <option value="<?= htmlspecialchars((string) $statusOption) ?>" <?= $status === (string) $statusOption ? 'selected' : '' ?>><?= htmlspecialchars((string) $statusOption) ?></option>
        <?php endforeach; ?>
    </select>
    <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" class="rounded-xl border border-slate-300 px-3 py-2">
    <div class="flex gap-2">
        <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" class="min-w-0 flex-1 rounded-xl border border-slate-300 px-3 py-2">
        <button class="rounded-xl bg-slate-900 px-4 py-2 font-bold text-white">Filtrar</button>
    </div>
</form>

<div class="overflow-hidden rounded-2xl bg-white shadow">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3">Emissao</th>
                    <th class="px-4 py-3">Fornecedor</th>
                    <th class="px-4 py-3">NF-e</th>
                    <th class="px-4 py-3 text-right">Valor</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3 text-right">Acoes</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (!$notes): ?>
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-slate-500">Nenhuma NF-e recebida encontrada. Clique em sincronizar para buscar na Focus.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($notes as $note): ?>
                    <tr class="align-top hover:bg-slate-50">
                        <td class="whitespace-nowrap px-4 py-3 text-slate-700">
                            <?= htmlspecialchars($note['issue_date'] ? date('d/m/Y H:i', strtotime((string) $note['issue_date'])) : '-') ?>
                        </td>
                        <td class="px-4 py-3">
                            <p class="font-bold text-slate-950"><?= htmlspecialchars((string) ($note['supplier_name'] ?: 'Fornecedor nao informado')) ?></p>
                            <p class="text-xs text-slate-500"><?= htmlspecialchars(inboundNfeFormatCnpj($note['supplier_cnpj'] ?? '')) ?></p>
                        </td>
                        <td class="px-4 py-3">
                            <p class="font-semibold text-slate-900">Nº <?= htmlspecialchars((string) ($note['number'] ?: '-')) ?> / Serie <?= htmlspecialchars((string) ($note['series'] ?: '-')) ?></p>
                            <p class="mt-1 max-w-[28rem] break-all font-mono text-xs text-slate-500"><?= htmlspecialchars((string) $note['access_key']) ?></p>
                            <p class="mt-1 text-xs text-slate-500"><?= (int) $note['item_count'] ?> item(ns) importado(s)</p>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right font-bold text-slate-950">
                            <?= $note['total_amount'] !== null ? money((float) $note['total_amount']) : '-' ?>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-bold <?= inboundNfeStatusClass($note['status'] ?? '') ?>">
                                <?= htmlspecialchars((string) ($note['status'] ?: 'sem status')) ?>
                            </span>
                            <?php if (!empty($note['manifest_status'])): ?>
                                <p class="mt-1 text-xs text-slate-500">Manifesto: <?= htmlspecialchars((string) $note['manifest_status']) ?></p>
                            <?php endif; ?>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            <a href="actions/inbound_nfe_download_pdf.php?id=<?= (int) $note['id'] ?>" class="rounded-xl bg-slate-900 px-3 py-2 text-xs font-bold text-white hover:bg-slate-800">
                                Baixar PDF
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
