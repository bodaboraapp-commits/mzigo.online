-- Mzigo.online — migration 017: parcel-insurance FAQ (Kenyan first).
-- Inserts the insurance question in position 3 so it appears on the landing page
-- (which shows the first five FAQs) as well as on /faq.
USE mzigo_online;

UPDATE faqs SET sort_order = sort_order + 1 WHERE sort_order >= 3;

INSERT INTO faqs (id, question, answer, sort_order, is_active) VALUES
  (13, 'Is my parcel insured?', 'Yes — Mzigo.online is the first Kenyan company to offer parcel insurance. Declare the parcel''s value at registration to set your cover, and if something goes wrong our claims process assesses approved payouts against that declared value.', 3, 1)
ON DUPLICATE KEY UPDATE
  question = VALUES(question),
  answer = VALUES(answer),
  sort_order = VALUES(sort_order),
  is_active = VALUES(is_active);
