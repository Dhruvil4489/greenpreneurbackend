-- ============================================================
-- GREENPRENEUR — Mandatory & New Fields Deployment Script
-- Database: PostgreSQL (Live & Local)
-- Date: 2026-06-24
-- ============================================================

-- NOTE: Run these statements individually. In PostgreSQL, ALTER TYPE ... ADD VALUE
-- cannot be executed inside a transaction block.

-- 1. Ensure Missing Enum Values Exist
ALTER TYPE membership_status_enum ADD VALUE IF NOT EXISTS 'only_green_peer';

-- 2. Add New/Missing Columns
ALTER TABLE users ADD COLUMN IF NOT EXISTS website VARCHAR(255) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS sustainability_contribution TEXT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS sustainability_areas JSONB NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS greenpreneur_goals JSONB NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS community_directory_listing VARCHAR(10) NULL;

-- 3. Ensure Registration Source Index Exists
CREATE INDEX IF NOT EXISTS idx_users_registration_source ON users (registration_source);
