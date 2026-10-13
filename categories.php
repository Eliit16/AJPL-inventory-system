<?php
require_once __DIR__ . '/config.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_category') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        if ($name === '') {
            set_flash('error', 'Category name is required.');
            redirect('categories.php');
        }
        if ($id > 0) {
            db()->prepare('UPDATE categories SET name = ?, description = ? WHERE id = ?')->execute([$name, $description, $id]);
            set_flash('success', 'Category updated.');
        } else {
            db()->prepare('INSERT INTO categories (name, description) VALUES (?, ?)')->execute([$name, $description]);
            set_flash('success', 'Category created.');
        }
        redirect('categories.php');
    }

    if ($action === 'delete_category') {
        $id = (int) ($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
        set_flash('success', 'Category deleted.');
        redirect('categories.php');
    }
}

$stmt = db()->query('SELECT c.*, COUNT(p.id) AS items, COALESCE(SUM(p.on_hand * p.cost_price), 0) AS stock_value FROM categories c LEFT JOIN products p ON p.category_id = c.id AND p.archived = 0 GROUP BY c.id, c.name, c.description ORDER BY c.name ASC');
$categories = $stmt->fetchAll();

$editing = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $cat = db()->prepare('SELECT * FROM categories WHERE id = ? LIMIT 1');
    $cat->execute([(int) $_GET['edit']]);
    $editing = $cat->fetch();
}

$user = inventory_get_current_user();
$flashSuccess = get_flash('success');
$flashError = get_flash('error');

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Categories | Inventory</title>
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
        <a class="nav-link active" href="categories.php"><span class="icon">▤</span>Categories</a>
        <a class="nav-link" href="reorder_alerts.php"><span class="icon">⚑</span>Reorder Alerts <span class="nav-badge"><?= e(get_sidebar_badge_count()) ?></span></a>
        <a class="nav-link" href="stock_movements.php"><span class="icon">↗</span>Stock Movements</a>
        <a class="nav-link" href="transactions.php"><span class="icon">▣</span>Transactions</a>
        <a class="nav-link" href="suppliers.php"><span class="icon">◌</span>Suppliers</a>
        <a class="nav-link" href="demand_forecast.php"><span class="icon">◔</span>Demand Forecast</a>
        <a class="nav-link" href="reports.php"><span class="icon">▤</span>Reports</a>
        <a class="nav-link" href="security.php"><span class="icon">◉</span>Security &amp; Data</a>
      </nav>
      <div class="sidebar-footer">Stock levels refresh live as movements are recorded.</div>
    </aside>

    <main class="main-panel">
      <header class="topbar">
        <div class="page-title">Categories</div>
        <div class="user-box">
          <div class="user-avatar"><?= strtoupper(substr($user['name'], 0, 1)) ?></div>
          <div class="user-meta"><strong><?= e($user['name']) ?></strong><small><?= e(strtoupper($user['role'])) ?></small></div>
          <a class="btn icon-btn" href="logout.php" title="Logout">⎋</a>
        </div>
      </header>

      <div class="content">
        <div class="page-head">
          <div class="head-left"><h1>Product categories</h1><p>How your catalog is grouped — for navigation, valuation reports and demand analysis.</p></div>
          <div class="header-actions"><button class="btn btn-primary" data-open-modal="categoryModal">+ Add category</button></div>
        </div>

        <div class="card-list">
          <?php foreach ($categories as $category): ?>
            <div class="category-card">
              <div class="card-top">
                <div class="card-name"><?= e($category['name']) ?></div>
                <div class="row-menu-wrap">
                  <button type="button" class="icon-btn row-menu-toggle" aria-label="Category actions">⋮</button>
                  <div class="row-menu">
                    <a href="categories.php?edit=<?= (int) $category['id'] ?>"><span class="menu-icon">✎</span><span>Edit</span></a>
                    <form method="post" action="categories.php" onsubmit="return confirm('Delete this category?');">
                      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                      <input type="hidden" name="action" value="delete_category">
                      <input type="hidden" name="id" value="<?= (int) $category['id'] ?>">
                      <button type="submit"><span class="menu-icon">🗑</span><span>Delete</span></button>
                    </form>
                  </div>
                </div>
              </div>
              <div class="card-sub"><?= e($category['description'] ?: 'No description available.') ?></div>
              <div class="card-meta">
                <div><span class="small">Items</span><br><strong><?= e((int) $category['items']) ?></strong></div>
                <div><span class="small">Stock value</span><br><strong><?= format_currency($category['stock_value']) ?></strong></div>
              </div>
              <div class="card-foot">
                <a class="link-inline" href="products.php?category=<?= (int) $category['id'] ?>">View items</a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </main>
  </div>

  <div class="modal <?= $editing ? 'open' : '' ?>" id="categoryModal">
    <div class="modal-card">
      <div class="modal-head"><h3><?= $editing ? 'Edit category' : 'Add category' ?></h3><button class="modal-close" type="button">✕</button></div>
      <div class="modal-body">
        <form method="post" action="categories.php">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="save_category">
          <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
          <div class="form-grid">
            <div class="field-wrap full">
              <label>Name</label>
              <input class="form-control" name="name" value="<?= e($editing['name'] ?? '') ?>" required>
            </div>
            <div class="field-wrap full">
              <label>Description</label>
              <textarea class="form-control" name="description" rows="4"><?= e($editing['description'] ?? '') ?></textarea>
            </div>
          </div>
          <div class="form-actions"><button type="button" class="btn modal-close">Cancel</button><button type="submit" class="btn btn-primary"><?= $editing ? 'Save changes' : 'Add category' ?></button></div>
        </form>
      </div>
    </div>
  </div>

  <script src="assets/js/app.js"></script>
</body>
</html>
