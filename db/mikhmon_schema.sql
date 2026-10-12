-- Optional Mikhmon reporting/billing schema. The current UI stores runtime data in storage/*.json;
-- migration/import scripts use this schema. Take backups before changing an existing database.
CREATE DATABASE IF NOT EXISTS mikhmon_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE mikhmon_db;

CREATE TABLE IF NOT EXISTS customers (
  router_session VARCHAR(64) NOT NULL DEFAULT '',
  username VARCHAR(64) NOT NULL,
  name VARCHAR(255) NOT NULL DEFAULT '',
  phone VARCHAR(32) NOT NULL DEFAULT '',
  email VARCHAR(255) NOT NULL DEFAULT '',
  amount INT UNSIGNED NOT NULL DEFAULT 0,
  due_day TINYINT UNSIGNED NOT NULL DEFAULT 20,
  profile VARCHAR(64) NOT NULL DEFAULT '',
  normal_profile VARCHAR(64) NOT NULL DEFAULT '',
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  mikrotik_comment TEXT,
  last_sync DATETIME DEFAULT NULL,
  PRIMARY KEY (router_session, username),
  KEY idx_customers_username (username),
  KEY idx_customers_session (router_session),
  KEY idx_customers_profile (profile),
  CONSTRAINT chk_customers_due_day CHECK (due_day BETWEEN 1 AND 31)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoices (
  invoice_no VARCHAR(32) NOT NULL,
  period CHAR(7) NOT NULL,
  router_session VARCHAR(64) NOT NULL DEFAULT '',
  customer_name VARCHAR(255) NOT NULL DEFAULT '',
  username VARCHAR(64) NOT NULL DEFAULT '',
  product VARCHAR(128) NOT NULL DEFAULT '',
  device VARCHAR(128) NOT NULL DEFAULT '',
  customer_phone VARCHAR(32) NOT NULL DEFAULT '',
  customer_email VARCHAR(255) NOT NULL DEFAULT '',
  amount INT UNSIGNED NOT NULL DEFAULT 0,
  due_date DATE DEFAULT NULL,
  status ENUM('unpaid','pending','paid','failed','expired') NOT NULL DEFAULT 'unpaid',
  payment_method VARCHAR(32) NOT NULL DEFAULT '',
  tripay_reference VARCHAR(100) NOT NULL DEFAULT '',
  payment_url VARCHAR(1024) NOT NULL DEFAULT '',
  proof_url VARCHAR(1024) NOT NULL DEFAULT '',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  paid_at DATETIME DEFAULT NULL,
  PRIMARY KEY (invoice_no),
  KEY idx_invoices_period (period),
  KEY idx_invoices_username (username),
  KEY idx_invoices_session_user (router_session, username),
  KEY idx_invoices_due_status (due_date, status),
  KEY idx_invoices_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pppoe_usage (
  key_token VARCHAR(192) NOT NULL,
  counter_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  usage_gb DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  updated_at DATETIME DEFAULT NULL,
  PRIMARY KEY (key_token),
  KEY idx_pppoe_usage_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fonnte_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  time DATETIME NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT '',
  target VARCHAR(32) NOT NULL DEFAULT '',
  event VARCHAR(32) NOT NULL DEFAULT '',
  invoice VARCHAR(32) NOT NULL DEFAULT '',
  http_code SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  message VARCHAR(160) NOT NULL DEFAULT '',
  detail VARCHAR(500) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY idx_fonnte_time (time),
  KEY idx_fonnte_target (target),
  KEY idx_fonnte_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
