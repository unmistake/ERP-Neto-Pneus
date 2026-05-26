<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$costId = (int) ($_POST['cost_id'] ?? 0);
if ($costId <= 0) {
    flash('error', 'Custo invalido para exclusao.');
    redirect('../index.php?page=financeiro');
}

$stmt = $pdo->prepare('DELETE FROM costs WHERE id = ?');
$stmt->execute([$costId]);

if ($stmt->rowCount() === 0) {
    flash('error', 'Custo nao encontrado.');
    redirect('../index.php?page=financeiro');
}

flash('success', 'Custo excluido com sucesso.');
redirect('../index.php?page=financeiro');
