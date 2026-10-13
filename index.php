<?php
require_once __DIR__ . '/config.php';
if (isset($_SESSION['user_id'])) {
    redirect('dashboard.php');
}
redirect('login.php');
