<?php
require_once __DIR__ . '/config.php';
require_login();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$user = inventory_get_current_user();

$totalStockValue = (float) db()->query('SELECT COALESCE(SUM(on_hand * cost_price),0) AS total FROM products WHERE archived = 0')->fetch()['total'];
$lowStockCount = (int) db()->query('SELECT COUNT(*) AS c FROM products WHERE archived = 0 AND on_hand <= reorder_level')->fetch()['c'];
$outOfStockCount = (int) db()->query('SELECT COUNT(*) AS c FROM products WHERE archived = 0 AND on_hand <= 0')->fetch()['c'];
$productsCount = (int) db()->query('SELECT COUNT(*) AS c FROM products WHERE archived = 0')->fetch()['c'];
$categoryCount = (int) db()->query('SELECT COUNT(*) AS c FROM categories')->fetch()['c'];

$months = [];
$received = [];
$issued = [];
$now = new DateTime('now');
$chartStart = (clone $now)->modify('first day of this month')->modify('-5 months')->setTime(0, 0, 0);
$chartEnd = (clone $now)->modify('first day of next month')->setTime(0, 0, 0);
$movementTotals = db()->prepare(
    'SELECT DATE_FORMAT(sm.created_at, "%Y-%m") AS month_key, sm.movement_type, COALESCE(SUM(sm.quantity), 0) AS total
     FROM stock_movements sm
     INNER JOIN products p ON p.id = sm.product_id AND p.archived = 0
     WHERE sm.created_at >= ? AND sm.created_at < ?
     GROUP BY DATE_FORMAT(sm.created_at, "%Y-%m"), sm.movement_type'
);
$movementTotals->execute([
    $chartStart->format('Y-m-d H:i:s'),
    $chartEnd->format('Y-m-d H:i:s')
]);
$monthlyMovementTotals = [];
foreach ($movementTotals->fetchAll() as $movementTotal) {
    $monthlyMovementTotals[$movementTotal['month_key']][$movementTotal['movement_type']] = (float) $movementTotal['total'];
}

for ($i = 5; $i >= 0; $i--) {
    $date = (clone $now)->modify("-{$i} month");
    $label = $date->format('M y');
    $months[] = $label;
    $monthKey = $date->format('Y-m');
    $received[] = $monthlyMovementTotals[$monthKey]['in'] ?? 0;
    $issued[] = $monthlyMovementTotals[$monthKey]['out'] ?? 0;
}

$categoryData = db()->query('SELECT c.name, COALESCE(SUM(p.on_hand * p.cost_price),0) AS total FROM categories c LEFT JOIN products p ON p.category_id = c.id AND p.archived = 0 GROUP BY c.id, c.name ORDER BY total DESC')->fetchAll();
$categoryLabels = array_map(fn($row) => $row['name'], $categoryData);
$categoryValues = array_map(fn($row) => (float) $row['total'], $categoryData);

$lowProducts = db()->query('SELECT p.*, c.name AS category_name, s.name AS supplier_name FROM products p LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN suppliers s ON s.id = p.supplier_id WHERE p.archived = 0 AND p.on_hand <= p.reorder_level ORDER BY p.on_hand ASC LIMIT 4')->fetchAll();

$fastItems = db()->query('SELECT p.name, p.unit, p.category_id, p.on_hand, SUM(sm.quantity) AS total_out FROM products p LEFT JOIN stock_movements sm ON sm.product_id = p.id AND sm.movement_type = "out" AND sm.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) WHERE p.archived = 0 GROUP BY p.id, p.name, p.unit, p.category_id, p.on_hand ORDER BY total_out DESC LIMIT 5')->fetchAll();

$latest = db()->query('SELECT sm.*, p.name AS product_name FROM stock_movements sm INNER JOIN products p ON p.id = sm.product_id AND p.archived = 0 ORDER BY sm.id DESC LIMIT 6')->fetchAll();

