-- Multi-trigger campaign configuration.
ALTER TABLE marketing_campaigns ADD COLUMN trigger_config JSON NULL AFTER trigger_days;
