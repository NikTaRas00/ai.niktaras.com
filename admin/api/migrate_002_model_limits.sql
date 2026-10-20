-- Run this ONCE in phpMyAdmin's SQL tab, BEFORE pushing the code that goes with
-- it. It is safe for the currently deployed code too: the new columns have
-- defaults and the old code keeps working until the push lands.
--
-- Adds:
--   * user_model_limits: a per-user, per-model rate limit and usage counter.
--     rate_limit NULL means "use the user's default" (users.rate_limit, which
--     the admin panel now labels "default limit"). Seeded below so every
--     existing user keeps exactly the limit they have today on every model.
--   * conversations.model / anonymous_chats.model: which model a chat belongs
--     to. Existing rows get '' (shown as "Unknown" in the admin panel).
--   * anonymous_chats is now unique per (session_id, model) instead of
--     session_id alone, so the two models stop overwriting each other's
--     anonymous chat for the same browser.
--
-- The ALTER statements are NOT safe to re-run; if one errors with "Duplicate
-- column name" or "Can't DROP", that step was already applied -- skip it.
-- A brand-new database should use each model's api/schema.sql instead.

CREATE TABLE IF NOT EXISTS user_model_limits (
  user_id INT UNSIGNED NOT NULL,
  model VARCHAR(32) NOT NULL,
  rate_limit INT UNSIGNED NULL,
  rate_count INT UNSIGNED NOT NULL DEFAULT 0,
  rate_window_start DATETIME NULL,
  PRIMARY KEY (user_id, model),
  CONSTRAINT fk_uml_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO user_model_limits (user_id, model, rate_limit, rate_count, rate_window_start)
SELECT u.id, m.model, NULL, u.rate_count, u.rate_window_start
FROM users u
CROSS JOIN (SELECT 'NKT-1' AS model UNION ALL SELECT 'NKT-2L') m;

ALTER TABLE conversations ADD COLUMN model VARCHAR(32) NOT NULL DEFAULT '' AFTER user_id;

ALTER TABLE anonymous_chats ADD COLUMN model VARCHAR(32) NOT NULL DEFAULT '' AFTER session_id;
ALTER TABLE anonymous_chats DROP INDEX session_id, ADD UNIQUE KEY uniq_session_model (session_id, model);
