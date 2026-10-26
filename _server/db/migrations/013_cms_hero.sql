-- Mzigo.online — SaaS migration 013: hero photo for the landing page.
--
-- The photo is uploaded from Admin → Website and applied to the landing-page
-- hero; the URL lives in platform_settings (file lives in assets/img/branding/).

USE mzigo_online;

INSERT IGNORE INTO platform_settings (setting_key, setting_value) VALUES
  ('brand_hero_url', '');
