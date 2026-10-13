<?php

function db(): PDO
{
    global $pdo;
    return $pdo;
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function format_currency($value): string
{
    return '₱' . number_format((float) $value, 2, '.', ',');
}

function format_currency_plain($value): string
{
    return number_format((float) $value, 2, '.', ',');
}

function format_date($value): string
{
    if (empty($value)) {
        return '-';
    }
    $date = new DateTime($value);
    return $date->format('M j, Y');
}

function format_short_date($value): string
{
    if (empty($value)) {
        return '-';
    }
    $date = new DateTime($value);
    return $date->format('M d, Y');
}

function redirect($path): void
{
    header('Location: ' . $path);
    exit;
}

function set_flash($key, $value): void
{
    $_SESSION['flash'][$key] = $value;
}

function get_flash($key): ?string
{
    if (!isset($_SESSION['flash'][$key])) {
        return null;
    }
    $value = $_SESSION['flash'][$key];
    unset($_SESSION['flash'][$key]);
    return $value;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        die('CSRF validation failed.');
    }
}

function get_sidebar_badge_count(): int
{
    $stmt = db()->query('SELECT COUNT(*) AS c FROM products WHERE on_hand <= reorder_level');
    $row = $stmt->fetch();
    return (int) ($row['c'] ?? 0);
}

function get_product_status($product): string
{
    $onHand = (int) ($product['on_hand'] ?? 0);
    $reorderLevel = (int) ($product['reorder_level'] ?? 0);
    if ($onHand <= 0) {
        return 'Out of stock';
    }
    if ($onHand <= $reorderLevel) {
        return 'Low stock';
    }
    return 'In stock';
}

function get_product_status_class($product): string
{
    $status = get_product_status($product);
    return match ($status) {
        'Out of stock' => 'status-out',
        'Low stock' => 'status-low',
        default => 'status-in',
    };
}

function get_category_name($categoryId): string
{
    if (!$categoryId) {
        return 'Uncategorized';
    }
    $stmt = db()->prepare('SELECT name FROM categories WHERE id = ?');
    $stmt->execute([$categoryId]);
    $row = $stmt->fetch();
    return $row['name'] ?? 'Unknown';
}

function get_supplier_name($supplierId): string
{
    if (!$supplierId) {
        return '—';
    }
    $stmt = db()->prepare('SELECT name FROM suppliers WHERE id = ?');
    $stmt->execute([$supplierId]);
    $row = $stmt->fetch();
    return $row['name'] ?? '—';
}

function product_total_value($product): float
{
    return ((float) $product['cost_price']) * ((float) $product['on_hand']);
}

function get_movement_summary($fromDate = null, $toDate = null): array
{
    $where = [];
    $params = [];
    if ($fromDate) {
        $where[] = 'created_at >= ?';
        $params[] = $fromDate;
    }
    if ($toDate) {
        $where[] = 'created_at <= ?';
        $params[] = $toDate . ' 23:59:59';
    }
    $sql = 'SELECT movement_type, SUM(quantity) AS total_qty FROM stock_movements';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' GROUP BY movement_type';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $data = ['in' => 0, 'out' => 0];
    while ($row = $stmt->fetch()) {
        if ($row['movement_type'] === 'in') {
            $data['in'] = (float) $row['total_qty'];
        }
        if ($row['movement_type'] === 'out') {
            $data['out'] = (float) $row['total_qty'];
        }
    }
    return $data;
}

function run_sql_file($path): void
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        return;
    }
    try {
        db()->exec($sql);
    } catch (PDOException $e) {
        // Ignore if already imported.
    }
}

function get_current_page(): string
{
    $page = basename($_SERVER['PHP_SELF']);
    return strtolower($page);
}

function active_nav($page): string
{
    return get_current_page() === strtolower($page) ? 'active' : '';
}

function get_stock_by_category(): array
{
    $stmt = db()->query('SELECT c.name, SUM(p.on_hand * p.cost_price) AS value, COUNT(p.id) AS items FROM categories c LEFT JOIN products p ON p.category_id = c.id AND p.archived = 0 GROUP BY c.id, c.name ORDER BY value DESC');
    return $stmt->fetchAll();
}

function get_product_categories(): array
{
    $stmt = db()->query('SELECT * FROM categories ORDER BY name ASC');
    return $stmt->fetchAll();
}

function get_suppliers(): array
{
    $stmt = db()->query('SELECT * FROM suppliers ORDER BY name ASC');
    return $stmt->fetchAll();
}

function is_admin(): bool
{
    $user = inventory_get_current_user();
    return $user && strtolower((string) $user['role']) === 'admin';
}
