<?php
require_once __DIR__ . '/config.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_product') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $supplierId = (int) ($_POST['supplier_id'] ?? 0);
        $unit = trim($_POST['unit'] ?? '');
        $reorderLevel = (int) ($_POST['reorder_level'] ?? 0);
        $reorderQuantity = max(0, (int) ($_POST['reorder_quantity'] ?? 0));
        $costPrice = (float) ($_POST['cost_price'] ?? 0);
        $quantity = max(0, (int) ($_POST['on_hand'] ?? 0));

        if ($name === '' || $unit === '') {
            set_flash('error', 'Name and unit are required.');
        } else {
            if ($id > 0) {
                $stmt = db()->prepare('UPDATE products SET name = ?, category_id = ?, supplier_id = ?, unit = ?, reorder_level = ?, reorder_quantity = ?, cost_price = ? WHERE id = ?');
                $stmt->execute([$name, $categoryId ?: null, $supplierId ?: null, $unit, $reorderLevel, $reorderQuantity, $costPrice, $id]);
                set_flash('success', 'Product updated successfully.');
            } else {
                $stmt = db()->prepare('INSERT INTO products (name, category_id, supplier_id, unit, on_hand, reorder_level, reorder_quantity, cost_price, archived) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)');
                $stmt->execute([$name, $categoryId ?: null, $supplierId ?: null, $unit, $quantity, $reorderLevel, $reorderQuantity, $costPrice]);
                set_flash('success', 'Product added successfully.');
            }
        }
        redirect('products.php');
    }

    if ($action === 'stock_in' || $action === 'stock_out') {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $qty = (int) ($_POST['quantity'] ?? 0);
        $movementDate = $_POST['movement_date'] ?? date('Y-m-d');
        $reason = trim($_POST['reason'] ?? 'manual');
        $reference = trim($_POST['reference'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        $parsedMovementDate = DateTime::createFromFormat('!Y-m-d', $movementDate);
        if ($parsedMovementDate === false || $parsedMovementDate->format('Y-m-d') !== $movementDate) {
            set_flash('error', 'A valid movement date is required.');
        } elseif ($productId > 0 && $qty > 0) {
            db()->beginTransaction();
            try {
                $product = db()->prepare('SELECT * FROM products WHERE id = ? AND archived = 0 LIMIT 1');
                $product->execute([$productId]);
                $row = $product->fetch();
                if (!$row) {
                    throw new Exception('Product not found.');
                }

                $newStock = (int) $row['on_hand'];
                if ($action === 'stock_out') {
                    $newStock -= $qty;
                    if ($newStock < 0) {
                        throw new Exception('Not enough stock for this movement.');
                    }
                } else {
                    $newStock += $qty;
                }

                db()->prepare('UPDATE products SET on_hand = ? WHERE id = ?')->execute([$newStock, $productId]);
                db()->prepare('INSERT INTO stock_movements (product_id, movement_type, quantity, reason, reference, notes, created_at, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                    $productId,
                    $action === 'stock_in' ? 'in' : 'out',
                    $qty,
                    $reason,
                    $reference,
                    $notes,
                    $movementDate . ' 00:00:00',
                    $_SESSION['user_id']
                ]);
                db()->commit();
                set_flash('success', 'Stock movement recorded successfully.');
            } catch (Exception $e) {
                db()->rollBack();
                set_flash('error', $e->getMessage());
            }
        } else {
            set_flash('error', 'Quantity must be greater than zero.');
        }
        redirect('products.php');
    }

    if ($action === 'archive_product') {
        $id = (int) ($_POST['id'] ?? 0);
        db()->prepare('UPDATE products SET archived = 1 WHERE id = ?')->execute([$id]);
        set_flash('success', 'Product archived.');
        redirect('products.php');
    }
}

if (isset($_GET['export']) && $_GET['export'] == '1') {
    $query = 'SELECT p.name, c.name AS category, p.unit, p.on_hand, p.reorder_level, p.cost_price, (p.cost_price * p.on_hand) AS stock_value FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.archived = 0';
    $rows = db()->query($query)->fetchAll();
    $out = fopen('php://output', 'w');
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="inventory_products.csv"');
    fputcsv($out, ['Name', 'Category', 'Unit', 'On Hand', 'Reorder Level', 'Cost Price', 'Stock Value']);
    foreach ($rows as $row) {
        fputcsv($out, [$row['name'], $row['category'], $row['unit'], $row['on_hand'], $row['reorder_level'], $row['cost_price'], $row['stock_value']]);
    }
    fclose($out);
    exit;
}

