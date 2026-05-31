-- No9 Cloud System - Bank Module
-- Import this in phpMyAdmin after backing up your database.

CREATE TABLE IF NOT EXISTS banks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  branch_id INT DEFAULT NULL,
  bank_name VARCHAR(150) NOT NULL,
  account_number VARCHAR(100) DEFAULT NULL,
  account_holder VARCHAR(150) DEFAULT NULL,
  currency VARCHAR(10) DEFAULT 'USD',
  opening_balance DECIMAL(18,2) DEFAULT 0.00,
  current_balance DECIMAL(18,2) DEFAULT 0.00,
  status ENUM('active','inactive') DEFAULT 'active',
  created_by INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_banks_company (company_id),
  INDEX idx_banks_branch (branch_id),
  INDEX idx_banks_status (status)
);

CREATE TABLE IF NOT EXISTS bank_transactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  branch_id INT DEFAULT NULL,
  bank_id INT NOT NULL,
  type ENUM('deposit','withdraw','transfer_in','transfer_out','sale','expense','invoice_payment','adjustment') NOT NULL,
  amount DECIMAL(18,2) NOT NULL,
  currency VARCHAR(10) DEFAULT 'USD',
  reference VARCHAR(150) DEFAULT NULL,
  description TEXT DEFAULT NULL,
  transaction_date DATE NOT NULL,
  related_table VARCHAR(80) DEFAULT NULL,
  related_id INT DEFAULT NULL,
  created_by INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_bt_company (company_id),
  INDEX idx_bt_branch (branch_id),
  INDEX idx_bt_bank (bank_id),
  INDEX idx_bt_date (transaction_date),
  CONSTRAINT fk_bank_transactions_bank
    FOREIGN KEY (bank_id) REFERENCES banks(id)
    ON DELETE CASCADE
);
