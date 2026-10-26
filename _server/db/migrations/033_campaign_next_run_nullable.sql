-- Growth experiments: completed one-shot campaigns have no next run.
ALTER TABLE referral_campaigns MODIFY next_run_at DATETIME NULL DEFAULT NULL;
