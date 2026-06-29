-- SQL Patch to synchronize brand_partners metadata columns
-- Run manually on PostgreSQL to add missing table fields

ALTER TABLE brand_partners 
    ADD COLUMN IF NOT EXISTS whatsapp VARCHAR(50) NULL,
    ADD COLUMN IF NOT EXISTS address VARCHAR(500) NULL,
    ADD COLUMN IF NOT EXISTS terms_and_conditions TEXT NULL,
    ADD COLUMN IF NOT EXISTS meta_title VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS meta_description VARCHAR(500) NULL,
    ADD COLUMN IF NOT EXISTS keywords VARCHAR(255) NULL;
