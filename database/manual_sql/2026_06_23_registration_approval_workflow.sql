-- Manual PostgreSQL Schema updates for Greenpreneur Registration Approval Workflow
-- Run these queries directly on your PostgreSQL database.

-- 1. Add 'rejected' to the custom user_status_enum type
ALTER TYPE user_status_enum ADD VALUE IF NOT EXISTS 'rejected';

-- 2. Add 'registration_source' column to the users table
ALTER TABLE users ADD COLUMN IF NOT EXISTS registration_source VARCHAR(100) DEFAULT 'App';

-- 3. Add index on status for faster lookup/filtering of inactive users
CREATE INDEX IF NOT EXISTS idx_users_status ON users(status);
