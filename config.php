<?php
session_start();
date_default_timezone_set('Asia/Manila');

if (!defined('APP_NAME')) {
    define('APP_NAME', 'Inventory Hardware & Supplies');
}
if (!defined('APP_ROOT')) {
    define('APP_ROOT', __DIR__);
}

$dbHost = '127.0.0.1';
$dbName = 'inventory_db';
$dbUser = 'root';
$dbPass = '';

try {
    $pdo = new PDO(
        'mysql:host=' . $dbHost . ';dbname=' . $dbName . ';charset=utf8mb4',
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    // Render a descriptive message if the DB is not yet created.
    http_response_code(503);
    die('Database connection failed. Please create the database and import the SQL schema first.');
}

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
