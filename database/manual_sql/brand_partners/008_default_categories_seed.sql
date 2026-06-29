-- Manual PostgreSQL SQL for Brand Partner Seeding
-- Run manually; do not use Laravel migrations.

-- Default Brand Partner Categories Seed (Idempotent)
INSERT INTO brand_partner_categories (id, name, icon, color, sort_order, status, created_at, updated_at)
SELECT gen_random_uuid(), 'Technology', 'bi-laptop', '#4A90E2', 1, 'active', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM brand_partner_categories WHERE name = 'Technology');

INSERT INTO brand_partner_categories (id, name, icon, color, sort_order, status, created_at, updated_at)
SELECT gen_random_uuid(), 'Education', 'bi-book', '#F5A623', 2, 'active', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM brand_partner_categories WHERE name = 'Education');

INSERT INTO brand_partner_categories (id, name, icon, color, sort_order, status, created_at, updated_at)
SELECT gen_random_uuid(), 'Healthcare', 'bi-heart-pulse', '#E28499', 3, 'active', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM brand_partner_categories WHERE name = 'Healthcare');

INSERT INTO brand_partner_categories (id, name, icon, color, sort_order, status, created_at, updated_at)
SELECT gen_random_uuid(), 'Finance', 'bi-cash-coin', '#7ED321', 4, 'active', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM brand_partner_categories WHERE name = 'Finance');

INSERT INTO brand_partner_categories (id, name, icon, color, sort_order, status, created_at, updated_at)
SELECT gen_random_uuid(), 'Travel', 'bi-airplane', '#50E3C2', 5, 'active', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM brand_partner_categories WHERE name = 'Travel');

INSERT INTO brand_partner_categories (id, name, icon, color, sort_order, status, created_at, updated_at)
SELECT gen_random_uuid(), 'Food', 'bi-egg-fried', '#D0021B', 6, 'active', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM brand_partner_categories WHERE name = 'Food');

INSERT INTO brand_partner_categories (id, name, icon, color, sort_order, status, created_at, updated_at)
SELECT gen_random_uuid(), 'Marketing', 'bi-megaphone', '#BD10E0', 7, 'active', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM brand_partner_categories WHERE name = 'Marketing');

INSERT INTO brand_partner_categories (id, name, icon, color, sort_order, status, created_at, updated_at)
SELECT gen_random_uuid(), 'Business Services', 'bi-briefcase', '#9B9B9B', 8, 'active', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM brand_partner_categories WHERE name = 'Business Services');

INSERT INTO brand_partner_categories (id, name, icon, color, sort_order, status, created_at, updated_at)
SELECT gen_random_uuid(), 'Legal', 'bi-bank', '#4A4A4A', 9, 'active', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM brand_partner_categories WHERE name = 'Legal');

INSERT INTO brand_partner_categories (id, name, icon, color, sort_order, status, created_at, updated_at)
SELECT gen_random_uuid(), 'HR', 'bi-people', '#F8E71C', 10, 'active', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM brand_partner_categories WHERE name = 'HR');

INSERT INTO brand_partner_categories (id, name, icon, color, sort_order, status, created_at, updated_at)
SELECT gen_random_uuid(), 'Insurance', 'bi-shield-check', '#7ED321', 11, 'active', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM brand_partner_categories WHERE name = 'Insurance');

INSERT INTO brand_partner_categories (id, name, icon, color, sort_order, status, created_at, updated_at)
SELECT gen_random_uuid(), 'Real Estate', 'bi-house-door', '#B8E986', 12, 'active', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM brand_partner_categories WHERE name = 'Real Estate');

INSERT INTO brand_partner_categories (id, name, icon, color, sort_order, status, created_at, updated_at)
SELECT gen_random_uuid(), 'Manufacturing', 'bi-gear-wide-connected', '#8B572A', 13, 'active', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM brand_partner_categories WHERE name = 'Manufacturing');

-- Seed Role Definitions into roles table
INSERT INTO roles (id, key, name, description, created_at, updated_at)
VALUES 
(gen_random_uuid(), 'marketing_team', 'Marketing Team', 'Manage Brand Partners and Offers', NOW(), NOW())
ON CONFLICT (key) DO UPDATE SET name = EXCLUDED.name, description = EXCLUDED.description;

INSERT INTO roles (id, key, name, description, created_at, updated_at)
VALUES 
(gen_random_uuid(), 'analytics_team', 'Analytics Team', 'View Brand Partners analytics and exports', NOW(), NOW())
ON CONFLICT (key) DO UPDATE SET name = EXCLUDED.name, description = EXCLUDED.description;

INSERT INTO roles (id, key, name, description, created_at, updated_at)
VALUES 
(gen_random_uuid(), 'content_team', 'Content Team', 'Manage Content and Brand categories', NOW(), NOW())
ON CONFLICT (key) DO UPDATE SET name = EXCLUDED.name, description = EXCLUDED.description;

INSERT INTO roles (id, key, name, description, created_at, updated_at)
VALUES 
(gen_random_uuid(), 'read_only', 'Read Only Staff', 'Read-only view of dashboard and reports', NOW(), NOW())
ON CONFLICT (key) DO UPDATE SET name = EXCLUDED.name, description = EXCLUDED.description;
