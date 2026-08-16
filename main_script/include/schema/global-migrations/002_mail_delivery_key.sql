ALTER TABLE mailServer
    ADD COLUMN IF NOT EXISTS delivery_key VARCHAR(191) NULL AFTER html,
    ADD UNIQUE KEY IF NOT EXISTS delivery_key (delivery_key);
