<?php
require_once __DIR__ . '/config.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'record_movement') {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $movementType = $_POST['movement_type'] ?? 'in';
        $qty = (int) ($_POST['quantity'] ?? 0);
    $movementDate = $_POST['movement_date'] ?? date('Y-m-d');
    $reason = trim($_POST['reason'] ?? 'manual');
    $reference = trim($_POST['reference'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    $parsedMovementDate = DateTime::createFromFormat('!Y-m-d', $movementDate);
    if (!in_array($movementType, ['in', 'out'], true) || $parsedMovementDate === false || $parsedMovementDate->format('Y-m-d') !== $movementDate) {
        set_flash('error', 'A valid movement type and date are required.');
        redirect('stock_movements.php');
    }
    if ($productId <= 0 || $qty <= 0) {
        set_flash('error', 'A valid product and quantity are required.');
        redirect('stock_movements.php');
    }

        db()->beginTransaction();
        try {
            $product = db()->prepare('SELECT * FROM products WHERE id = ? AND archived = 0 LIMIT 1');
            $product->execute([$productId]);
            $row = $product->fetch();
            if (!$row) throw new Exception('Product not found.');

            $newQty = (int) $row['on_hand'];
            if ($movementType === 'out') {
                $newQty -= $qty;
                if ($newQty < 0) {
                    throw new Exception('Insufficient stock for this movement.');
                }
            } else {
                $newQty += $qty;
            }

            db()->prepare('UPDATE products SET on_hand = ? WHERE id = ?')->execute([$newQty, $productId]);
            db()->prepare('INSERT INTO stock_movements (product_id, movement_type, quantity, reason, reference, notes, created_at, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                $productId,
                $movementType,
                $qty,
                $reason,
                $reference,
                $notes,
                $movementDate . ' 00:00:00',
                $_SESSION['user_id']
            ]);
            db()->commit();
            set_flash('success', 'Stock movement added successfully.');
        } catch (Exception $e) {
            db()->rollBack();
            set_flash('error', $e->getMessage());
        }
        redirect('stock_movements.php');
    }
}

if (isset($_GET['export']) && $_GET['export'] == '1') {
    $where = ['1=1'];
    $params = [];
    if (!empty($_GET['product'])) {
        $where[] = 'sm.product_id = ?';
        $params[] = (int) $_GET['product'];
    }
    if (!empty($_GET['type']) && $_GET['type'] !== 'all') {
        $where[] = 'sm.movement_type = ?';
        $params[] = $_GET['type'];
    }
    if (!empty($_GET['from'])) {
        $where[] = 'sm.created_at >= ?';
        $params[] = $_GET['from'] . ' 00:00:00';
    }
    if (!empty($_GET['to'])) {
        $where[] = 'sm.created_at <= ?';
        $params[] = $_GET['to'] . ' 23:59:59';
    }

    $sql = 'SELECT sm.created_at, p.name, sm.movement_type, sm.quantity, sm.reason, sm.reference, sm.notes FROM stock_movements sm LEFT JOIN products p ON p.id=sm.product_id WHERE ' . implode(' AND ', $where) . ' ORDER BY sm.created_at DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $out = fopen('php://output', 'w');
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="stock_movements.csv"');
    fputcsv($out, ['Date', 'Item', 'Type', 'Qty', 'Reason', 'Reference', 'Notes']);
    foreach ($rows as $row) {
        fputcsv($out, [$row['created_at'], $row['name'], $row['movement_type'], $row['quantity'], $row['reason'], $row['reference'], $row['notes']]);
    }
    fclose($out);
    exit;
}

$productFilter = (int) ($_GET['product'] ?? 0);
$typeFilter = $_GET['type'] ?? 'all';
$fromFilter = $_GET['from'] ?? '';
$toFilter = $_GET['to'] ?? '';

$where = ['1=1'];
$params = [];
if ($productFilter > 0) { $where[] = 'sm.product_id = ?'; $params[] = $productFilter; }
if ($typeFilter !== 'all' && $typeFilter !== '') { $where[] = 'sm.movement_type = ?'; $params[] = $typeFilter; }
if ($fromFilter !== '') { $where[] = 'sm.created_at >= ?'; $params[] = $fromFilter . ' 00:00:00'; }
if ($toFilter !== '') { $where[] = 'sm.created_at <= ?'; $params[] = $toFilter . ' 23:59:59'; }

$sql = 'SELECT sm.*, p.name AS product_name, p.unit FROM stock_movements sm LEFT JOIN products p ON p.id = sm.product_id WHERE ' . implode(' AND ', $where) . ' ORDER BY sm.created_at DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$movements = $stmt->fetchAll();

$receivedTotal = 0; $issuedTotal = 0;
foreach ($movements as $m) {
    if ($m['movement_type'] === 'in') { $receivedTotal += (int) $m['quantity']; }
    if ($m['movement_type'] === 'out') { $issuedTotal += (int) $m['quantity']; }
}

