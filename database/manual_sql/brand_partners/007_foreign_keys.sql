-- Manual PostgreSQL SQL for Brand Partner Foreign Keys & Roles Enum
-- Run manually; do not use Laravel migrations.
-- WARNING: ALTER TYPE ... ADD VALUE cannot run inside a transaction block in PostgreSQL.
-- Execute these statements separately outside of any transaction block.

ALTER TYPE admin_role_key_enum ADD VALUE IF NOT EXISTS 'marketing_team';
ALTER TYPE admin_role_key_enum ADD VALUE IF NOT EXISTS 'analytics_team';
ALTER TYPE admin_role_key_enum ADD VALUE IF NOT EXISTS 'content_team';
ALTER TYPE admin_role_key_enum ADD VALUE IF NOT EXISTS 'read_only';

-- Add foreign key constraints
ALTER TABLE brand_partners
    ADD CONSTRAINT fk_brand_partners_category FOREIGN KEY (category_id) REFERENCES brand_partner_categories(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_brand_partners_created_by FOREIGN KEY (created_by) REFERENCES admin_users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_brand_partners_updated_by FOREIGN KEY (updated_by) REFERENCES admin_users(id) ON DELETE SET NULL;

ALTER TABLE brand_partner_views
    ADD CONSTRAINT fk_brand_partner_views_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_brand_partner_views_partner FOREIGN KEY (brand_partner_id) REFERENCES brand_partners(id) ON DELETE CASCADE;

ALTER TABLE brand_partner_clicks
    ADD CONSTRAINT fk_brand_partner_clicks_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_brand_partner_clicks_partner FOREIGN KEY (brand_partner_id) REFERENCES brand_partners(id) ON DELETE CASCADE;

ALTER TABLE brand_partner_saved
    ADD CONSTRAINT fk_brand_partner_saved_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_brand_partner_saved_partner FOREIGN KEY (brand_partner_id) REFERENCES brand_partners(id) ON DELETE CASCADE;
