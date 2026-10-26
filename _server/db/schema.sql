-- Mzigo.online — MySQL/MariaDB schema
-- Follows the parcel-network engineering design doc: integer KES, UUID parcels,
-- Locker IDs, scan history, fee quotes, Tier A/B, pay-at-collection, errands.
-- Tested on XAMPP MariaDB 10.4.32 (port 3307).

CREATE DATABASE IF NOT EXISTS mzigo_online
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE mzigo_online;

CREATE TABLE IF NOT EXISTS users (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  phone         VARCHAR(20)  NOT NULL,
  name          VARCHAR(120) NOT NULL,
  business      VARCHAR(160) NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME     NULL,
  UNIQUE KEY uq_users_phone (phone)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS partners (
  slug      VARCHAR(60)  PRIMARY KEY,
  name      VARCHAR(120) NOT NULL,
  is_pickup TINYINT(1)   NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS destinations (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  partner_slug VARCHAR(60)  NOT NULL,
  name         VARCHAR(120) NOT NULL,
  route        VARCHAR(20)  NOT NULL DEFAULT '0',
  KEY idx_dest_partner (partner_slug, name),
  CONSTRAINT fk_dest_partner FOREIGN KEY (partner_slug)
    REFERENCES partners (slug) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS parcels (
  id              CHAR(36)     PRIMARY KEY,
  tracking        VARCHAR(20)  NOT NULL,
  locker          VARCHAR(16)  NOT NULL,
  user_id         BIGINT UNSIGNED NULL,
  anon_id         CHAR(32)     NULL,
  carrier         VARCHAR(120) NOT NULL,
  carrier_slug    VARCHAR(60)  NOT NULL,
  from_city       VARCHAR(120) NOT NULL,
  to_city         VARCHAR(120) NOT NULL,
  sender_name     VARCHAR(120) NOT NULL,
  sender_phone    VARCHAR(20)  NOT NULL,
  receiver_name   VARCHAR(120) NOT NULL,
  receiver_phone  VARCHAR(20)  NOT NULL,
  description     VARCHAR(255) NOT NULL,
  size            VARCHAR(40)  NOT NULL,
  value_kes       INT UNSIGNED NOT NULL DEFAULT 0,
  tier            CHAR(1)      NOT NULL DEFAULT 'A',
  payment_method  VARCHAR(10)  NOT NULL,
  collect_code    CHAR(6)      NOT NULL,
  status          VARCHAR(30)  NOT NULL DEFAULT 'CREATED',
  fee_base        INT UNSIGNED NOT NULL DEFAULT 0,
  fee_holding     INT UNSIGNED NOT NULL DEFAULT 0,
  fee_insurance   INT UNSIGNED NOT NULL DEFAULT 0,
  fee_platform    INT UNSIGNED NOT NULL DEFAULT 0,
  fee_total       INT UNSIGNED NOT NULL DEFAULT 0,
  registered_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_parcels_tracking (tracking),
  UNIQUE KEY uq_parcels_locker (locker),
  KEY idx_parcels_user (user_id),
  KEY idx_parcels_anon (anon_id),
  KEY idx_parcels_phone (sender_phone),
  KEY idx_parcels_status (status),
  CONSTRAINT fk_parcels_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS scans (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subject_type ENUM('parcel','errand') NOT NULL,
  subject_id   CHAR(36)     NOT NULL,
  status       VARCHAR(30)  NOT NULL,
  label        VARCHAR(120) NOT NULL,
  location     VARCHAR(160) NULL,
  note         VARCHAR(255) NULL,
  scanned_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_scans_subject (subject_type, subject_id, scanned_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS errands (
  id               CHAR(36)     PRIMARY KEY,
  ref              VARCHAR(20)  NOT NULL,
  user_id          BIGINT UNSIGNED NULL,
  anon_id          CHAR(32)     NULL,
  carrier          VARCHAR(120) NOT NULL,
  pickup           VARCHAR(160) NOT NULL,
  dropoff          VARCHAR(160) NOT NULL,
  sender_name      VARCHAR(120) NOT NULL,
  sender_phone     VARCHAR(20)  NOT NULL,
  recipient_name   VARCHAR(120) NOT NULL,
  recipient_phone  VARCHAR(20)  NOT NULL,
  description      VARCHAR(255) NOT NULL,
  instructions     VARCHAR(255) NULL,
  payment_method   VARCHAR(10)  NOT NULL,
  status           VARCHAR(30)  NOT NULL DEFAULT 'CREATED',
  fee_base         INT UNSIGNED NOT NULL DEFAULT 0,
  fee_platform     INT UNSIGNED NOT NULL DEFAULT 0,
  fee_total        INT UNSIGNED NOT NULL DEFAULT 0,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_errands_ref (ref),
  KEY idx_errands_user (user_id),
  KEY idx_errands_anon (anon_id),
  CONSTRAINT fk_errands_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS senders (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  phone      VARCHAR(20)  NOT NULL,
  name       VARCHAR(120) NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_senders (phone, name)
) ENGINE=InnoDB;
