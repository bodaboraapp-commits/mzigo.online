-- Mzigo.online — SaaS migration 001
-- Multi-tenancy, plans/billing/invoices, team memberships, API keys, audit log,
-- rate limiting. Existing demo rows keep tenant_id NULL (platform demo parcels).

USE mzigo_online;

-- ------------------------------------------------------------------ accounts
ALTER TABLE users
  ADD COLUMN email VARCHAR(160) NULL AFTER phone,
  ADD COLUMN password_hash VARCHAR(255) NULL AFTER email,
  ADD COLUMN role ENUM('user','platform_admin') NOT NULL DEFAULT 'user' AFTER password_hash,
  ADD UNIQUE KEY uq_users_email (email);

-- ------------------------------------------------------------------ tenants
CREATE TABLE IF NOT EXISTS tenants (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug       VARCHAR(80)  NOT NULL,
  name       VARCHAR(160) NOT NULL,
  email      VARCHAR(160) NULL,
  phone      VARCHAR(20)  NULL,
  status     ENUM('trial','active','suspended','cancelled') NOT NULL DEFAULT 'trial',
  settings   JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tenants_slug (slug)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tenant_members (
  tenant_id BIGINT UNSIGNED NOT NULL,
  user_id   BIGINT UNSIGNED NOT NULL,
  role      ENUM('owner','admin','staff') NOT NULL DEFAULT 'staff',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (tenant_id, user_id),
  KEY idx_members_user (user_id),
  CONSTRAINT fk_members_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_members_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------- plans/billing
CREATE TABLE IF NOT EXISTS plans (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code              VARCHAR(40) NOT NULL,
  name              VARCHAR(80) NOT NULL,
  monthly_price_kes INT UNSIGNED NOT NULL DEFAULT 0,
  parcel_limit      INT UNSIGNED NOT NULL DEFAULT 0,
  staff_limit       INT UNSIGNED NOT NULL DEFAULT 1,
  features          JSON NULL,
  sort_order        INT NOT NULL DEFAULT 0,
  is_active         TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_plans_code (code)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS subscriptions (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id            BIGINT UNSIGNED NOT NULL,
  plan_id              INT UNSIGNED NOT NULL,
  status               ENUM('trialing','active','past_due','cancelled') NOT NULL DEFAULT 'trialing',
  trial_ends_at        DATETIME NULL,
  current_period_start DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  current_period_end   DATETIME NULL,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_subscriptions_tenant (tenant_id),
  CONSTRAINT fk_sub_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_sub_plan FOREIGN KEY (plan_id) REFERENCES plans (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS invoices (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id   BIGINT UNSIGNED NOT NULL,
  plan_id     INT UNSIGNED NOT NULL,
  number      VARCHAR(30) NOT NULL,
  amount_kes  INT UNSIGNED NOT NULL,
  status      ENUM('pending','paid','void') NOT NULL DEFAULT 'pending',
  description VARCHAR(160) NOT NULL,
  issued_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  due_at      DATETIME NULL,
  paid_at     DATETIME NULL,
  method      VARCHAR(20) NULL,
  reference   VARCHAR(60) NULL,
  UNIQUE KEY uq_invoices_number (number),
  KEY idx_invoices_tenant (tenant_id, issued_at),
  CONSTRAINT fk_invoices_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
  CONSTRAINT fk_invoices_plan FOREIGN KEY (plan_id) REFERENCES plans (id)
) ENGINE=InnoDB;

-- --------------------------------------------------------------- integrations
CREATE TABLE IF NOT EXISTS api_keys (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id    BIGINT UNSIGNED NOT NULL,
  name         VARCHAR(80) NOT NULL,
  prefix       CHAR(12) NOT NULL,
  key_hash     CHAR(64) NOT NULL,
  last_used_at DATETIME NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at   DATETIME NULL,
  UNIQUE KEY uq_api_keys_prefix (prefix),
  KEY idx_api_keys_tenant (tenant_id),
  CONSTRAINT fk_api_keys_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- --------------------------------------------------------------- audit/limits
CREATE TABLE IF NOT EXISTS audit_log (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id     BIGINT UNSIGNED NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  action        VARCHAR(80) NOT NULL,
  entity        VARCHAR(60) NULL,
  entity_id     VARCHAR(64) NULL,
  meta          JSON NULL,
  ip            VARCHAR(45) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_tenant (tenant_id, created_at),
  KEY idx_audit_action (action, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rate_limits (
  bucket     VARCHAR(160) PRIMARY KEY,
  hits       INT UNSIGNED NOT NULL DEFAULT 0,
  expires_at DATETIME NOT NULL,
  KEY idx_rate_expiry (expires_at)
) ENGINE=InnoDB;

-- ------------------------------------------------------------- tenant scoping
ALTER TABLE parcels
  ADD COLUMN tenant_id BIGINT UNSIGNED NULL AFTER anon_id,
  ADD KEY idx_parcels_tenant (tenant_id, registered_at),
  ADD CONSTRAINT fk_parcels_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE SET NULL;

ALTER TABLE errands
  ADD COLUMN tenant_id BIGINT UNSIGNED NULL AFTER anon_id,
  ADD KEY idx_errands_tenant (tenant_id, created_at),
  ADD CONSTRAINT fk_errands_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE SET NULL;

ALTER TABLE senders
  ADD COLUMN tenant_id BIGINT UNSIGNED NULL AFTER id,
  DROP INDEX uq_senders,
  ADD UNIQUE KEY uq_senders_tenant (tenant_id, phone, name),
  ADD CONSTRAINT fk_senders_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE;
