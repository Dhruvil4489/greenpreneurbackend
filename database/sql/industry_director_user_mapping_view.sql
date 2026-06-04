-- Industry Director user/category/city mapping view.
-- Run manually in PostgreSQL. No Laravel migration is provided for this view.

DROP VIEW IF EXISTS industry_director_user_mappings;

CREATE OR REPLACE VIEW industry_director_user_mappings AS
SELECT DISTINCT
    u.id AS user_id,
    c.industry_director_user_id AS industry_director_id,
    cc.id AS main_category_id,
    cc.name AS main_category,
    ccl4.id AS sub_category_id,
    ccl4.name AS sub_category,
    NULL::uuid AS state_id,
    ct.state AS state,
    ct.id AS city_id,
    ct.name AS city
FROM users u
JOIN circle_members cm
    ON cm.user_id = u.id
   AND cm.status = 'approved'
   AND cm.deleted_at IS NULL
   AND cm.left_at IS NULL
JOIN circles c
    ON c.id = cm.circle_id
   AND c.deleted_at IS NULL
JOIN circle_category_mappings ccm
    ON ccm.circle_id = c.id
JOIN circle_categories cc
    ON cc.id = COALESCE(u.main_business_category_id, ccm.category_id)
LEFT JOIN circle_category_level4 ccl4
    ON ccl4.id = u.business_category_id
LEFT JOIN cities ct
    ON ct.id = u.city_id
WHERE u.deleted_at IS NULL
  AND u.id IS NOT NULL
  AND cc.id IS NOT NULL;

-- Verification queries:
-- SELECT * FROM industry_director_user_mappings LIMIT 50;
-- SELECT industry_director_id, main_category, COUNT(*) AS total_users
-- FROM industry_director_user_mappings
-- GROUP BY industry_director_id, main_category
-- ORDER BY total_users DESC;
