-- Admin-defined campaign triggers: time, lifecycle event, audience condition, or all.
ALTER TABLE marketing_campaigns
    ADD COLUMN trigger_type ENUM('time','event','condition','all') NOT NULL DEFAULT 'time' AFTER audience,
    ADD COLUMN trigger_event VARCHAR(40) NULL AFTER trigger_type,
    ADD COLUMN trigger_condition VARCHAR(40) NULL AFTER trigger_event,
    ADD COLUMN trigger_days INT UNSIGNED NOT NULL DEFAULT 0 AFTER trigger_condition;
