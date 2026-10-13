<?php
require_once __DIR__ . '/config.php';

if (isset($_SESSION['user_id'])) {
    redirect('dashboard.php');
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $errors[] = 'Please enter both username and password.';
    } else {
        $stmt = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['role'] = $user['role'];
            redirect('dashboard.php');
        }
        $errors[] = 'Invalid username or password.';
    }
}

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Login | Inventory</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root {
      --bg: #fcfaf8; --fg: #171c26; --card: #ffffff; --card-fg: #171c26; --popover: #ffffff; --popover-fg: #171c26;
      --primary: #83b94d; --primary-fg: #102b22; --secondary: #e7f0df; --secondary-fg: #204638; --muted: #eef5e9; --muted-fg: #62756b; --accent: #d9e9c9; --accent-fg: #1b4434; --destructive: #b8443e; --destructive-fg: #ffffff; --border: #d8e4d2; --input: #d8e4d2; --ring: #83b94d; --sidebar-bg: #0b2b21; --sidebar-fg: #f1f7ed; --sidebar-primary: #9aca5d; --sidebar-primary-fg: #102b22; --sidebar-accent: #174536; --sidebar-accent-fg: #f1f7ed; --sidebar-border: #1d503e; --sidebar-ring: #83b94d;
      --chart-1: #83b94d; --chart-2: #2f604b; --chart-3: #a7c978; --chart-4: #4c8061; --chart-5: #c2d99a;
      --radius: 14px;
      --shadow: 0 10px 28px rgba(15, 23, 42, 0.08);
    }
    * { box-sizing: border-box; }
    body {
      margin:0; font-family:'Inter', sans-serif; background: linear-gradient(135deg, #eef5e9 0%, #f7faf5 100%); color: var(--fg);
    }
    .login-wrap { min-height: 100vh; display:flex; align-items:center; justify-content:center; padding:24px; }
    .login-box {
      width: 100%; max-width: 420px; background: rgba(255,255,255,0.64); backdrop-filter: blur(9px);
      border: 1px solid rgba(216,228,210,0.8); border-radius: 18px; box-shadow: var(--shadow); padding: 32px 28px;
    }
    .brand { display:flex; align-items:center; gap:12px; justify-content:center; margin-bottom: 8px; }
    .brand-mark {
      width: 52px; height: 52px; border-radius: 50%; background: #0b2b21; color:white; display:block; object-fit:cover; border: 2px solid #83b94d;
    }
    .brand-text { font-family:'Archivo', sans-serif; font-weight:800; letter-spacing:0.04em; text-transform: uppercase; font-size: 1.1rem; }
    .login-title { font-family:'Archivo', sans-serif; text-align:center; font-size: 2rem; margin:16px 0 8px; }
    .login-subtitle { text-align:center; color: var(--muted-fg); margin-bottom: 18px; }
    .field { margin-bottom: 16px; }
    label { display:block; font-weight:600; margin-bottom:8px; }
    input {
      width:100%; border:1px solid var(--input); background:#fff; height:46px; border-radius:10px; padding:0 12px; font-size:1rem;
    }
    input:focus { outline:none; border-color: var(--ring); box-shadow:0 0 0 3px rgba(131,185,77,0.18); }
    .btn {
      width:100%; border:none; background: linear-gradient(135deg, #9aca5d, #5d9442); color: var(--primary-fg); border-radius:10px; height:46px; cursor:pointer; font-weight:700; font-size: 1rem; box-shadow: 0 10px 20px rgba(47,96,75,0.2);
    }
    .alert {
      background: #fff2f0; border: 1px solid #f3d2ce; color: #a13728; padding: 10px 12px; border-radius: 10px; margin-bottom: 18px; font-size: 0.92rem;
    }
    .muted-link { text-align:center; margin-top: 18px; color: var(--muted-fg); font-size: 0.9rem; }
  </style>
</head>
<body>
  <div class="login-wrap">
    <div class="login-box">
      <div class="brand">
        <img class="brand-mark" src="assets/images/ajpl-logo.png" alt="AJPL Hardware and Construction Supplies">
        <div class="brand-text">AJPL Hardware</div>
      </div>
      <h1 class="login-title">Sign in</h1>
      <div class="login-subtitle">Hardware &amp; Construction Supplies</div>

      <?php foreach ($errors as $error): ?>
        <div class="alert"><?= e($error) ?></div>
      <?php endforeach; ?>

      <form method="post" action="login.php">
        <div class="field">
          <label for="username">Username</label>
          <input id="username" name="username" type="text" value="admin" autocomplete="username" required>
        </div>
        <div class="field">
          <label for="password">Password</label>
          <input id="password" name="password" type="password" value="admin123" autocomplete="current-password" required>
        </div>
        <button type="submit" class="btn">Login</button>
      </form>
      <div class="muted-link">Default admin login: admin / admin123</div>
    </div>
  </div>
</body>
</html>
