-- 018: email bounce/complaint events and suppressions.
USE mzigo_online;

CREATE TABLE IF NOT EXISTS email_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  provider VARCHAR(40) NOT NULL DEFAULT 'smtp',
  event_type VARCHAR(40) NOT NULL,
  email VARCHAR(160) NOT NULL,
  reason VARCHAR(255) NULL,
  payload MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_email_events_email (email, created_at),
  KEY idx_email_events_type (event_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS email_suppressions (
  email VARCHAR(160) NOT NULL PRIMARY KEY,
  reason VARCHAR(255) NOT NULL,
  event_type VARCHAR(40) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
