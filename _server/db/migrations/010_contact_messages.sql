-- Mzigo.online — SaaS migration 010: contact-form inbox.
--
-- Submissions from the public /contact page are stored here and managed by the
-- platform admin (Admin console → Messages).

USE mzigo_online;

CREATE TABLE IF NOT EXISTS contact_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(160) NOT NULL,
  phone VARCHAR(30) NULL,
  subject VARCHAR(160) NOT NULL,
  message TEXT NOT NULL,
  status ENUM('new','read','replied','archived') NOT NULL DEFAULT 'new',
  admin_note VARCHAR(500) NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_contact_status (status),
  KEY idx_contact_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
