<?php
require_once __DIR__ . '/config.php';
require_login();

$today = new DateTime('today');
$defaultFrom = (clone $today)->modify('first day of this month')->format('Y-m-d');
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : $defaultFrom;
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : $today->format('Y-m-d');
$requestedMovementType = $_GET['type'] ?? 'all';
$movementType = in_array($requestedMovementType, ['all', 'in', 'out'], true) ? $requestedMovementType : 'all';
$requestedPeriod = (int) ($_GET['period'] ?? 90);
$period = in_array($requestedPeriod, [30, 90, 180, 365], true) ? $requestedPeriod : 90;

$categoryRows = db()->query('SELECT c.name, COUNT(p.id) AS items, COALESCE(SUM(p.on_hand * p.cost_price), 0) AS value
    FROM categories c LEFT JOIN products p ON p.category_id = c.id AND p.archived = 0
    GROUP BY c.id, c.name ORDER BY value DESC, c.name ASC')->fetchAll();
$uncategorized = db()->query('SELECT COUNT(*) AS items, COALESCE(SUM(on_hand * cost_price), 0) AS value FROM products WHERE archived = 0 AND category_id IS NULL')->fetch();
if ((int) $uncategorized['items'] > 0) {
    $categoryRows[] = ['name' => 'Uncategorized', 'items' => $uncategorized['items'], 'value' => $uncategorized['value']];
}
$totalStockValue = (float) db()->query('SELECT COALESCE(SUM(on_hand * cost_price), 0) AS total FROM products WHERE archived = 0')->fetch()['total'];
$productCount = (int) db()->query('SELECT COUNT(*) AS c FROM products WHERE archived = 0')->fetch()['c'];
$highestItems = db()->query('SELECT p.name, p.unit, p.on_hand, c.name AS category_name, (p.on_hand * p.cost_price) AS total_value
    FROM products p LEFT JOIN categories c ON c.id = p.category_id
    WHERE p.archived = 0 ORDER BY total_value DESC, p.name ASC LIMIT 8')->fetchAll();

$movementWhere = ['sm.created_at >= ?', 'sm.created_at <= ?', 'p.archived = 0'];
$movementParams = [$from . ' 00:00:00', $to . ' 23:59:59'];
if ($movementType !== 'all') {
    $movementWhere[] = 'sm.movement_type = ?';
    $movementParams[] = $movementType;
}
$movementSql = 'SELECT sm.created_at, sm.movement_type, sm.quantity, sm.reason, p.name AS product_name, p.unit, u.name AS user_name
    FROM stock_movements sm INNER JOIN products p ON p.id = sm.product_id LEFT JOIN users u ON u.id = sm.user_id
    WHERE ' . implode(' AND ', $movementWhere) . ' ORDER BY sm.created_at DESC, sm.id DESC';
$movementStmt = db()->prepare($movementSql);
$movementStmt->execute($movementParams);
$movementRows = $movementStmt->fetchAll();
$movementCount = count($movementRows);
$receivedQty = 0;
$issuedQty = 0;
foreach ($movementRows as $movement) {
    if ($movement['movement_type'] === 'in') {
        $receivedQty += (int) $movement['quantity'];
    } else {
        $issuedQty += (int) $movement['quantity'];
    }
}

$periodStart = (clone $today)->modify("-{$period} days")->format('Y-m-d 00:00:00');
$moverStmt = db()->prepare('SELECT p.id, p.name, p.unit, COALESCE(SUM(CASE WHEN sm.movement_type = "out" THEN sm.quantity ELSE 0 END), 0) AS issued
    FROM products p LEFT JOIN stock_movements sm ON sm.product_id = p.id AND sm.created_at >= ? AND sm.created_at <= ?
    WHERE p.archived = 0 GROUP BY p.id, p.name, p.unit ORDER BY issued DESC, p.name ASC');
$moverStmt->execute([$periodStart, $today->format('Y-m-d 23:59:59')]);
$moverRows = $moverStmt->fetchAll();
$fastMovers = array_slice($moverRows, 0, 5);
$slowMovers = array_slice(array_reverse($moverRows), 0, 6);
$periodIssued = array_sum(array_map(fn($row) => (int) $row['issued'], $moverRows));
$itemsWithMovement = count(array_filter($moverRows, fn($row) => (int) $row['issued'] > 0));
$maxIssued = max(1, ...array_map(fn($row) => (int) $row['issued'], $fastMovers));

if (isset($_GET['export']) && $_GET['export'] === '1') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="stock_movement_report.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Item', 'Type', 'Qty', 'Unit', 'Reason', 'Recorded by']);
    foreach ($movementRows as $row) {
        fputcsv($out, [$row['created_at'], $row['product_name'], strtoupper($row['movement_type']), $row['quantity'], $row['unit'], $row['reason'], $row['user_name'] ?: 'System']);
    }
    fclose($out);
    exit;
}

$user = inventory_get_current_user();
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reports | Inventory</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
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
        <a class="nav-link active" href="reports.php"><span class="icon">▤</span>Reports</a>
        <a class="nav-link" href="security.php"><span class="icon">◉</span>Security &amp; Data</a>
      </nav>
      <div class="sidebar-footer">Stock levels refresh live as movements are recorded.</div>
    </aside>
    <main class="main-panel">
      <header class="topbar"><div class="page-title">Reports</div><div class="user-box"><div class="user-avatar"><?= strtoupper(substr($user['name'], 0, 1)) ?></div><div class="user-meta"><strong><?= e($user['name']) ?></strong><small><?= e(strtoupper($user['role'])) ?></small></div><a class="btn icon-btn" href="logout.php" title="Logout">⎋</a></div></header>
      <div class="content reports-page">
        <div class="page-head"><div class="head-left"><h1>Reports</h1><p>Inventory valuation, stock movement and product performance.</p></div></div>
        <div class="report-tabs" role="tablist" aria-label="Report views">
          <button class="report-tab active" id="valuation-tab" role="tab" aria-selected="true" aria-controls="valuation-panel" tabindex="0" data-report-tab="valuation">Inventory valuation</button>
          <button class="report-tab" id="movement-tab" role="tab" aria-selected="false" aria-controls="movement-panel" tabindex="-1" data-report-tab="movement">Stock movement</button>
          <button class="report-tab" id="movers-tab" role="tab" aria-selected="false" aria-controls="movers-panel" tabindex="-1" data-report-tab="movers">Fast &amp; slow movers</button>
        </div>

        <section class="report-panel active" id="valuation-panel" role="tabpanel" aria-labelledby="valuation-tab">
          <div class="report-section"><div class="report-section-title"><h2>Inventory valuation</h2><span><?= e($productCount) ?> active products</span></div>
            <div class="report-table-wrap"><table class="report-table valuation-table"><thead><tr><th>Category</th><th>Items</th><th>Stock value</th><th>Share</th></tr></thead><tbody>
              <?php foreach ($categoryRows as $row): $share = $totalStockValue > 0 ? ((float) $row['value'] / $totalStockValue) * 100 : 0; ?>
                <tr><td><?= e($row['name']) ?></td><td><?= e((int) $row['items']) ?></td><td><?= format_currency($row['value']) ?></td><td><?= number_format($share, 1) ?>%</td></tr>
              <?php endforeach; ?>
              <tr class="report-total"><td>Total</td><td><?= e($productCount) ?></td><td><?= format_currency($totalStockValue) ?></td><td>100%</td></tr>
            </tbody></table></div>
          </div>
          <div class="report-section"><div class="report-section-title"><h2>Highest value items</h2><span>Top 8 by stock value</span></div>
            <div class="report-table-wrap"><table class="report-table valuation-items-table"><thead><tr><th>Product</th><th>Category</th><th>Quantity</th><th>Stock value</th></tr></thead><tbody>
              <?php if (!$highestItems): ?><tr><td colspan="4" class="report-empty">No active products to value.</td></tr><?php endif; ?>
              <?php foreach ($highestItems as $row): ?><tr><td><strong><?= e($row['name']) ?></strong></td><td class="report-muted"><?= e($row['category_name'] ?: 'Uncategorized') ?></td><td><?= e((int) $row['on_hand']) ?> <?= e($row['unit']) ?></td><td><strong><?= format_currency($row['total_value']) ?></strong></td></tr><?php endforeach; ?>
            </tbody></table></div>
          </div>
        </section>

        <section class="report-panel" id="movement-panel" role="tabpanel" aria-labelledby="movement-tab" hidden>
          <form class="report-filter-card" method="get" action="reports.php">
            <input type="hidden" name="tab" value="movement">
            <div class="field-wrap"><label for="report-from">From</label><input class="form-control" id="report-from" type="date" name="from" value="<?= e($from) ?>"></div>
            <div class="field-wrap"><label for="report-to">To</label><input class="form-control" id="report-to" type="date" name="to" value="<?= e($to) ?>"></div>
            <div class="field-wrap"><label for="report-type">Movement type</label><select class="form-control" id="report-type" name="type"><option value="all" <?= $movementType === 'all' ? 'selected' : '' ?>>All movements</option><option value="in" <?= $movementType === 'in' ? 'selected' : '' ?>>Stock in</option><option value="out" <?= $movementType === 'out' ? 'selected' : '' ?>>Stock out</option></select></div>
            <button class="btn btn-primary report-filter-submit" type="submit">Apply filters</button>
          </form>
          <div class="report-metrics"><div class="metric-card"><div class="label">Movements logged</div><div class="value"><?= e($movementCount) ?></div><div class="sub">In the selected period</div></div><div class="metric-card"><div class="label">Quantity received</div><div class="value report-positive"><?= e($receivedQty) ?></div><div class="sub">Stock in quantity</div></div><div class="metric-card"><div class="label">Quantity issued</div><div class="value report-negative"><?= e($issuedQty) ?></div><div class="sub">Stock out quantity</div></div></div>
          <div class="report-toolbar"><span><?= e($from) ?> to <?= e($to) ?></span><a class="btn" href="reports.php?export=1&amp;from=<?= e($from) ?>&amp;to=<?= e($to) ?>&amp;type=<?= e($movementType) ?>">⇩ Export this report</a></div>
          <div class="report-table-wrap"><table class="report-table movement-table"><thead><tr><th>Date</th><th>Item</th><th>Type</th><th>Qty</th><th>Reason</th><th>Recorded by</th></tr></thead><tbody>
            <?php if (!$movementRows): ?><tr><td colspan="6" class="report-empty">No movements match the selected filters.</td></tr><?php endif; ?>
            <?php foreach ($movementRows as $row): ?><tr><td><?= format_date($row['created_at']) ?></td><td><strong><?= e($row['product_name']) ?></strong></td><td><span class="report-movement <?= $row['movement_type'] === 'in' ? 'movement-in' : 'movement-out' ?>"><?= $row['movement_type'] === 'in' ? '↗ Stock in' : '↘ Stock out' ?></span></td><td><?= e((int) $row['quantity']) ?> <?= e($row['unit']) ?></td><td class="report-muted"><?= e($row['reason']) ?></td><td class="report-muted"><?= e($row['user_name'] ?: 'System') ?></td></tr><?php endforeach; ?>
          </tbody></table></div>
        </section>

        <section class="report-panel" id="movers-panel" role="tabpanel" aria-labelledby="movers-tab" hidden>
          <form class="report-period"><div class="field-wrap"><label for="report-period">Period</label><select class="form-control" id="report-period" onchange="window.location.href='reports.php?period='+this.value+'#movers-panel'"><option value="30" <?= $period === 30 ? 'selected' : '' ?>>Last 30 days</option><option value="90" <?= $period === 90 ? 'selected' : '' ?>>Last 90 days</option><option value="180" <?= $period === 180 ? 'selected' : '' ?>>Last 180 days</option><option value="365" <?= $period === 365 ? 'selected' : '' ?>>Last 365 days</option></select></div></form>
          <div class="report-metrics"><div class="metric-card"><div class="label">Quantity issued</div><div class="value"><?= e($periodIssued) ?></div><div class="sub">In the selected period</div></div><div class="metric-card"><div class="label">Items with movement</div><div class="value"><?= e($itemsWithMovement) ?></div><div class="sub">Products issued</div></div><div class="metric-card"><div class="label">Period</div><div class="value"><?= e($period) ?> days</div><div class="sub">Selected reporting period</div></div></div>
          <div class="mover-chart report-section"><div class="report-section-title"><h2>Fastest moving items</h2><span>Quantity issued</span></div><?php if (!$fastMovers): ?><div class="report-empty">No stock-out movement in this period.</div><?php endif; ?><?php foreach ($fastMovers as $row): ?><div class="mover-bar-row"><span><?= e($row['name']) ?></span><div class="mover-bar-track"><div class="mover-bar" style="width: <?= round(((int) $row['issued'] / $maxIssued) * 100, 2) ?>%"></div></div><strong><?= e((int) $row['issued']) ?></strong></div><?php endforeach; ?></div>
          <div class="mover-tables"><div class="report-section"><div class="report-section-title"><h2>Fastest moving</h2></div><div class="report-table-wrap"><table class="report-table"><thead><tr><th>Item</th><th>Issued</th></tr></thead><tbody><?php foreach ($fastMovers as $row): ?><tr><td><?= e($row['name']) ?></td><td><strong><?= e((int) $row['issued']) ?></strong></td></tr><?php endforeach; ?></tbody></table></div></div><div class="report-section"><div class="report-section-title"><h2>Slowest movers</h2></div><div class="report-table-wrap"><table class="report-table"><thead><tr><th>Item</th><th>Issued</th></tr></thead><tbody><?php foreach ($slowMovers as $row): ?><tr><td><?= e($row['name']) ?></td><td><strong><?= e((int) $row['issued']) ?></strong></td></tr><?php endforeach; ?></tbody></table></div></div></div>
        </section>
      </div>
    </main>
  </div>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const tabs = [...document.querySelectorAll('[data-report-tab]')];
      const panels = [...document.querySelectorAll('.report-panel')];
      function activate(tab) {
        tabs.forEach((item) => { const active = item === tab; item.classList.toggle('active', active); item.setAttribute('aria-selected', active ? 'true' : 'false'); item.tabIndex = active ? 0 : -1; });
        panels.forEach((panel) => { const active = panel.id === tab.getAttribute('aria-controls'); panel.hidden = !active; panel.classList.toggle('active', active); });
      }
      tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => activate(tab));
        tab.addEventListener('keydown', (event) => { if (!['ArrowRight', 'ArrowLeft', 'Home', 'End'].includes(event.key)) return; event.preventDefault(); const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length; tabs[next].focus(); activate(tabs[next]); });
      });
      const requestedTab = new URLSearchParams(window.location.search).get('tab');
      if (window.location.hash === '#movers-panel' || requestedTab === 'movers') activate(document.querySelector('[data-report-tab="movers"]'));
      if (requestedTab === 'movement') activate(document.querySelector('[data-report-tab="movement"]'));
    });
  </script>
</body>
</html>
