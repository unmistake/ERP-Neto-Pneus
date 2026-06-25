<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
$flash = getFlash();
$currentPage = $page ?? 'dashboard';
$currentUser = authCurrentUser();
$navigation = [
    ['page' => 'dashboard', 'label' => 'Dashboard', 'href' => 'index.php'],
    ['page' => 'estoque', 'label' => 'Estoque', 'href' => 'index.php?page=estoque'],
    ['page' => 'pdv', 'label' => 'PDV', 'href' => 'index.php?page=pdv'],
    ['page' => 'pdv_mobile', 'label' => 'PDV Mobile', 'href' => 'index.php?page=pdv_mobile'],
    ['page' => 'crm', 'label' => 'CRM', 'href' => 'index.php?page=crm'],
    ['page' => 'nfe_entrada', 'label' => 'NF-e Entrada', 'href' => 'index.php?page=nfe_entrada'],
    ['page' => 'financeiro', 'label' => 'Financeiro', 'href' => 'index.php?page=financeiro'],
];

function navigationLinkClass(string $itemPage, string $currentPage): string
{
    $base = 'flex items-center gap-3 rounded-xl px-3 py-3 text-sm font-semibold transition';
    if ($itemPage === $currentPage) {
        return $base . ' bg-emerald-500 text-slate-950 shadow-lg shadow-emerald-950/20';
    }

    return $base . ' text-slate-300 hover:bg-slate-800 hover:text-white';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'ERP Pneus') ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        [data-mobile-menu] {
            transition: transform 180ms ease;
        }

        body.menu-open {
            overflow: hidden;
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-800">
<div class="min-h-screen md:flex">
    <aside class="hidden w-64 shrink-0 bg-slate-950 text-white md:sticky md:top-0 md:flex md:h-screen md:flex-col md:p-5">
        <div class="mb-8">
            <p class="text-xs font-bold uppercase tracking-[0.24em] text-emerald-400">Neto Rodas</p>
            <h1 class="mt-2 text-2xl font-black">ERP Pneus</h1>
        </div>
        <nav class="flex-1 space-y-1">
            <?php foreach ($navigation as $item): ?>
                <a class="<?= navigationLinkClass($item['page'], $currentPage) ?>" href="<?= htmlspecialchars($item['href']) ?>">
                    <span class="h-2 w-2 rounded-full <?= $item['page'] === $currentPage ? 'bg-slate-950' : 'bg-slate-600' ?>"></span>
                    <?= htmlspecialchars($item['label']) ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php if ($currentUser): ?>
            <div class="mt-8 rounded-2xl bg-slate-900 p-3 text-xs text-slate-300">
                <p class="font-bold text-white"><?= htmlspecialchars($currentUser['username']) ?></p>
                <form method="post" action="actions/logout.php" class="mt-2">
                    <button class="text-rose-300 underline">Sair</button>
                </form>
            </div>
        <?php endif; ?>
        <p class="mt-4 text-xs leading-relaxed text-slate-500">Gestao de estoque, vendas, clientes e financeiro.</p>
    </aside>

    <div class="min-w-0 flex-1">
        <header class="sticky top-0 z-40 flex items-center justify-between border-b border-slate-200 bg-white/95 px-4 py-3 shadow-sm backdrop-blur md:hidden">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.2em] text-emerald-600">Neto Rodas</p>
                <p class="text-lg font-black"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $currentPage))) ?></p>
            </div>
            <button
                type="button"
                data-menu-open
                aria-label="Abrir menu"
                aria-controls="mobile-navigation"
                aria-expanded="false"
                class="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-slate-950 text-white shadow-lg"
            >
                <svg aria-hidden="true" viewBox="0 0 24 24" class="h-6 w-6 fill-none stroke-current" stroke-width="2" stroke-linecap="round">
                    <path d="M4 7h16M4 12h16M4 17h16"></path>
                </svg>
            </button>
        </header>

        <div data-menu-overlay class="fixed inset-0 z-40 hidden bg-slate-950/60 backdrop-blur-sm md:hidden"></div>
        <aside
            id="mobile-navigation"
            data-mobile-menu
            class="fixed inset-y-0 right-0 z-50 flex w-[min(86vw,22rem)] translate-x-full flex-col bg-slate-950 p-5 text-white shadow-2xl md:hidden"
            aria-hidden="true"
        >
            <div class="mb-7 flex items-start justify-between">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.24em] text-emerald-400">Neto Rodas</p>
                    <h2 class="mt-2 text-2xl font-black">Menu do ERP</h2>
                </div>
                <button type="button" data-menu-close aria-label="Fechar menu" class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-slate-800 text-white">
                    <svg aria-hidden="true" viewBox="0 0 24 24" class="h-6 w-6 fill-none stroke-current" stroke-width="2" stroke-linecap="round">
                        <path d="M6 6l12 12M18 6L6 18"></path>
                    </svg>
                </button>
            </div>
            <nav class="flex-1 space-y-1 overflow-y-auto">
                <?php foreach ($navigation as $item): ?>
                    <a class="<?= navigationLinkClass($item['page'], $currentPage) ?>" href="<?= htmlspecialchars($item['href']) ?>">
                        <span class="h-2 w-2 rounded-full <?= $item['page'] === $currentPage ? 'bg-slate-950' : 'bg-slate-600' ?>"></span>
                        <?= htmlspecialchars($item['label']) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <?php if ($currentUser): ?>
                <form method="post" action="actions/logout.php" class="mt-4">
                    <button class="w-full rounded-xl bg-slate-800 px-3 py-3 text-left text-sm font-bold text-rose-200">Sair de <?= htmlspecialchars($currentUser['username']) ?></button>
                </form>
            <?php endif; ?>
        </aside>

        <main class="min-w-0 p-4 sm:p-5 md:p-8">
            <?php if ($flash): ?>
                <div class="mb-4 rounded-xl p-3 text-sm font-semibold <?= $flash['type'] === 'success' ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800' ?>">
                    <?= htmlspecialchars($flash['message']) ?>
                </div>
            <?php endif; ?>

            <?= $content ?? '' ?>
        </main>
    </div>
</div>

<script>
(() => {
    const body = document.body;
    const menu = document.querySelector('[data-mobile-menu]');
    const overlay = document.querySelector('[data-menu-overlay]');
    const openButton = document.querySelector('[data-menu-open]');
    const closeButton = document.querySelector('[data-menu-close]');

    if (!menu || !overlay || !openButton || !closeButton) {
        return;
    }

    function setMenu(open) {
        menu.classList.toggle('translate-x-full', !open);
        overlay.classList.toggle('hidden', !open);
        body.classList.toggle('menu-open', open);
        menu.setAttribute('aria-hidden', open ? 'false' : 'true');
        openButton.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    openButton.addEventListener('click', () => setMenu(true));
    closeButton.addEventListener('click', () => setMenu(false));
    overlay.addEventListener('click', () => setMenu(false));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            setMenu(false);
        }
    });
})();
</script>
</body>
</html>
