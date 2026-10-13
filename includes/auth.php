<?php

if (!function_exists('inventory_get_current_user')) {
    function inventory_get_current_user(): ?array
    {
        if (!isset($_SESSION['user_id'])) {
            return null;
        }
        $stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch() ?: null;
    }
}

if (!function_exists('require_login')) {
    function require_login(): void
    {
        if (!isset($_SESSION['user_id'])) {
            header('Location: login.php');
            exit;
        }
    }
}

if (!function_exists('require_admin')) {
    function require_admin(): void
    {
        require_login();
        $user = inventory_get_current_user();
        if (!$user || strtolower((string) $user['role']) !== 'admin') {
            http_response_code(403);
            die('Access denied. Administrator privileges required.');
        }
    }
}

if (!function_exists('require_any_role')) {
    function require_any_role(array $roles): void
    {
        require_login();
        $user = inventory_get_current_user();
        if (!$user || !in_array(strtolower((string) $user['role']), array_map('strtolower', $roles), true)) {
            http_response_code(403);
            die('Access denied.');
        }
    }
}

if (!function_exists('require_active_session')) {
    function require_active_session(): void
    {
        if (!isset($_SESSION['user_id'])) {
            redirect('login.php');
        }
    }
}
