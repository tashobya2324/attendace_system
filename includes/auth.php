<?php
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        header('Location: /index.php');
        exit;
    }
    return $user;
}

function require_role(array $roles): array
{
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        die('You do not have permission to view this page.');
    }
    return $user;
}

function attempt_login(string $username, string $password): ?array
{
    $stmt = db()->prepare('SELECT id, full_name, username, password_hash, role FROM users WHERE username = ? AND is_active = 1');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($result && password_verify($password, $result['password_hash'])) {
        unset($result['password_hash']);
        $_SESSION['user'] = $result;
        return $result;
    }
    return null;
}

function logout(): void
{
    $_SESSION = [];
    session_destroy();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verify_csrf(): void
{
    $token = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(419);
        die(json_encode(['error' => 'Invalid or expired session token. Please refresh the page.']));
    }
}
