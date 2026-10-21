CREATE DATABASE IF NOT EXISTS erp_master CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE erp_master;

CREATE TABLE IF NOT EXISTS plans (
  id INT AUTO_INCREMENT PRIMARY KEY,
  plan_name VARCHAR(60) NOT NULL,
  modules VARCHAR(500) NULL,            -- comma separated, khali = shob module
  max_users INT NOT NULL DEFAULT 0,     -- 0 = unlimited
  monthly_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  yearly_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  status VARCHAR(10) NOT NULL DEFAULT 'Active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tenants (
  id INT AUTO_INCREMENT PRIMARY KEY,
  subdomain VARCHAR(40) NOT NULL UNIQUE,
  custom_domain VARCHAR(120) NULL UNIQUE,
  company_name VARCHAR(150) NOT NULL,
  db_host VARCHAR(100) NOT NULL DEFAULT 'localhost',
  db_name VARCHAR(64) NOT NULL,
  db_user VARCHAR(64) NOT NULL,
  db_pass_enc TEXT NOT NULL,
  plan_id INT NULL,
  max_users INT NOT NULL DEFAULT 0,
  billing_cycle VARCHAR(10) NOT NULL DEFAULT 'Monthly',
  subscription_end DATE NULL,
  grace_days INT NOT NULL DEFAULT 0,
  status VARCHAR(12) NOT NULL DEFAULT 'Active',
  phone VARCHAR(40) NULL,
  email VARCHAR(120) NULL,
  contact_person VARCHAR(100) NULL,
  notes VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_plan (plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tenant_payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  method VARCHAR(40) NULL,
  reference VARCHAR(80) NULL,
  note VARCHAR(255) NULL,
  period_from DATE NULL,
  period_to DATE NULL,
  paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO plans (plan_name, modules, max_users, monthly_price, yearly_price) VALUES ('Full', NULL, 0, 1500, 15000);

-- web er SELECT-only user (erp_env.php te ja ache)
-- CREATE USER 'erp_master'@'localhost' IDENTIFIED BY '<notun password>';
-- GRANT SELECT ON erp_master.* TO 'erp_master'@'localhost';