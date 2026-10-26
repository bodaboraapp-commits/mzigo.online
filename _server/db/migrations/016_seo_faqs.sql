-- Mzigo.online — SaaS migration 016: SEO FAQ bank.
--
-- Replaces the launch FAQs with the keyword-aware set used on the homepage and
-- keeps the CMS (Admin → Website → FAQs) as the editing surface afterwards.
USE mzigo_online;

INSERT INTO faqs (id, question, answer, sort_order, is_active) VALUES
  (1, 'How do I send a parcel with Mzigo.online?', 'Open Send Mzigo, choose a courier partner and route, add the sender and receiver details, declare the parcel value and drop it off at the partner office. A tracking number and QR label are generated for every parcel.', 1, 1),
  (2, 'How do I track my parcel?', 'Enter the tracking number or Locker ID on the Track page. You will see every scan — drop-off, transit, arrival and collection — without signing in.', 2, 1),
  (3, 'How much does it cost to send a parcel in Kenya?', 'The quote combines the courier partner''s base rate for the parcel size and route with holding, declared-value cover and a small platform fee. The exact amount is shown before you register the parcel.', 3, 1),
  (4, 'Can the receiver pay the delivery fee at collection?', 'Yes. The receiver pays by M-Pesa when the parcel is ready for collection and receives a collection code. Some workspaces agree different billing terms.', 4, 1),
  (5, 'Where do I find my tracking number or Locker ID?', 'It is on the QR label or receipt from drop-off, and is sent by SMS or WhatsApp when the parcel is registered. Workspace users can also copy it from Orders.', 5, 1),
  (6, 'What parcel sizes can I send?', 'Envelopes cover documents; Small and Medium suit everyday boxes; Large and above handle bulky items. Declare fragile or high-value items accurately and contact support if you are unsure.', 6, 1),
  (7, 'How long does delivery take?', 'Delivery time depends on the partner and route. Most routes move within a few days, and tracking updates appear as the parcel is scanned at each office. Check with support for a specific route before sending urgent items.', 7, 1),
  (8, 'What happens if my parcel is lost, damaged or delayed?', 'Open a claim from the Claims page with the tracking number, a description and the estimated value. Claims are reviewed against the declared value and the partner''s terms, and approved payouts are recorded in the platform.', 8, 1),
  (9, 'Which destinations do you cover?', 'Our partner network currently covers 70+ destinations, including Nairobi, Mombasa, Nakuru, Nyeri, Naivasha, Thika, Machakos, Kitui, Embu, Kilifi, Malindi and Voi. New routes are added regularly.', 9, 1),
  (10, 'Do you offer plans for businesses?', 'Yes. Starter suits small shops, Growth adds API access and team roles for growing stores, and Scale supports high-volume operations with custom rate cards and SLA support.', 10, 1),
  (11, 'Is the delivery fee included in my plan?', 'No. Plans cover platform features such as parcel limits and team seats. Delivery fees are quoted per parcel and are paid at collection unless your workspace has agreed different terms.', 11, 1),
  (12, 'Can I become a courier partner?', 'If you run a courier or transport business, email hello@mzigo.online with your company details, routes and offices. We will get back to you about onboarding.', 12, 1)
ON DUPLICATE KEY UPDATE
  question = VALUES(question),
  answer = VALUES(answer),
  sort_order = VALUES(sort_order),
  is_active = VALUES(is_active);

DELETE FROM faqs WHERE id > 12;
