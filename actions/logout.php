<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

authLogout();
flash('success', 'Sessao encerrada com sucesso.');
redirect('../index.php?page=login');
