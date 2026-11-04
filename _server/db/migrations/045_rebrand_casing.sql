-- MziGO.Online brand casing migration
-- Update stored copy/templates without touching lowercase domains or email addresses.

UPDATE email_templates
SET label = REPLACE(label, 'Mzigo.online', 'MziGO.Online'),
    subject = REPLACE(subject, 'Mzigo.online', 'MziGO.Online'),
    trigger_text = REPLACE(trigger_text, 'Mzigo.online', 'MziGO.Online'),
    body_text = REPLACE(body_text, 'Mzigo.online', 'MziGO.Online'),
    body_html = REPLACE(body_html, 'Mzigo.online', 'MziGO.Online')
WHERE CONCAT_WS(' ', label, subject, trigger_text, body_text, body_html) LIKE '%Mzigo.online%';

UPDATE platform_settings
SET setting_value = REPLACE(setting_value, 'Mzigo.online', 'MziGO.Online')
WHERE setting_value LIKE '%Mzigo.online%';

UPDATE faqs
SET question = REPLACE(question, 'Mzigo.online', 'MziGO.Online'),
    answer = REPLACE(answer, 'Mzigo.online', 'MziGO.Online')
WHERE CONCAT_WS(' ', question, answer) LIKE '%Mzigo.online%';
