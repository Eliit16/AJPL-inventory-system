<?php
require_once __DIR__ . '/config.php';
require_login();

if (isset($_GET['export']) && $_GET['export'] == '1') {
    $rows = db()->query('SELECT p.name, p.unit, p.on_hand, p.reorder_level, p.cost_price, (SELECT COALESCE(SUM(quantity),0) FROM stock_movements WHERE product_id = p.id AND movement_type = "out" AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)) AS recent_3m, (SELECT COALESCE(SUM(quantity),0) FROM stock_movements WHERE product_id = p.id AND movement_type = "out" AND created_at >= DATE_SUB(NOW(), INTERVAL 180 DAY)) AS recent_6m FROM products p WHERE p.archived = 0 ORDER BY p.name ASC')->fetchAll();
    $out = fopen('php://output', 'w');
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="demand_forecast.csv"');
    fputcsv($out, ['Item', 'On Hand', 'Avg / Month', 'Forecast', 'Suggested Order', 'Days Cover', 'Status']);
    foreach ($rows as $row) {
        $threeAvg = ((float) $row['recent_3m']) / 3;
        $sixAvg = ((float) $row['recent_6m']) / 6;
        $forecast = (($threeAvg * 2) + $sixAvg) / 3;
        $daysCover = $row['on_hand'] / max($threeAvg, 1) * 30;
        $suggested = max((int) ceil(($forecast * 1.5) - $row['on_hand']), 0);
        $status = $daysCover < 30 ? 'Reorder now' : ($daysCover > 120 ? 'Healthy' : 'Monitor');
        fputcsv($out, [$row['name'], $row['on_hand'], round($threeAvg, 2), round($forecast, 2), $suggested, round($daysCover, 2), $status]);
    }
    fclose($out);
    exit;
}

$showOnlyReorder = (isset($_GET['show']) && $_GET['show'] === 'reorder');

$rows = db()->query('SELECT p.*, (SELECT COALESCE(SUM(quantity),0) FROM stock_movements WHERE product_id = p.id AND movement_type = "out" AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)) AS recent_3m, (SELECT COALESCE(SUM(quantity),0) FROM stock_movements WHERE product_id = p.id AND movement_type = "out" AND created_at >= DATE_SUB(NOW(), INTERVAL 180 DAY)) AS recent_6m FROM products p WHERE p.archived = 0 ORDER BY p.name ASC')->fetchAll();
$forecastRows = [];
foreach ($rows as $row) {
    $threeAvg = ((float) $row['recent_3m']) / 3;
    $sixAvg = ((float) $row['recent_6m']) / 6;
    $forecast = (($threeAvg * 2) + $sixAvg) / 3;
    $daysCover = ((float) $row['on_hand']) / max($threeAvg, 1) * 30;
    $suggestedOrder = max((int) ceil(($forecast * 1.5) - $row['on_hand']), 0);
    $status = $daysCover < 30 ? 'Reorder now' : ($daysCover > 120 ? 'Healthy' : 'Monitor');
    $entry = [
        'id' => $row['id'],
        'name' => $row['name'],
        'on_hand' => (int) $row['on_hand'],
        'avg_month' => $threeAvg,
        'forecast' => $forecast,
        'days_cover' => $daysCover,
        'suggested_order' => $suggestedOrder,
        'status' => $status,
        'cost_price' => (float) $row['cost_price']
    ];
    if (!$showOnlyReorder || $status === 'Reorder now' || $entry['suggested_order'] > 0) {
        $forecastRows[] = $entry;
    }
}

$topItem = $forecastRows[0] ?? null;
$chartLabels = [];
$chartValues = [];
if ($topItem) {
    $data = db()->prepare('SELECT DATE_FORMAT(created_at, "%b %d") AS label, COUNT(*) AS cnt FROM stock_movements WHERE product_id = ? AND movement_type = "out" AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH) GROUP BY DATE_FORMAT(created_at, "%Y-%m-%d") ORDER BY created_at ASC LIMIT 6');
    $data->execute([$topItem['id']]);
    $chartRows = $data->fetchAll();
    foreach ($chartRows as $r) {
        $chartLabels[] = $r['label'];
        $chartValues[] = (int) $r['cnt'];
    }
}

$itemsToReorder = count(array_filter($forecastRows, fn($row) => $row['suggested_order'] > 0 || $row['status'] === 'Reorder now'));
$suggestedPurchaseValue = array_reduce($forecastRows, fn($carry, $row) => $carry + ($row['suggested_order'] * $row['cost_price']), 0.0);
$overStocked = count(array_filter($forecastRows, fn($row) => $row['days_cover'] > 120));
$itemsAnalysed = count($forecastRows);

