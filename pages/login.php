<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

authEnsureSchema($pdo);

$returnTo = trim((string) ($_GET['return_to'] ?? $_POST['return_to'] ?? 'index.php'));
if ($returnTo === '' || preg_match('/^https?:\/\//i', $returnTo)) {
    $returnTo = 'index.php';
}

if (authIsLoggedIn()) {
    redirect($returnTo);
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = (string) ($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if (authLogin($pdo, $username, $password)) {
        redirect($returnTo);
    }

    $error = 'Usuario ou senha invalidos.';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - ERP Neto Rodas</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
    <main class="flex min-h-screen items-center justify-center p-5">
        <section class="w-full max-w-md overflow-hidden rounded-3xl bg-white text-slate-950 shadow-2xl">
            <div class="bg-emerald-500 p-6">
                <p class="text-xs font-black uppercase tracking-[0.24em] text-emerald-950">Neto Rodas</p>
                <h1 class="mt-2 text-3xl font-black">Acesso ao ERP</h1>
                <p class="mt-1 text-sm font-semibold text-emerald-950/80">Entre para acessar o dashboard administrativo.</p>
            </div>

            <form method="post" class="space-y-4 p-6">
                <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo) ?>">

                <?php if ($error !== ''): ?>
                    <div class="rounded-2xl bg-rose-100 p-3 text-sm font-bold text-rose-800"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <label class="block">
                    <span class="text-sm font-bold text-slate-700">Usuario</span>
                    <input name="username" required autofocus autocomplete="username" class="mt-1 w-full rounded-2xl border border-slate-300 px-4 py-3 outline-none ring-emerald-500 focus:ring-2">
                </label>

                <label class="block">
                    <span class="text-sm font-bold text-slate-700">Senha</span>
                    <input type="password" name="password" required autocomplete="current-password" class="mt-1 w-full rounded-2xl border border-slate-300 px-4 py-3 outline-none ring-emerald-500 focus:ring-2">
                </label>

                <button class="w-full rounded-2xl bg-slate-950 px-4 py-3 font-black text-white hover:bg-slate-800">Entrar</button>
            </form>
        </section>
    </main>
</body>
</html>
