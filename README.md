# AJPL-inventory-system

# Inventory Hardware & Supplies

A no-framework PHP/MySQL inventory management system modeled after the supplied mockups.

## Included modules
- Dashboard
- Products
- Categories
- Reorder Alerts
- Stock Movements
- Transactions
- Suppliers
- Demand Forecast
- Reports
- Security & Data

## Default login
- Username: admin
- Password: admin123

## Local setup with XAMPP
1. Start Apache and MySQL in XAMPP.
2. Create a database named `inventory_db` in phpMyAdmin or MySQL CLI.
3. Import `database.sql`.
4. Update database credentials in `config.php` if needed.
5. Point your document root to this project folder or copy it into `htdocs`.
6. Open `http://localhost/inventory-management-system` (or the project folder name in `htdocs`).

## File tree
- `assets/css/style.css`
- `assets/js/app.js`
- `includes/auth.php`
- `includes/functions.php`
- `categories.php`
- `dashboard.php`
- `database.sql`
- `demand_forecast.php`
- `index.php`
- `login.php`
- `logout.php`
- `products.php`
- `reorder_alerts.php`
- `reports.php`
- `security.php`
- `stock_movements.php`
- `suppliers.php`
- `transactions.php`
- `config.php`
- `README.md`
