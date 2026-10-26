-- Mzigo.online — SaaS migration 012: website CMS (FAQs + brand assets).
--
-- FAQs drive the landing-page accordion and are edited in the admin console
-- (Admin → Website). Brand asset URLs are stored in platform_settings and
-- applied site-wide; uploads themselves live in assets/img/branding/.

USE mzigo_online;

CREATE TABLE IF NOT EXISTS faqs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  question VARCHAR(200) NOT NULL,
  answer TEXT NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_faq_order (sort_order, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO faqs (question, answer, sort_order, is_active) VALUES
  ('How do I send a parcel?',
   'Open Send Mzigo, choose your courier and route, add the sender and receiver details, then drop the parcel off at the partner office. A tracking number and QR label are generated for every parcel.',
   1, 1),
  ('How do I track my parcel?',
   'Enter the tracking number or Locker ID on Track Mzigo to see every scan — drop-off, transit, arrival and collection.',
   2, 1),
  ('How do I make a drop-off?',
   'Visit the office of the courier partner you chose and hand the parcel to the customer service team. They scan your QR label so tracking starts immediately.',
   3, 1),
  ('How much does delivery cost?',
   'The quote on Send Mzigo combines the base rate for the parcel size and route with holding, insurance and a small platform fee. The exact total is shown before you register the parcel.',
   4, 1),
  ('Can I pay the delivery fee at collection?',
   'Yes. The receiver pays the delivery fee by M-Pesa when the parcel is ready for collection. A collection code is issued after payment and shown at the office.',
   5, 1),
  ('What if my parcel is delayed, lost or damaged?',
   'Open a claim from the Claims page with the tracking number, describe what happened and add the estimated value. Our team reviews it and records a payout if the claim is approved.',
   6, 1);

INSERT IGNORE INTO platform_settings (setting_key, setting_value) VALUES
  ('brand_logo_url', ''),
  ('brand_favicon_url', ''),
  ('brand_version', '0');
