ALTER TABLE notifications
    ADD COLUMN IF NOT EXISTS delivery_key VARCHAR(191) NULL AFTER message,
    ADD UNIQUE KEY IF NOT EXISTS delivery_key (delivery_key);
