-- Run this once against your cPanel MySQL database (e.g. via phpMyAdmin's
-- SQL tab). It's safe to re-run: every statement is idempotent.

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  email_verified_at DATETIME NULL,
  verification_token_hash CHAR(64) NULL,
  verification_token_expires_at DATETIME NULL,
  rate_limit INT UNSIGNED NOT NULL DEFAULT 5,
  rate_count INT UNSIGNED NOT NULL DEFAULT 0,
  rate_window_start DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS conversations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL DEFAULT 'Untitled chat',
  messages LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_conversations_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_conversations_user (user_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Anonymous (signed-out) chats, one row per browser session, so the admin
-- panel can review them. Keyed by PHP's session id, not any personal
-- identifier.
CREATE TABLE IF NOT EXISTS anonymous_chats (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id VARCHAR(191) NOT NULL UNIQUE,
  title VARCHAR(255) NOT NULL DEFAULT 'Untitled chat',
  messages LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