$products = db()->query('SELECT * FROM products WHERE archived = 0 ORDER BY name ASC')->fetchAll();
$user = inventory_get_current_user();
$flashSuccess = get_flash('success');
$flashError = get_flash('error');

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Stock Movements | Inventory</title>
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
        <a class="nav-link active" href="stock_movements.php"><span class="icon">↗</span>Stock Movements</a>
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
        <div class="page-title">Stock movements</div>
        <div class="user-box">
          <div class="user-avatar"><?= strtoupper(substr($user['name'], 0, 1)) ?></div>
          <div class="user-meta"><strong><?= e($user['name']) ?></strong><small><?= e(strtoupper($user['role'])) ?></small></div>
          <a class="btn icon-btn" href="logout.php" title="Logout">⎋</a>
        </div>
      </header>

      <div class="content">
        <div class="page-head">
          <div class="head-left"><h1>Stock movements</h1><p>Every stock-in and stock-out with who recorded it, the reason and the resulting balance.</p></div>
          <div class="header-actions">
            <a href="stock_movements.php?export=1&product=<?= e($productFilter) ?>&type=<?= e($typeFilter) ?>&from=<?= e($fromFilter) ?>&to=<?= e($toFilter) ?>" class="btn">Export CSV</a>
            <button class="btn btn-primary" data-open-modal="movementModal">+ Record movement</button>
          </div>
        </div>

        <div class="filter-panel" style="grid-template-columns: 1.3fr 1fr 1fr 1fr;">
          <div class="field-wrap search-box">
            <label>Product</label>
            <form method="get" action="stock_movements.php" style="display:block;">
              <select class="form-control" name="product" onchange="this.form.submit()">
                <option value="0">All products</option>
                <?php foreach ($products as $product): ?>
                  <option value="<?= (int) $product['id'] ?>" <?= ($productFilter === (int) $product['id']) ? 'selected' : '' ?>><?= e($product['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
          </div>
          <div class="field-wrap">
            <label>Movement type</label>
            <form method="get" action="stock_movements.php" style="display:block;">
              <select class="form-control" name="type" onchange="this.form.submit()">
                <option value="all" <?= ($typeFilter === 'all' || $typeFilter === '') ? 'selected' : '' ?>>All movements</option>
                <option value="in" <?= ($typeFilter === 'in') ? 'selected' : '' ?>>Stock in</option>
                <option value="out" <?= ($typeFilter === 'out') ? 'selected' : '' ?>>Stock out</option>
              </select>
            </form>
          </div>
          <div class="field-wrap">
            <label>Date range</label>
            <form method="get" action="stock_movements.php" style="display:block;">
              <input class="form-control" type="date" name="from" value="<?= e($fromFilter) ?>" onchange="this.form.submit()">
            </form>
          </div>
          <div class="field-wrap">
            <label>To</label>
            <form method="get" action="stock_movements.php" style="display:block;">
              <input class="form-control" type="date" name="to" value="<?= e($toFilter) ?>" onchange="this.form.submit()">
            </form>
          </div>
        </div>

        <div class="kpi-inline" style="margin-bottom:18px;">
          <div class="small-stats"><span class="k">Total received</span><span class="v"><?= e($receivedTotal) ?></span></div>
          <div class="small-stats"><span class="k">Total issued</span><span class="v"><?= e($issuedTotal) ?></span></div>
        </div>

        <div class="table-card">
          <table>
            <thead>
              <tr>
                <th>Date</th>
                <th>Item</th>
                <th>Type</th>
                <th>Qty</th>
                <th>Reason</th>
                <th>Reference</th>
                <th>Balance</th>
                <th>Recorded by</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$movements): ?>
                <tr><td colspan="8"><div class="empty-state">No stock movements match the current filters.</div></td></tr>
              <?php else: ?>
                <?php foreach ($movements as $movement): ?>
                  <?php
                    $current = db()->prepare('SELECT on_hand FROM products WHERE id = ? LIMIT 1');
                    $current->execute([$movement['product_id']]);
                    $balanceValue = (int) ($current->fetch()['on_hand'] ?? 0);
                  ?>
                  <tr>
                    <td><?= format_date($movement['created_at']) ?></td>
                    <td><?= e($movement['product_name']) ?></td>
                    <td><span class="status-badge <?= ($movement['movement_type'] === 'in' ? 'in' : 'out') ?>"><?= e(strtoupper($movement['movement_type'])) ?></span></td>
                    <td><?= e($movement['quantity']) ?></td>
                    <td><?= e($movement['reason']) ?></td>
                    <td><?= e($movement['reference'] ?: '—') ?></td>
                    <td><?= e($balanceValue) ?></td>
                    <td><?= e($movement['user_id'] ? 'System' : 'System') ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </main>
  </div>

  <div class="modal" id="movementModal">
    <div class="modal-card">
      <div class="modal-head"><h3>Record stock movement</h3><button class="modal-close" type="button">✕</button></div>
      <div class="modal-body">
        <form method="post" action="stock_movements.php">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="record_movement">
          <div class="form-grid">
            <div class="field-wrap">
              <label>Product</label>
              <select class="form-control" name="product_id" required>
                <option value="">Select product</option>
                <?php foreach ($products as $product): ?>
                  <option value="<?= (int) $product['id'] ?>"><?= e($product['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field-wrap">
              <label>Movement type</label>
              <select class="form-control" name="movement_type">
                <option value="in">Stock in</option>
                <option value="out">Stock out</option>
              </select>
            </div>
            <div class="field-wrap">
              <label>Quantity</label>
              <input class="form-control" type="number" min="1" name="quantity" required>
            </div>
            <div class="field-wrap">
              <label>Date</label>
              <input class="form-control" type="date" name="movement_date" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="field-wrap">
              <label>Reason</label>
              <input class="form-control" name="reason" value="manual" required>
            </div>
            <div class="field-wrap full">
              <label>Reference</label>
              <input class="form-control" name="reference">
            </div>
            <div class="field-wrap full">
              <label>Notes</label>
              <textarea class="form-control" name="notes" rows="3"></textarea>
            </div>
          </div>
          <div class="form-actions"><button type="button" class="btn modal-close">Cancel</button><button type="submit" class="btn btn-primary">Save movement</button></div>
        </form>
      </div>
    </div>
  </div>

  <script src="assets/js/app.js"></script>
</body>
</html>
