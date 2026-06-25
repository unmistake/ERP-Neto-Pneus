<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

function authEnsureSchema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS system_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(80) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('admin') NOT NULL DEFAULT 'admin',
            active TINYINT(1) NOT NULL DEFAULT 1,
            last_login_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_system_users_username (username)
        )"
    );

    $defaultUsername = trim((string) (getenv('ERP_ADMIN_DEFAULT_USER') ?: 'admin'));
    $defaultPassword = (string) (getenv('ERP_ADMIN_DEFAULT_PASSWORD') ?: 'neto001');
    if ($defaultUsername === '' || $defaultPassword === '') {
        return;
    }

    $stmt = $pdo->prepare('SELECT id FROM system_users WHERE username = ? LIMIT 1');
    $stmt->execute([$defaultUsername]);
    if ($stmt->fetchColumn()) {
        return;
    }

    $insert = $pdo->prepare('INSERT INTO system_users (username, password_hash, role, active) VALUES (?, ?, ?, 1)');
    $insert->execute([$defaultUsername, password_hash($defaultPassword, PASSWORD_DEFAULT), 'admin']);
}

function authCurrentUser(): ?array
{
    if (empty($_SESSION['system_user_id'])) {
        return null;
    }

    return [
        'id' => (int) $_SESSION['system_user_id'],
        'username' => (string) ($_SESSION['system_username'] ?? ''),
        'role' => (string) ($_SESSION['system_user_role'] ?? 'admin'),
    ];
}

function authIsLoggedIn(): bool
{
    return authCurrentUser() !== null;
}

function authLogin(PDO $pdo, string $username, string $password): bool
{
    authEnsureSchema($pdo);

    $stmt = $pdo->prepare('SELECT id, username, password_hash, role, active FROM system_users WHERE username = ? LIMIT 1');
    $stmt->execute([trim($username)]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !(int) $user['active'] || !password_verify($password, (string) $user['password_hash'])) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['system_user_id'] = (int) $user['id'];
    $_SESSION['system_username'] = (string) $user['username'];
    $_SESSION['system_user_role'] = (string) $user['role'];

    $upd = $pdo->prepare('UPDATE system_users SET last_login_at = NOW() WHERE id = ?');
    $upd->execute([(int) $user['id']]);

    return true;
}

function authLogout(): void
{
    unset($_SESSION['system_user_id'], $_SESSION['system_username'], $_SESSION['system_user_role']);
    session_regenerate_id(true);
}

function authRequireLogin(?string $returnTo = null): void
{
    if (authIsLoggedIn()) {
        return;
    }

    $target = $returnTo ?: ($_SERVER['REQUEST_URI'] ?? 'index.php');
    redirect('index.php?page=login&return_to=' . rawurlencode($target));
}

function authRequireActionLogin(): void
{
    if (authIsLoggedIn()) {
        return;
    }

    flash('error', 'Faça login para executar esta ação.');
    redirect('../index.php?page=login');
}
