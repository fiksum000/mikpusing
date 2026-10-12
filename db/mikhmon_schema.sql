-- Mikhmon v3 Database Schema (MariaDB/MySQL)
-- Untuk skala 1000+ pelanggan PPPoE / Hotspot
CREATE DATABASE IF NOT EXISTS mikhmon_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE mikhmon_db;

CREATE TABLE IF NOT EXISTS customers (
  username VARCHAR(64) PRIMARY KEY,
  name VARCHAR(255),
  phone VARCHAR(32),
  email VARCHAR(255),
  amount INT DEFAULT 0,
  due_day TINYINT DEFAULT 20,
  router_session VARCHAR(64),
  profile VARCHAR(32),
  normal_profile VARCHAR(32),
  status ENUM('active','disabled') DEFAULT 'active',
  mikrotik_comment TEXT,
  last_sync DATETIME,
  INDEX idx_router_session (router_session),
  INDEX idx_profile (profile)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS invoices (
  invoice_no VARCHAR(32) PRIMARY KEY,
  period VARCHAR(7),
  customer_name VARCHAR(255),
  username VARCHAR(64),
  product VARCHAR(64),
  device VARCHAR(64),
  customer_phone VARCHAR(32),
  customer_email VARCHAR(255),
  amount INT DEFAULT 0,
  due_date DATE,
  status ENUM('unpaid','paid','pending') DEFAULT 'unpaid',
  payment_method VARCHAR(32),
  tripay_reference VARCHAR(64),
  proof_url VARCHAR(255),
  created_at DATETIME,
  paid_at DATETIME,
  INDEX idx_period (period),
  INDEX idx_username (username),
  INDEX idx_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pppoe_usage (
  key_token VARCHAR(128) PRIMARY KEY,
  counter_bytes BIGINT DEFAULT 0,
  usage_gb DECIMAL(10,2) DEFAULT 0.00,
  updated_at DATETIME,
  INDEX idx_period (key_token)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS fonnte_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  time DATETIME,
  status VARCHAR(32),
  target VARCHAR(32),
  event VARCHAR(32),
  invoice VARCHAR(32),
  http_code SMALLINT,
  message VARCHAR(160),
  detail VARCHAR(500),
  INDEX idx_time (time),
  INDEX idx_target (target),
  INDEX idx_status (status)
) ENGINE=InnoDB;
