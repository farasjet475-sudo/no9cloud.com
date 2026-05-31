CREATE DATABASE IF NOT EXISTS no9_cloud_system_v5 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE no9_cloud_system_v5;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS sent_messages;
DROP TABLE IF EXISTS sale_items;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS activity_logs;
DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS payment_proofs;
DROP TABLE IF EXISTS subscriptions;
DROP TABLE IF EXISTS quotations;
DROP TABLE IF EXISTS sales;
DROP TABLE IF EXISTS expenses;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS customers;
DROP TABLE IF EXISTS suppliers;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS branches;
DROP TABLE IF EXISTS plans;
DROP TABLE IF EXISTS companies;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE companies (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  company_code VARCHAR(50) NOT NULL UNIQUE,
  email VARCHAR(120) NULL,
  phone VARCHAR(40) NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE plans (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL,
  code VARCHAR(30) NOT NULL UNIQUE,
  price_monthly DECIMAL(12,2) NOT NULL DEFAULT 0,
  description VARCHAR(255) NULL,
  sort_order INT NOT NULL DEFAULT 0
);

CREATE TABLE branches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  name VARCHAR(120) NOT NULL,
  address VARCHAR(255) NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_branch_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
);

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  branch_id INT NOT NULL,
  full_name VARCHAR(120) NOT NULL,
  username VARCHAR(60) NOT NULL UNIQUE,
  email VARCHAR(120) NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('super_admin','admin','cashier') NOT NULL DEFAULT 'cashier',
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_user_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
);

CREATE TABLE customers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  branch_id INT NOT NULL,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(40) NULL,
  email VARCHAR(120) NULL,
  address TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_customer_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_customer_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
);

CREATE TABLE suppliers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  branch_id INT NOT NULL,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(40) NULL,
  email VARCHAR(120) NULL,
  address TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_supplier_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_supplier_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
);

CREATE TABLE products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  branch_id INT NOT NULL,
  name VARCHAR(160) NOT NULL,
  sku VARCHAR(80) NULL,
  category VARCHAR(100) NULL,
  price DECIMAL(12,2) NOT NULL DEFAULT 0,
  stock_qty DECIMAL(12,2) NOT NULL DEFAULT 0,
  min_stock DECIMAL(12,2) NOT NULL DEFAULT 5,
  unit VARCHAR(20) NOT NULL DEFAULT 'pcs',
  description TEXT NULL,
  image_path VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_product_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
);

CREATE TABLE expenses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  branch_id INT NOT NULL,
  category VARCHAR(100) NOT NULL,
  description TEXT NOT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  expense_date DATE NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_expense_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_expense_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
);

CREATE TABLE sales (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  branch_id INT NOT NULL,
  customer_id INT NULL,
  invoice_no VARCHAR(80) NOT NULL,
  sale_date DATE NOT NULL,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  tax DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sale_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_sale_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
  CONSTRAINT fk_sale_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
);

CREATE TABLE sale_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sale_id INT NOT NULL,
  product_id INT NULL,
  product_name VARCHAR(160) NOT NULL,
  qty DECIMAL(12,2) NOT NULL DEFAULT 1,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_sale_item_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE
);

CREATE TABLE quotations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  branch_id INT NOT NULL,
  customer_name VARCHAR(120) NOT NULL,
  quote_no VARCHAR(80) NOT NULL,
  quote_date DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  status ENUM('draft','sent','approved') NOT NULL DEFAULT 'draft',
  details LONGTEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_quote_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_quote_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
);

CREATE TABLE subscriptions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  plan_id INT NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  status ENUM('trial','active','expired','suspended') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sub_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_sub_plan FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE RESTRICT
);

CREATE TABLE payment_proofs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  subscription_id INT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  payment_method VARCHAR(60) NOT NULL,
  reference_no VARCHAR(80) NULL,
  proof_file VARCHAR(255) NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  reviewed_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_payment_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_payment_sub FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE SET NULL
);

CREATE TABLE settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  setting_key VARCHAR(80) NOT NULL,
  setting_value TEXT NULL,
  UNIQUE KEY uk_settings_company_key (company_id, setting_key),
  CONSTRAINT fk_setting_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
);

CREATE TABLE notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  type VARCHAR(40) NOT NULL DEFAULT 'system',
  title VARCHAR(160) NOT NULL,
  message TEXT NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notification_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
);

