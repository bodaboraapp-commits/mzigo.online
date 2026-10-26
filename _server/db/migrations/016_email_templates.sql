-- Mzigo.online — SaaS migration 016: editable customer-journey email templates.
USE mzigo_online;

CREATE TABLE IF NOT EXISTS email_templates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  template_key VARCHAR(60) NOT NULL,
  label VARCHAR(120) NOT NULL,
  stage VARCHAR(40) NOT NULL DEFAULT 'General',
  recipient VARCHAR(120) NOT NULL DEFAULT 'Customer',
  trigger_text VARCHAR(190) NULL,
  subject VARCHAR(190) NULL,
  body_text TEXT NULL,
  body_html MEDIUMTEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  is_system TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_email_template_key (template_key),
  KEY idx_email_template_stage (stage)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
