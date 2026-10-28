-- Mzigo.online â€” migration 041: product and package photos captured at order creation.
CREATE TABLE IF NOT EXISTS parcel_photos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  parcel_id CHAR(36) NOT NULL,
  kind ENUM('product','package') NOT NULL,
  filename VARCHAR(255) NOT NULL,
  mime VARCHAR(60) NOT NULL,
  size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
  storage_name VARCHAR(190) NOT NULL,
  scan_status VARCHAR(30) NOT NULL DEFAULT 'clean',
  uploaded_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_parcel_photo_kind (parcel_id, kind),
  KEY idx_parcel_photos_parcel (parcel_id),
  CONSTRAINT fk_parcel_photos_parcel FOREIGN KEY (parcel_id) REFERENCES parcels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

