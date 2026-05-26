<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'ERP Pneus') ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 text-slate-800">
<div class="min-h-screen flex">
    <aside class="w-64 bg-slate-900 text-white p-5 hidden md:block">
        <h1 class="text-xl font-bold mb-6">ERP Pneus</h1>
        <nav class="space-y-2 text-sm">
            <a class="block px-3 py-2 rounded hover:bg-slate-700" href="index.php">Dashboard</a>
            <a class="block px-3 py-2 rounded hover:bg-slate-700" href="index.php?page=estoque">Estoque</a>
            <a class="block px-3 py-2 rounded hover:bg-slate-700" href="index.php?page=pdv">PDV</a>
            <a class="block px-3 py-2 rounded hover:bg-slate-700" href="index.php?page=pdv_mobile">PDV Mobile</a>
            <a class="block px-3 py-2 rounded hover:bg-slate-700" href="index.php?page=crm">CRM</a>
            <a class="block px-3 py-2 rounded hover:bg-slate-700" href="index.php?page=financeiro">Financeiro</a>
        </nav>
    </aside>

    <main class="flex-1 p-4 md:p-8">
        <?php if ($flash): ?>
            <div class="mb-4 p-3 rounded text-sm <?= $flash['type'] === 'success' ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800' ?>">
                <?= htmlspecialchars($flash['message']) ?>
            </div>
        <?php endif; ?>

        <?= $content ?? '' ?>
    </main>
</div>
</body>
</html>