CREATE TABLE activity_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  branch_id INT NOT NULL,
  user_id INT NOT NULL,
  action VARCHAR(50) NOT NULL,
  entity VARCHAR(50) NOT NULL,
  entity_id INT NULL,
  notes VARCHAR(255) NULL,
  ip_address VARCHAR(50) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_log_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_log_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
  CONSTRAINT fk_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE sent_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  sender_user_id INT NOT NULL,
  channel ENUM('email','sms','whatsapp') NOT NULL,
  recipient VARCHAR(180) NULL,
  subject VARCHAR(180) NULL,
  message TEXT NOT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'queued',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_msg_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_msg_sender FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  code_hash VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

INSERT INTO plans(name, code, price_monthly, description, sort_order) VALUES
('Basic','basic',18,'3 branches, 10 users',1),
('Standard','standard',40,'5 branches, 18 users',2),
('Premium','premium',70,'Unlimited branches and users',3);

INSERT INTO companies(id,name,company_code,email,phone,status) VALUES
(1,'Platform Owner','SUPER','owner@example.com','000000000','active'),
(2,'No9 Demo Company','NO9DEMO','demo@example.com','0630000000','active');

INSERT INTO branches(id,company_id,name,address,status) VALUES
(1,1,'HQ','Platform Administration','active'),
(2,2,'Main Branch','Hargeisa','active'),
(3,2,'Branch B','Hargeisa','active');

INSERT INTO users(id,company_id,branch_id,full_name,username,email,password_hash,role,status) VALUES
(1,1,1,'Super Admin','superadmin','owner@example.com','$2y$12$6yGGxVXfQFsD4jD7bn9PAuAElhBL0cTNfXHA78Ko7r7XHTdjE11Sq','super_admin','active'),
(2,2,2,'Company Admin','admin','demo@example.com','$2y$12$6yGGxVXfQFsD4jD7bn9PAuAElhBL0cTNfXHA78Ko7r7XHTdjE11Sq','admin','active'),
(3,2,3,'Cashier User','cashier','cashier@example.com','$2y$12$6yGGxVXfQFsD4jD7bn9PAuAElhBL0cTNfXHA78Ko7r7XHTdjE11Sq','cashier','active');

INSERT INTO subscriptions(company_id,plan_id,start_date,end_date,status) VALUES
(2,1,CURDATE(),DATE_ADD(CURDATE(), INTERVAL 30 DAY),'active');

INSERT INTO customers(company_id,branch_id,name,phone,email,address) VALUES
(2,2,'Ahmed Retail','0631111111','ahmed@example.com','Hargeisa');

INSERT INTO products(company_id,branch_id,name,sku,category,price,stock_qty,min_stock,unit,description,image_path) VALUES
(2,2,'Football Jersey','JR-100','Sportswear',12.00,80,10,'pcs','Team jersey',NULL),
(2,2,'Training Shorts','SH-200','Sportswear',8.50,50,8,'pcs','Training short',NULL),
(2,3,'Sports Cap','CP-300','Accessories',5.00,40,5,'pcs','Cap',NULL);

INSERT INTO expenses(company_id,branch_id,category,description,amount,expense_date) VALUES
(2,2,'transport','Delivery and shipping',65,'2026-03-20'),
(2,2,'material','Packing materials',30,'2026-03-21');

INSERT INTO sales(company_id,branch_id,customer_id,invoice_no,sale_date,subtotal,tax,total_amount,notes) VALUES
(2,2,1,'INV-20260320-001','2026-03-20',24,1,25,'Paid cash');
INSERT INTO sale_items(sale_id,product_id,product_name,qty,unit_price,line_total) VALUES
(1,1,'Football Jersey',2,12,24);

INSERT INTO quotations(company_id,branch_id,customer_name,quote_no,quote_date,amount,status,details) VALUES
(2,2,'Ahmed Retail','QT-20260322-001','2026-03-22',450,'sent','[{"description":"Bulk order quotation","qty":50,"price":9,"total":450}]');

INSERT INTO settings(company_id,setting_key,setting_value) VALUES
(2,'company_name','No9 Demo Company'),
(2,'company_title','No9 Demo Company'),
(2,'company_phone','0630000000'),
(2,'company_email','demo@example.com'),
(2,'company_address','Hargeisa'),
(2,'invoice_footer','Thank you for your business.'),
(2,'currency_symbol','$'),
(2,'company_tagline','Inventory, finance, and branch management');