$reorderAlerts = db()->query('SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.archived = 0 AND p.on_hand <= p.reorder_level ORDER BY p.on_hand ASC LIMIT 4')->fetchAll();

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Dashboard | Inventory</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body>
  <div class="app-shell">
    <aside class="sidebar">
      <div class="brand-row">
        <img class="brand-mark" src="assets/images/ajpl-logo.png" alt="AJPL Hardware and Construction Supplies">
        <div class="brand-copy">AJPL<br>Hardware &amp; Construction Supplies</div>
      </div>

      <nav class="sidebar-nav">
        <a class="nav-link active" href="dashboard.php"><span class="icon">▣</span>Dashboard</a>
        <a class="nav-link" href="products.php"><span class="icon">◫</span>Products</a>
        <a class="nav-link" href="categories.php"><span class="icon">▤</span>Categories</a>
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
        <div class="page-title">Dashboard</div>
        <div class="user-box">
          <div class="user-avatar"><?= strtoupper(substr($user['name'], 0, 1)) ?></div>
          <div class="user-meta">
            <strong><?= e($user['name']) ?></strong>
            <small><?= e(strtoupper($user['role'])) ?></small>
          </div>
          <a class="btn icon-btn" href="logout.php" title="Logout">⎋</a>
        </div>
      </header>

      <div class="content">
        <div class="page-head">
          <div class="head-left">
            <h1>Inventory dashboard</h1>
            <p>Live stock patrol across your catalog, with movement and restock insights.</p>
          </div>
        </div>

        <?php if ($lowStockCount > 0): ?>
          <a class="stock-warning" href="reorder_alerts.php">
            <span class="stock-warning-icon" aria-hidden="true">!</span>
            <span class="stock-warning-copy">
              <strong><?= e($lowStockCount) ?> <?= $lowStockCount === 1 ? 'item needs' : 'items need' ?> restocking</strong>
              <span><?= e($outOfStockCount) ?> already out of stock, <?= e($lowStockCount - $outOfStockCount) ?> at or below the reorder point. Open the reorder list to see amounts.</span>
            </span>
            <span class="stock-warning-arrow" aria-hidden="true">→</span>
          </a>
        <?php endif; ?>

        <div class="soft-panel" style="margin-bottom: 18px;">
          <div class="card-grid">
            <div class="metric-card">
              <div class="label">Total stock value</div>
              <div class="value"><?= format_currency($totalStockValue) ?></div>
              <div class="sub">Across <?= e($productsCount) ?> active products</div>
            </div>
            <div class="metric-card">
              <div class="label">Products value</div>
              <div class="value"><?= e($productsCount) ?></div>
              <div class="sub">Active products</div>
            </div>
            <div class="metric-card">
              <div class="label">Categories</div>
              <div class="value"><?= e($categoryCount) ?></div>
              <div class="sub">Inventory groupings</div>
            </div>
          </div>
        </div>

        <div class="chart-wrap" style="margin-bottom: 18px;">
          <div class="chart-box" style="height: 260px;">
            <h3 style="margin: 0 0 8px;">Stock received vs issued</h3>
            <canvas data-chart="bar" data-labels='<?= json_encode($months) ?>' data-received='<?= json_encode($received) ?>' data-issued='<?= json_encode($issued) ?>' data-colors='["#83b94d", "#b8443e"]'></canvas>
          </div>
        </div>

        <div class="soft-panel" style="margin-bottom: 18px;">
          <div class="card-grid">
            <div class="chart-wrap" style="padding: 12px; height: 240px;">
              <h3 style="margin-bottom:12px;">Value by category</h3>
              <div style="height: 180px;">
                <canvas data-chart="doughnut" data-labels='<?= json_encode($categoryLabels) ?>' data-values='<?= json_encode($categoryValues) ?>' data-colors='["#83B94D", "#2F604B", "#A7C978", "#4C8061", "#C2D99A", "#6E927D", "#B7CF8B"]'></canvas>
              </div>
            </div>

            <div class="chart-wrap" style="padding: 12px;">
              <h3 style="margin-bottom:12px;">Reorder alerts</h3>
              <?php if (empty($reorderAlerts)): ?>
                <div class="empty-state">No items currently need attention.</div>
              <?php else: ?>
                <?php foreach ($reorderAlerts as $item): ?>
                  <div style="padding: 10px 0; border-bottom: 1px solid var(--border);">
                    <div style="display:flex; justify-content:space-between; gap:10px; align-items:center;">
                      <strong><?= e($item['name']) ?></strong>
                      <span class="status-badge <?= ($item['on_hand'] <= 0 ? 'out' : 'low') ?>"><?= ($item['on_hand'] <= 0 ? 'Out of stock' : 'Low stock') ?></span>
                    </div>
                    <div style="color: var(--muted-foreground); font-size: 0.8rem; margin-top: 4px;"><?= e($item['category_name']) ?></div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>

            <div class="chart-wrap" style="padding: 12px;">
              <h3 style="margin-bottom:12px;">Fastest moving items</h3>
              <?php if (empty($fastItems)): ?>
                <div class="empty-state">No movement history in the last 90 days.</div>
              <?php else: ?>
                <?php foreach ($fastItems as $item): ?>
                  <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; border-bottom:1px solid var(--border); padding: 8px 0;">
                    <div>
                      <strong><?= e($item['name']) ?></strong><br>
                      <span style="font-size:0.78rem; color: var(--muted-foreground);"><?= e($item['unit']) ?></span>
                    </div>
                    <strong style="color: var(--primary);"><?= e((int) $item['total_out']) ?> </strong>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="soft-panel">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <h3>Latest stock activity</h3>
          </div>
          <div class="table-card">
            <table>
              <thead>
                <tr>
                  <th>Item</th>
                  <th>Type</th>
                  <th>Qty</th>
                  <th>Reference</th>
                  <th>Time</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($latest as $row): ?>
                  <tr>
                    <td><?= e($row['product_name'] ?? 'Unknown') ?></td>
                    <td><span class="status-badge <?= $row['movement_type'] === 'in' ? 'in' : 'out' ?>"><?= e(strtoupper($row['movement_type'])) ?></span></td>
                    <td><?= e($row['quantity']) ?></td>
                    <td><?= e($row['reference'] ?: '—') ?></td>
                    <td><?= format_date($row['created_at']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </main>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="assets/js/app.js?v=3"></script>
</body>
</html>
