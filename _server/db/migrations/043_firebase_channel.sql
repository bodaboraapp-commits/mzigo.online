-- Mzigo.online — migration 043: Firebase Phone Auth communication channel.
-- Inactive until the platform admin saves the Firebase web credentials in
-- Admin → Communications → Firebase Phone Auth (SMS OTP).
INSERT INTO communication_channels (code, label, provider, sender, config, is_active, sort_order)
VALUES ('firebase', 'Firebase Phone Auth (SMS OTP)', 'firebase', NULL, NULL, 0,
        (SELECT next_order FROM (SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_order FROM communication_channels) AS t))
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    provider = VALUES(provider);
