<?php
require_once __DIR__ . '/config.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'receive_stock') {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $qty = (int) ($_POST['quantity'] ?? 0);
        if ($productId > 0 && $qty > 0) {
            db()->beginTransaction();
            try {
                $product = db()->prepare('SELECT * FROM products WHERE id = ? AND archived = 0 LIMIT 1');
                $product->execute([$productId]);
                $row = $product->fetch();
                if (!$row) throw new Exception('Product not found.');
                $newQty = (int) $row['on_hand'] + $qty;
                db()->prepare('UPDATE products SET on_hand = ? WHERE id = ?')->execute([$newQty, $productId]);
                db()->prepare('INSERT INTO stock_movements (product_id, movement_type, quantity, reason, reference, notes, user_id) VALUES (?, "in", ?, "restock", ?, ?, ?)')->execute([$productId, $qty, 'restock', 'Received reorder stock', $_SESSION['user_id']]);
                db()->commit();
                set_flash('success', 'Stock received and recorded.');
            } catch (Exception $e) {
                db()->rollBack();
                set_flash('error', $e->getMessage());
            }
        } else {
            set_flash('error', 'Receive quantity must be greater than zero.');
        }
        redirect('reorder_alerts.php');
    }
}

$alerts = db()->query('SELECT p.*, c.name AS category_name, s.name AS supplier_name, p.reorder_quantity AS suggested_qty FROM products p LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN suppliers s ON s.id = p.supplier_id WHERE p.archived = 0 AND p.on_hand <= p.reorder_level ORDER BY p.on_hand ASC')->fetchAll();
$itemsNeedingAttention = count($alerts);
$alreadyOut = count(array_filter($alerts, fn($item) => (int) $item['on_hand'] <= 0));
$estimatedCost = array_reduce($alerts, fn($carry, $item) => $carry + ((float) $item['cost_price'] * (int) $item['suggested_qty']), 0.0);

$user = inventory_get_current_user();
$flashSuccess = get_flash('success');
$flashError = get_flash('error');

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Reorder Alerts | Inventory</title>
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
        <a class="nav-link active" href="reorder_alerts.php"><span class="icon">⚑</span>Reorder Alerts <span class="nav-badge"><?= e(get_sidebar_badge_count()) ?></span></a>
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
        <div class="page-title">Reorder Alerts</div>
        <div class="user-box">
          <div class="user-avatar"><?= strtoupper(substr($user['name'], 0, 1)) ?></div>
          <div class="user-meta"><strong><?= e($user['name']) ?></strong><small><?= e(strtoupper($user['role'])) ?></small></div>
          <a class="btn icon-btn" href="logout.php" title="Logout">⎋</a>
        </div>
      </header>

      <div class="content">
        <div class="page-head">
          <div class="head-left"><h1>Low-stock reorder alerts</h1><p>Items at or below their reorder point, with the amount worth ordering and who supplies it.</p></div>
        </div>

        <div class="card-grid" style="margin-bottom: 18px;">
          <div class="metric-card"><div class="label">Items needing attention</div><div class="value"><?= e($itemsNeedingAttention) ?></div><div class="sub">Low stock / reorder items</div></div>
          <div class="metric-card"><div class="label">Already out of stock</div><div class="value"><?= e($alreadyOut) ?></div><div class="sub">Immediate follow up</div></div>
          <div class="metric-card"><div class="label">Estimated restock cost</div><div class="value"><?= format_currency($estimatedCost) ?></div><div class="sub">At current cost prices</div></div>
        </div>

        <div class="table-card">
          <table>
            <tbody>
              <?php if (!$alerts): ?>
                <tr><td><div class="empty-state">No reorder alerts at the moment.</div></td></tr>
              <?php else: ?>
                <?php foreach ($alerts as $item): ?>
                  <tr>
                    <td style="width: 48%;">
                      <div style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
                        <div>
                          <strong><?= e($item['name']) ?></strong><br>
                          <span style="color: var(--muted-foreground); font-size: 0.8rem;"><?= e($item['category_name'] ?: 'Uncategorized') ?></span>
                          <div style="margin-top: 4px; color: var(--muted-foreground); font-size: 0.8rem;">
                            <?= e((int) $item['on_hand']) ?> on hand · reorder at <?= e((int) $item['reorder_level']) ?>
                          </div>
                        </div>
                        <span class="status-badge <?= ((int) $item['on_hand'] <= 0 ? 'out' : 'low') ?>"><?= ((int) $item['on_hand'] <= 0 ? 'Out of stock' : 'Low stock') ?></span>
                      </div>
                    </td>
                    <td style="width: 18%; text-align: center;">
                      <div style="font-size:0.7rem; color: var(--muted-foreground); text-transform: uppercase; letter-spacing: 0.1em;">Suggested order</div>
                      <div style="font-weight:700; font-size:1.2rem; margin-top: 4px;">
                        <?= e((int) $item['suggested_qty']) ?> <?= e($item['unit']) ?>
                      </div>
                      <div style="font-size:0.8rem; color: var(--muted-foreground);">= <?= format_currency((float) $item['cost_price'] * (int) $item['suggested_qty']) ?></div>
                    </td>
                    <td style="width: 18%; text-align: center;">
                      <div style="font-size:0.7rem; color: var(--muted-foreground); text-transform: uppercase; letter-spacing: 0.1em;">Supplier</div>
                      <div style="font-weight:700; margin-top:4px; font-size: 0.9rem; "><?= e($item['supplier_name'] ?: '—') ?></div>
                    </td>
                    <td style="width: 16%; text-align: right;">
                      <form method="post" action="reorder_alerts.php">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="receive_stock">
                        <input type="hidden" name="product_id" value="<?= (int) $item['id'] ?>">
                        <input type="hidden" name="quantity" value="<?= max(1, (int) $item['suggested_qty']) ?>">
                        <button class="btn btn-primary" type="submit">Receive stock</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </main>
  </div>

  <script src="assets/js/app.js"></script>
</body>
</html>
