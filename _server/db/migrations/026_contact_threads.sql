-- Mzigo.online — migration 026: contact inbox notes and direct replies.
USE mzigo_online;

CREATE TABLE IF NOT EXISTS contact_message_notes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  message_id BIGINT UNSIGNED NOT NULL,
  admin_id BIGINT UNSIGNED NULL,
  note TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_contact_notes_message (message_id, created_at),
  CONSTRAINT fk_contact_notes_message FOREIGN KEY (message_id) REFERENCES contact_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS contact_message_replies (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  message_id BIGINT UNSIGNED NOT NULL,
  admin_id BIGINT UNSIGNED NULL,
  channel VARCHAR(20) NOT NULL,
  recipient VARCHAR(160) NOT NULL,
  body TEXT NOT NULL,
  status ENUM('sent','skipped','failed') NOT NULL DEFAULT 'sent',
  error VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_contact_replies_message (message_id, created_at),
  CONSTRAINT fk_contact_replies_message FOREIGN KEY (message_id) REFERENCES contact_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO contact_message_notes (message_id, admin_id, note, created_at)
SELECT id, NULL, admin_note, updated_at FROM contact_messages
WHERE admin_note IS NOT NULL AND TRIM(admin_note) <> ''
  AND NOT EXISTS (SELECT 1 FROM contact_message_notes n WHERE n.message_id = contact_messages.id);
