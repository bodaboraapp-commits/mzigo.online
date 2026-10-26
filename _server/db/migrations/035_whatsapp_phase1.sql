-- Mzigo.online — migration 035: WhatsApp Phase 1 (FAQ, tracking, handoff, admin inbox).
USE mzigo_online;

CREATE TABLE IF NOT EXISTS wa_contacts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  phone VARCHAR(20) NOT NULL,
  wa_id VARCHAR(40) NOT NULL,
  name VARCHAR(120) NULL,
  user_id BIGINT UNSIGNED NULL,
  consent_at DATETIME NULL,
  opted_out TINYINT(1) NOT NULL DEFAULT 0,
  language VARCHAR(10) NOT NULL DEFAULT 'en',
  last_seen_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_wa_contact (wa_id),
  KEY idx_wa_contact_phone (phone),
  CONSTRAINT fk_wa_contact_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wa_conversations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  contact_id BIGINT UNSIGNED NOT NULL,
  status ENUM('bot','human','closed') NOT NULL DEFAULT 'bot',
  assigned_admin_id BIGINT UNSIGNED NULL,
  last_message_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_wa_conversation_contact (contact_id),
  KEY idx_wa_conversation_status (status, last_message_at),
  CONSTRAINT fk_wa_conversation_contact FOREIGN KEY (contact_id) REFERENCES wa_contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wa_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  conversation_id BIGINT UNSIGNED NOT NULL,
  direction ENUM('inbound','outbound') NOT NULL,
  wa_message_id VARCHAR(128) NULL,
  body TEXT NOT NULL,
  message_type VARCHAR(20) NOT NULL DEFAULT 'text',
  status ENUM('received','queued','sent','failed') NOT NULL DEFAULT 'received',
  ai_model VARCHAR(80) NULL,
  error VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_wa_message_id (wa_message_id),
  KEY idx_wa_messages_conversation (conversation_id, created_at),
  CONSTRAINT fk_wa_messages_conversation FOREIGN KEY (conversation_id) REFERENCES wa_conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wa_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  message_id BIGINT UNSIGNED NOT NULL,
  status ENUM('queued','processing','done','failed') NOT NULL DEFAULT 'queued',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  error VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_wa_job_message (message_id),
  KEY idx_wa_jobs_status (status, created_at),
  CONSTRAINT fk_wa_jobs_message FOREIGN KEY (message_id) REFERENCES wa_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wa_handoffs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  conversation_id BIGINT UNSIGNED NOT NULL,
  reason VARCHAR(120) NOT NULL,
  note VARCHAR(500) NULL,
  status ENUM('open','assigned','resolved') NOT NULL DEFAULT 'open',
  assigned_admin_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  KEY idx_wa_handoffs_status (status, created_at),
  KEY idx_wa_handoffs_conversation (conversation_id, status),
  CONSTRAINT fk_wa_handoffs_conversation FOREIGN KEY (conversation_id) REFERENCES wa_conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
