-- Mzigo.online — SaaS migration 014: editable landing-page hero copy.
--
-- Title and subtitle shown in the landing-page hero (and the footer tagline)
-- are stored in platform_settings and edited from Admin → Website.

USE mzigo_online;

INSERT IGNORE INTO platform_settings (setting_key, setting_value) VALUES
  ('hero_title', ''),
  ('hero_subtitle', '');
