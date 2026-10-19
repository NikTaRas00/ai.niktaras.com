-- Run this ONCE against your existing database to add the admin-panel
-- features (per-account rate limits + anonymous chat logging) to a database
-- that was already set up from an earlier version of api/schema.sql.
-- Unlike schema.sql, these ALTER TABLE statements are NOT safe to re-run --
-- if a column already exists, MySQL will error on that line (harmless, just
-- skip it and run the rest). A brand-new database should use schema.sql
-- instead, which already includes these columns/table.

ALTER TABLE users ADD COLUMN rate_limit INT UNSIGNED NOT NULL DEFAULT 5;
ALTER TABLE users ADD COLUMN rate_count INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN rate_window_start DATETIME NULL;

CREATE TABLE IF NOT EXISTS anonymous_chats (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id VARCHAR(191) NOT NULL UNIQUE,
  title VARCHAR(255) NOT NULL DEFAULT 'Untitled chat',
  messages LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
