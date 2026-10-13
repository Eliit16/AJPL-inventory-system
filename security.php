<?php
require_once __DIR__ . '/config.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'save_user') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'staff';
        $password = $_POST['password'] ?? '';

        if ($name === '' || $username === '' || $email === '') {
            set_flash('error', 'Name, username and email are required.');
            redirect('security.php');
        }

        if ($id > 0) {
            if ($password !== '') {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                db()->prepare('UPDATE users SET name = ?, username = ?, email = ?, role = ?, password_hash = ? WHERE id = ?')->execute([$name, $username, $email, $role, $hash, $id]);
            } else {
                db()->prepare('UPDATE users SET name = ?, username = ?, email = ?, role = ? WHERE id = ?')->execute([$name, $username, $email, $role, $id]);
            }
            set_flash('success', 'User updated.');
        } else {
            $check = db()->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
            $check->execute([$username, $email]);
            if ($check->fetch()) {
                set_flash('error', 'Username or email already exists.');
                redirect('security.php');
            }
            $hash = password_hash($password !== '' ? $password : 'admin123', PASSWORD_DEFAULT);
            db()->prepare('INSERT INTO users (name, username, email, password_hash, role) VALUES (?, ?, ?, ?, ?)')->execute([$name, $username, $email, $hash, $role]);
            set_flash('success', 'User created.');
        }
        redirect('security.php');
    }
    if ($action === 'delete_user') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id === $_SESSION['user_id']) {
            set_flash('error', 'You cannot delete your own account.');
            redirect('security.php');
        }
        db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        set_flash('success', 'User deleted.');
        redirect('security.php');
    }
}

$users = db()->query('SELECT * FROM users ORDER BY name ASC')->fetchAll();
$editing = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $_GET['edit']]);
    $editing = $stmt->fetch();
}

$user = inventory_get_current_user();
$flashSuccess = get_flash('success');
$flashError = get_flash('error');

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Security &amp; Data | Inventory</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body>
  <?php if ($flashSuccess): ?><div class="flash" data-flash><?= e($flashSuccess) ?></div><?php endif; ?>
  <?php if ($flashError): ?><div class="flash" data-flash><?= e($flashError) ?></div><?php endif; ?>

  <div class="app-shell">
    <aside class="sidebar">
      <div class="brand-row"><img class="brand-mark" src="assets/images/ajpl-logo.png" alt="AJPL Hardware and Construction Supplies"><div class="brand-copy">AJPL<br>Hardware &amp; Construction Supplies</div></div>
      <nav class="sidebar-nav">
        <a class="nav-link" href="dashboard.php"><span class="icon">▣</span>Dashboard</a>
        <a class="nav-link" href="products.php"><span class="icon">◫</span>Products</a>
        <a class="nav-link" href="categories.php"><span class="icon">▤</span>Categories</a>
        <a class="nav-link" href="reorder_alerts.php"><span class="icon">⚑</span>Reorder Alerts <span class="nav-badge"><?= e(get_sidebar_badge_count()) ?></span></a>
        <a class="nav-link" href="stock_movements.php"><span class="icon">↗</span>Stock Movements</a>
        <a class="nav-link" href="transactions.php"><span class="icon">▣</span>Transactions</a>
        <a class="nav-link" href="suppliers.php"><span class="icon">◌</span>Suppliers</a>
        <a class="nav-link" href="demand_forecast.php"><span class="icon">◔</span>Demand Forecast</a>
        <a class="nav-link" href="reports.php"><span class="icon">▤</span>Reports</a>
        <a class="nav-link active" href="security.php"><span class="icon">◉</span>Security &amp; Data</a>
      </nav>
      <div class="sidebar-footer">Stock levels refresh live as movements are recorded.</div>
    </aside>

    <main class="main-panel">
      <header class="topbar">
        <div class="page-title">Security &amp; Data</div>
        <div class="user-box">
          <div class="user-avatar"><?= strtoupper(substr($user['name'], 0, 1)) ?></div>
          <div class="user-meta"><strong><?= e($user['name']) ?></strong><small><?= e(strtoupper($user['role'])) ?></small></div>
          <a class="btn icon-btn" href="logout.php" title="Logout">⎋</a>
        </div>
      </header>

      <div class="content">
        <div class="page-head">
          <div class="head-left"><h1>User access</h1><p>Roles and access control are enforced on the server before every protected page loads.</p></div>
          <div class="header-actions"><button class="btn btn-primary" data-open-modal="userModal">+ Add user</button></div>
        </div>

        <div class="table-card">
          <table>
            <thead>
              <tr>
                <th>Name</th>
                <th>Username</th>
                <th>Email</th>
                <th>Role</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($users as $account): ?>
                <tr>
                  <td><?= e($account['name']) ?></td>
                  <td><?= e($account['username']) ?></td>
                  <td><?= e($account['email']) ?></td>
                  <td><?= e(strtoupper($account['role'])) ?></td>
                  <td>
                    <div class="inline-row">
                      <a class="link-inline" href="security.php?edit=<?= (int) $account['id'] ?>">Edit</a>
                      <?php if ((int) $account['id'] !== (int) $_SESSION['user_id']): ?>
                        <form method="post" action="security.php" onsubmit="return confirm('Delete this user?');" style="display:inline;">
                          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                          <input type="hidden" name="action" value="delete_user">
                          <input type="hidden" name="id" value="<?= (int) $account['id'] ?>">
                          <button class="action-btn text-danger" type="submit">Delete</button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </main>
  </div>

  <div class="modal <?= $editing ? 'open' : '' ?>" id="userModal">
    <div class="modal-card">
      <div class="modal-head"><h3><?= $editing ? 'Edit user' : 'Add user' ?></h3><button class="modal-close" type="button">✕</button></div>
      <div class="modal-body">
        <form method="post" action="security.php">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="save_user">
          <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
          <div class="form-grid">
            <div class="field-wrap">
              <label>Name</label>
              <input class="form-control" name="name" value="<?= e($editing['name'] ?? '') ?>" required>
            </div>
            <div class="field-wrap">
              <label>Username</label>
              <input class="form-control" name="username" value="<?= e($editing['username'] ?? '') ?>" required>
            </div>
            <div class="field-wrap">
              <label>Email</label>
              <input class="form-control" name="email" type="email" value="<?= e($editing['email'] ?? '') ?>" required>
            </div>
            <div class="field-wrap">
              <label>Role</label>
              <select class="form-control" name="role">
                <option value="admin" <?= (($editing['role'] ?? 'staff') === 'admin') ? 'selected' : '' ?>>Administrator</option>
                <option value="staff" <?= (($editing['role'] ?? 'staff') === 'staff') ? 'selected' : '' ?>>Staff</option>
              </select>
            </div>
            <div class="field-wrap full">
              <label>Password <?= $editing ? '(leave blank to keep current)' : '' ?></label>
              <input class="form-control" type="password" name="password" <?= $editing ? '' : 'required' ?>>
            </div>
          </div>
          <div class="form-actions"><button type="button" class="btn modal-close">Cancel</button><button type="submit" class="btn btn-primary"><?= $editing ? 'Save' : 'Add user' ?></button></div>
        </form>
      </div>
    </div>
  </div>

  <script src="assets/js/app.js"></script>
</body>
</html>
