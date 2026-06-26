-- ============================================================
-- GREENPRENEUR — New Registration Fields SQL Script
-- Date: 2026-06-24
-- Run manually on local and production PostgreSQL databases.
-- NO migrations used.
-- ============================================================

-- Add the new registration fields to the users table if they do not exist
ALTER TABLE users ADD COLUMN IF NOT EXISTS website VARCHAR(255) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS sustainability_contribution TEXT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS sustainability_areas JSONB NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS greenpreneur_goals JSONB NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS community_directory_listing VARCHAR(10) NULL;

-- ============================================================
-- VERIFICATION QUERY
-- Run this query to verify that the columns have been added
-- ============================================================
SELECT column_name, data_type, character_maximum_length, is_nullable
FROM information_schema.columns
WHERE table_name = 'users'
  AND column_name IN (
    'website',
    'sustainability_contribution',
    'sustainability_areas',
    'greenpreneur_goals',
    'community_directory_listing'
  )
ORDER BY column_name;