$user = inventory_get_current_user();
$flashSuccess = get_flash('success');
$flashError = get_flash('error');

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Demand Forecast | Inventory</title>
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
        <a class="nav-link active" href="demand_forecast.php"><span class="icon">◔</span>Demand Forecast</a>
        <a class="nav-link" href="reports.php"><span class="icon">▤</span>Reports</a>
        <a class="nav-link" href="security.php"><span class="icon">◉</span>Security &amp; Data</a>
      </nav>
      <div class="sidebar-footer">Stock levels refresh live as movements are recorded.</div>
    </aside>

    <main class="main-panel">
      <header class="topbar">
        <div class="page-title">Demand forecast</div>
        <div class="user-box">
          <div class="user-avatar"><?= strtoupper(substr($user['name'], 0, 1)) ?></div>
          <div class="user-meta"><strong><?= e($user['name']) ?></strong><small><?= e(strtoupper($user['role'])) ?></small></div>
          <a class="btn icon-btn" href="logout.php" title="Logout">⎋</a>
        </div>
      </header>

      <div class="content">
        <div class="page-head">
          <div class="head-left"><h1>Demand forecast</h1><p>Projected demand for the coming month, based on the quantities you issued over the last six months.</p></div>
          <div class="header-actions">
            <a class="btn" href="demand_forecast.php?show=reorder">Show items to reorder</a>
            <a class="btn btn-primary" href="demand_forecast.php?export=1">Export CSV</a>
          </div>
        </div>

        <div class="kpi-inline" style="margin-bottom: 18px;">
          <div class="small-stats"><span class="k">Items to reorder</span><span class="v"><?= e($itemsToReorder) ?></span></div>
          <div class="small-stats"><span class="k">Suggested purchase value</span><span class="v"><?= format_currency($suggestedPurchaseValue) ?></span></div>
          <div class="small-stats"><span class="k">Overstocked items</span><span class="v"><?= e($overStocked) ?></span></div>
          <div class="small-stats"><span class="k">Items analysed</span><span class="v"><?= e($itemsAnalysed) ?></span></div>
        </div>

        <div class="chart-wrap">
          <?php if ($topItem): ?>
            <div style="display:flex; justify-content:space-between; gap:12px; align-items:center; margin-bottom:14px;">
              <div>
                <div style="font-weight:700; font-size:1.2rem;"><?= e($topItem['name']) ?></div>
                <div style="color: var(--muted-foreground); font-size:0.82rem;"><?= e((int) $topItem['on_hand']) ?> on hand</div>
              </div>
              <div style="color: var(--muted-foreground); font-size:0.75rem; letter-spacing:0.12em; text-transform:uppercase;">Forecast confidence: high</div>
            </div>
            <div class="chart-box">
              <canvas data-chart="line" data-labels='<?= json_encode($chartLabels ?: ['Apr 26','May 26','Jun 26','Jul 26','Aug 26','Sep 26']) ?>' data-values='<?= json_encode($chartValues ?: [0,0,0,0,0,0]) ?>' data-colors='["#2B7CB6"]'></canvas>
            </div>
          <?php else: ?>
            <div class="empty-state">No forecast data is available yet.</div>
          <?php endif; ?>
        </div>

        <div class="chart-wrap" style="margin-top:18px;">
          <table style="width:100%; border-collapse:collapse;">
            <thead>
              <tr>
                <th>Item</th>
                <th>Avg / month</th>
                <th>Trend</th>
                <th>Forecast</th>
                <th>On hand</th>
                <th>Days cover</th>
                <th>Suggested order</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($forecastRows as $row): ?>
                <tr>
                  <td><?= e($row['name']) ?></td>
                  <td><?= round($row['avg_month'], 2) ?></td>
                  <td><?= $row['forecast'] >= $row['avg_month'] ? 'Up' : 'Down' ?></td>
                  <td><?= round($row['forecast'], 2) ?></td>
                  <td><?= e($row['on_hand']) ?></td>
                  <td><?= round($row['days_cover'], 1) ?></td>
                  <td><?= e($row['suggested_order']) ?></td>
                  <td><span class="status-badge <?= $row['status'] === 'Reorder now' ? 'out' : 'in' ?>"><?= e($row['status']) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </main>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="assets/js/app.js"></script>
</body>
</html>
