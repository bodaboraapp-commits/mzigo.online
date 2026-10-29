-- Mzigo.online — migration 039: analytics IP + geo for CTA click review.
ALTER TABLE analytics_events
    ADD COLUMN ip VARCHAR(45) NULL AFTER ip_hash,
    ADD COLUMN geo_country CHAR(2) NULL AFTER ip,
    ADD COLUMN geo_city VARCHAR(80) NULL AFTER geo_country;
