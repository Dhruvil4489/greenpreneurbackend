-- ============================================================
-- GREENPRENEUR — Only Green Peer Rebranding SQL Script
-- Date: 2026-06-23
-- Run manually on local and production PostgreSQL databases.
-- NO migrations used.
-- ============================================================

-- ============================================================
-- STEP 1: Add 'Only Green Peer' to the membership_status_enum
-- (PostgreSQL enums can only ADD new values, not rename/remove)    
-- ============================================================

ALTER TYPE membership_status_enum ADD VALUE IF NOT EXISTS 'Only Green Peer';


-- ============================================================
-- STEP 2: Migrate existing users
-- Move all users currently set to 'Only Unity Peer' or 'only_unity_peer'
-- to the new canonical value 'Only Green Peer'
-- ============================================================

-- Migrate users with 'Only Unity Peer' (the old Peers Global DB value)
UPDATE users
SET membership_status = 'Only Green Peer',
    updated_at = NOW()
WHERE membership_status = 'Only Unity Peer';

-- Migrate users with legacy snake_case 'only_unity_peer' (if any exist)
UPDATE users
SET membership_status = 'Only Green Peer',
    updated_at = NOW()
WHERE membership_status = 'only_unity_peer';


-- ============================================================
-- STEP 3: Verify the results
-- Run these SELECT queries to confirm correctness
-- ============================================================

-- Check current enum values (should include 'Only Green Peer')
SELECT enumlabel
FROM pg_enum
JOIN pg_type ON pg_enum.enumtypid = pg_type.oid
WHERE pg_type.typname = 'membership_status_enum'
ORDER BY enumsortorder;

-- Check user distribution by membership_status after migration
SELECT membership_status, COUNT(*) AS user_count
FROM users
GROUP BY membership_status
ORDER BY user_count DESC;

-- Confirm no users remain with old Unity Peer values
SELECT COUNT(*) AS old_unity_peer_remaining
FROM users
WHERE membership_status IN ('Only Unity Peer', 'only_unity_peer');

-- ============================================================
-- STEP 4: Verify registration workflow
-- ============================================================

-- Check the 5 most recently registered App users (should be inactive + free_trial_peer)
SELECT id, email, status, membership_status, registration_source, created_at
FROM users
WHERE registration_source = 'App'
ORDER BY created_at DESC
LIMIT 5;

-- ============================================================
-- END OF SCRIPT
-- Expected results:
--   - membership_status_enum contains 'Only Green Peer'
--   - All users previously 'Only Unity Peer' are now 'Only Green Peer'
--   - New registrations: status=inactive, membership_status=free_trial_peer
--   - After approval: status=active, membership_status='Only Green Peer'
-- ============================================================
