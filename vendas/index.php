<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$flash = getFlash();
$pdvFormAction = '../actions/sale_finalize.php';
$pdvReturnPage = 'pdv_mobile_link';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PDV Mobile - Equipe de Vendas</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 text-slate-800">
    <main class="min-h-screen p-4 md:p-6">
        <?php if ($flash): ?>
            <div class="max-w-4xl mx-auto mb-4 p-3 rounded text-sm <?= $flash['type'] === 'success' ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800' ?>">
                <?= htmlspecialchars($flash['message']) ?>
            </div>
        <?php endif; ?>

        <?php require __DIR__ . '/../pages/pdv_mobile.php'; ?>
    </main>
</body>
</html>