$stockProducts = db()->query('SELECT id, name, unit, on_hand FROM products WHERE archived = 0 ORDER BY name ASC')->fetchAll();
$search = trim($_GET['q'] ?? '');
$categoryFilter = (int) ($_GET['category'] ?? 0);
$statusFilter = $_GET['status'] ?? '';

$sql = 'SELECT p.*, c.name AS category_name, s.name AS supplier_name FROM products p LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN suppliers s ON s.id = p.supplier_id WHERE p.archived = 0';
$params = [];
if ($search !== '') {
    $sql .= ' AND p.name LIKE ?';
    $term = '%' . $search . '%';
    $params[] = $term;
}
if ($categoryFilter > 0) {
    $sql .= ' AND p.category_id = ?';
    $params[] = $categoryFilter;
}
if ($statusFilter !== '' && $statusFilter !== 'all') {
    if ($statusFilter === 'in') {
        $sql .= ' AND p.on_hand > p.reorder_level';
    } elseif ($statusFilter === 'low') {
        $sql .= ' AND p.on_hand <= p.reorder_level AND p.on_hand > 0';
    } else {
        $sql .= ' AND p.on_hand <= 0';
    }
}
$sql .= ' ORDER BY p.name ASC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$categories = db()->query('SELECT * FROM categories ORDER BY name ASC')->fetchAll();
$editingProduct = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $prod = db()->prepare('SELECT * FROM products WHERE id = ? AND archived = 0 LIMIT 1');
    $prod->execute([(int) $_GET['edit']]);
    $editingProduct = $prod->fetch();
}

