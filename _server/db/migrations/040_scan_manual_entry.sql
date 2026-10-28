-- Mzigo.online — migration 040: per-point manual tracking entry control.
ALTER TABLE partner_points
  ADD COLUMN manual_entry_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active;
