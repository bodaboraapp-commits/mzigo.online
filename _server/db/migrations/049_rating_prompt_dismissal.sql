-- Mzigo.online — migration 049: persistent delivery-rating prompt dismissal.
-- Lets a customer dismiss the post-collection "Rate your delivery" prompt so it
-- does not reappear on the next login, while new deliveries still prompt.
USE mzigo_online;

ALTER TABLE parcels
  ADD COLUMN csat_dismissed_at DATETIME NULL AFTER csat_at;
