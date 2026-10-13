CREATE DATABASE IF NOT EXISTS inventory_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE inventory_db;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  username VARCHAR(80) NOT NULL UNIQUE,
  email VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','staff') NOT NULL DEFAULT 'staff',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  description TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS suppliers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  contact_person VARCHAR(150) DEFAULT NULL,
  phone VARCHAR(50) DEFAULT NULL,
  email VARCHAR(150) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL,
  category_id INT NULL,
  supplier_id INT NULL,
  unit VARCHAR(40) NOT NULL,
  on_hand INT NOT NULL DEFAULT 0,
  reorder_level INT NOT NULL DEFAULT 0,
  reorder_quantity INT NOT NULL DEFAULT 0,
  cost_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_category (category_id),
  KEY idx_supplier (supplier_id),
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
  CONSTRAINT fk_products_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock_movements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  movement_type ENUM('in','out') NOT NULL,
  quantity INT NOT NULL,
  reason VARCHAR(50) NOT NULL DEFAULT 'manual',
  reference VARCHAR(100) DEFAULT NULL,
  notes TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_id INT NULL,
  KEY idx_product (product_id),
  KEY idx_created_at (created_at),
  CONSTRAINT fk_movements_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_movements_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS transactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  transaction_no VARCHAR(64) NOT NULL UNIQUE,
  customer_name VARCHAR(180) NOT NULL,
  purpose VARCHAR(100) NOT NULL DEFAULT 'sale',
  reference VARCHAR(100) DEFAULT NULL,
  transaction_date DATE NOT NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_transaction_no (transaction_no),
  CONSTRAINT fk_transactions_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS transaction_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  transaction_id INT NOT NULL,
  product_id INT NOT NULL,
  quantity INT NOT NULL,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  KEY idx_transaction (transaction_id),
  KEY idx_product (product_id),
  CONSTRAINT fk_items_transaction FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO users (name, username, email, password_hash, role)
VALUES ('Jarvs Christopher Biong', 'admin', 'admin@inventory.local', '$2y$10$FDzWnbS.4swtAj6OYkXqHOT4aSHTLXTmPedXVcOp1Qbcmqy6sY2B2', 'admin')
ON DUPLICATE KEY UPDATE username = VALUES(username);

INSERT INTO categories (id, name, description) VALUES
(1, 'Building Materials', 'Cement, steel, blocks and boards'),
(2, 'Electrical', 'Wires, breakers, lighting and conduit'),
(3, 'Plumbing', 'Pipes, fittings, valves and water lines'),
(4, 'Hardware & Fasteners', 'Nails, screws, hinges and fixings'),
(5, 'Paint & Finishes', 'Paints, thinners and painting tools'),
(6, 'Safety Equipment', 'Protective gear for site work'),
(7, 'Tools', 'Hand tools and trade equipment')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

INSERT INTO suppliers (id, name, contact_person, phone, email) VALUES
(1, 'Brightline Electrical Supply', 'Joel Santos', '+63 917 000 0005', 'support@brightline.example'),
(2, 'Metro Builders Trading', 'Liza Fernandez', '+63 917 000 0002', 'orders@metrobuilders.example'),
(3, 'Northgate Construction Supply', 'Ramon Dela Cruz', '+63 917 000 0001', 'sales@northgate.example'),
(4, 'Pacific Steel & Cement', 'Grace Lim', '+63 917 000 0004', 'trade@pacificsteel.example'),
(5, 'Summit Hardware Depot', 'Dante Reyes', '+63 917 000 0003', 'hello@summithardware.example')
ON DUPLICATE KEY UPDATE name = VALUES(name), contact_person = VALUES(contact_person), phone = VALUES(phone), email = VALUES(email);