$user = inventory_get_current_user();
$flashSuccess = get_flash('success');
$flashError = get_flash('error');

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Products | Inventory</title>
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
        <a class="nav-link active" href="products.php"><span class="icon">◫</span>Products</a>
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
        <div class="page-title">Products</div>
        <div class="user-box">
          <div class="user-avatar"><?= strtoupper(substr($user['name'], 0, 1)) ?></div>
          <div class="user-meta"><strong><?= e($user['name']) ?></strong><small><?= e(strtoupper($user['role'])) ?></small></div>
          <a class="btn icon-btn" href="logout.php" title="Logout">⎋</a>
        </div>
      </header>

      <div class="content">
        <div class="page-head">
          <div class="head-left"><h1>Products</h1><p>Your full catalog with live stock levels, reorder points and valuation.</p></div>
          <div class="header-actions">
            <a href="products.php?export=1" class="btn">Export CSV</a>
            <button class="btn btn-primary" data-open-modal="productModal">+ Add product</button>
          </div>
        </div>

        <div class="filter-panel">
          <div class="field-wrap search-box">
            <label>Find a product</label>
            <form method="get" action="products.php" style="position:relative;">
              <span class="search-icon">⌕</span>
              <input class="form-control" type="text" name="q" value="<?= e($search) ?>" placeholder="Search by product name">
            </form>
          </div>
          <div class="field-wrap">
            <label>Category</label>
            <form method="get" action="products.php" style="display:block;">
              <select class="form-control" name="category" onchange="this.form.submit()">
                <option value="0">All categories</option>
                <?php foreach ($categories as $category): ?>
                  <option value="<?= (int) $category['id'] ?>" <?= ((int) $categoryFilter === (int) $category['id']) ? 'selected' : '' ?>><?= e($category['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
          </div>
          <div class="field-wrap">
            <label>Stock status</label>
            <form method="get" action="products.php" style="display:block;">
              <select class="form-control" name="status" onchange="this.form.submit()">
                <option value="all" <?= ($statusFilter === '' || $statusFilter === 'all') ? 'selected' : '' ?>>All active items</option>
                <option value="in" <?= ($statusFilter === 'in') ? 'selected' : '' ?>>In stock</option>
                <option value="low" <?= ($statusFilter === 'low') ? 'selected' : '' ?>>Low stock</option>
                <option value="out" <?= ($statusFilter === 'out') ? 'selected' : '' ?>>Out of stock</option>
              </select>
            </form>
          </div>
        </div>

        <div class="table-card">
          <table>
            <thead>
              <tr>
                <th>Item</th>
                <th>Category</th>
                <th>On hand</th>
                <th>Reorder at</th>
                <th>Cost</th>
                <th>Stock value</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$products): ?>
                <tr><td colspan="7"><div class="empty-state">No products match the current filters.</div></td></tr>
              <?php else: ?>
                <?php foreach ($products as $product): ?>
                  <tr>
                    <td>
                      <div style="display:flex; flex-direction:column; gap:6px;">
                        <strong><?= e($product['name']) ?></strong>
                        <span class="status-badge <?= get_product_status_class($product) ?>"><?= e(get_product_status($product)) ?></span>
                      </div>
                    </td>
                    <td><?= e($product['category_name'] ?: 'Uncategorized') ?></td>
                    <td><?= e((int) $product['on_hand']) ?> <?= e($product['unit']) ?></td>
                    <td><?= e((int) $product['reorder_level']) ?></td>
                    <td><?= format_currency($product['cost_price']) ?></td>
                    <td><?= format_currency(product_total_value($product)) ?></td>
                    <td>
                      <div class="inline-row" style="flex-wrap: wrap; row-gap: 8px;">
                        <button class="btn stock-movement-trigger" type="button" data-direction="in" data-product-id="<?= (int) $product['id'] ?>" data-product-name="<?= e($product['name']) ?>" data-product-stock="<?= e((int) $product['on_hand']) ?>" data-product-unit="<?= e($product['unit']) ?>" style="padding: 6px 10px;">In</button>
                        <button class="btn stock-movement-trigger" type="button" data-direction="out" data-product-id="<?= (int) $product['id'] ?>" data-product-name="<?= e($product['name']) ?>" data-product-stock="<?= e((int) $product['on_hand']) ?>" data-product-unit="<?= e($product['unit']) ?>" style="padding: 6px 10px;">Out</button>
                        <div class="row-menu-wrap">
                          <button type="button" class="icon-btn row-menu-toggle" aria-label="Product actions">⋮</button>
                          <div class="row-menu">
                            <a href="products.php?edit=<?= (int) $product['id'] ?>"><span class="menu-icon">✎</span><span>Edit product</span></a>
                            <form method="post" action="products.php" onsubmit="return confirm('Archive this product?');">
                              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                              <input type="hidden" name="action" value="archive_product">
                              <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
                              <button type="submit"><span class="menu-icon">🗑</span><span>Delete</span></button>
                            </form>
                          </div>
                        </div>
                      </div>
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

  <div class="modal <?= $editingProduct ? 'open' : '' ?>" id="productModal">
    <div class="modal-card product-edit-card">
      <div class="modal-head">
        <h3><?= $editingProduct ? 'Edit product' : 'Add product' ?></h3>
        <button class="modal-close" type="button" aria-label="Close">×</button>
      </div>
      <div class="modal-body">
        <p class="modal-subtitle product-edit-subtitle">Set the reorder point so low-stock alerts fire automatically.</p>
        <form method="post" action="products.php">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="save_product">
          <input type="hidden" name="id" value="<?= (int) ($editingProduct['id'] ?? 0) ?>">
          <div class="form-grid product-edit-grid">
            <div class="field-wrap product-name-field">
              <label>Product name</label>
              <input class="form-control" name="name" value="<?= e($editingProduct['name'] ?? '') ?>" required>
            </div>
            <div class="field-wrap">
              <label>Category</label>
              <select class="form-control" name="category_id">
                <option value="0">Uncategorized</option>
                <?php foreach ($categories as $category): ?>
                  <option value="<?= (int) $category['id'] ?>" <?= (($editingProduct['category_id'] ?? 0) == $category['id']) ? 'selected' : '' ?>><?= e($category['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field-wrap">
              <label>Supplier</label>
              <select class="form-control" name="supplier_id">
                <option value="0">No supplier</option>
                <?php foreach (get_suppliers() as $supplier): ?>
                  <option value="<?= (int) $supplier['id'] ?>" <?= (($editingProduct['supplier_id'] ?? 0) == $supplier['id']) ? 'selected' : '' ?>><?= e($supplier['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field-wrap">
              <label>Unit of measure</label>
              <select class="form-control" name="unit" required>
                <?php foreach (['pcs', 'pack', 'box', 'set', 'kg', 'litre'] as $unit): ?>
                  <option value="<?= e($unit) ?>" <?= (($editingProduct['unit'] ?? 'pcs') === $unit) ? 'selected' : '' ?>><?= e($unit) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field-wrap">
              <label>Cost price</label>
              <input class="form-control" type="number" step="0.01" name="cost_price" value="<?= e($editingProduct['cost_price'] ?? '0.00') ?>">
            </div>
            <div class="field-wrap">
              <label>Selling price</label>
              <input class="form-control" type="number" step="0.01"  name="selling_price" value="<?= e($editingProduct['selling_price'] ?? $editingProduct['cost_price'] ?? '0.00') ?>">
            </div>
            <div class="field-wrap">
              <label>Reorder at</label>
              <input class="form-control" type="number"min="0" name="reorder_level" value="<?= e((int) ($editingProduct['reorder_level'] ?? 0)) ?>">
            </div>
            <div class="field-wrap">
              <label>Reorder quantity</label>
              <input class="form-control" type="number" min="0" name="reorder_quantity" value="<?= e((int) ($editingProduct['reorder_quantity'] ?? 0)) ?>">
            </div>
            <?php if (!$editingProduct): ?>
              <div class="field-wrap">
                <label>Opening quantity</label>
                <input class="form-control" type="number" name="on_hand" value="0">
              </div>
            <?php endif; ?>
          </div>
          <div class="form-actions">
            <button type="button" class="btn modal-close">Cancel</button>
            <button type="submit" class="btn btn-primary"><?= $editingProduct ? 'Save changes' : 'Add product' ?></button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal" id="stockMovementModal">
    <div class="modal-card stock-movement-card">
      <div class="modal-head">
        <h3>Record stock movement</h3>
        <button class="modal-close" type="button" aria-label="Close">✕</button>
      </div>
      <div class="modal-body">
        <p class="modal-subtitle">Stock levels update the moment this is saved.</p>
        <form method="post" action="products.php" id="stockMovementForm">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" id="stockMovementAction" value="stock_in">

          <div class="stock-toggle" role="tablist" aria-label="Stock movement type">
            <button type="button" class="stock-toggle-btn active" data-direction="in" aria-selected="true">↗ Stock in</button>
            <button type="button" class="stock-toggle-btn" data-direction="out" aria-selected="false">↘ Stock out</button>
          </div>

          <div class="form-grid stock-movement-grid">
            <div class="field-wrap full">
              <label>Product</label>
              <div class="stock-product-picker">
                <div class="stock-product-summary" id="stockProductSummary">
                  <strong>Select product</strong>
                  <span>Choose an active product</span>
                </div>
                <button type="button" class="stock-product-clear" id="stockProductClear" aria-label="Clear product">×</button>
              </div>
              <select class="form-control stock-product-select" name="product_id" id="stockMovementProduct" required>
                <option value="">Select product</option>
                <?php foreach ($stockProducts as $stockProduct): ?>
                  <option value="<?= (int) $stockProduct['id'] ?>" data-name="<?= e($stockProduct['name']) ?>" data-stock="<?= e((int) $stockProduct['on_hand']) ?>" data-unit="<?= e($stockProduct['unit']) ?>"><?= e($stockProduct['name']) ?> - <?= e((int) $stockProduct['on_hand']) ?> <?= e($stockProduct['unit']) ?> on hand</option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field-wrap">
              <label>Quantity (pack)</label>
              <input class="form-control" type="number" min="1" name="quantity" value="1" required>
            </div>
            <div class="field-wrap">
              <label>Date</label>
              <input class="form-control" type="date" name="movement_date" value="<?= date('Y-m-d') ?>">
            </div>

            <div class="field-wrap full">
              <label>Reason</label>
              <select class="form-control" name="reason">
                <option value="stock_count">Stock count</option>
                <option value="purchase">Purchase</option>
                <option value="sale">Sale</option>
                <option value="return">Return</option>
                <option value="damage">Damage</option>
                <option value="adjustment">Adjustment</option>
              </select>
            </div>  

            <div class="field-wrap">
              <label>Reference no.</label>
              <input class="form-control" type="text" name="reference" placeholder="DR / invoice / job no.">
            </div>
            <div class="field-wrap">
              <label>Note</label>
              <input class="form-control" type="text" name="notes" placeholder="Optional">
            </div>
          </div>

          <div class="form-actions stock-form-actions">
            <button type="button" class="btn modal-close">Cancel</button>
            <button type="submit" class="btn btn-primary">Save movement</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="assets/js/app.js?v=2"></script>
</body>
</html>
