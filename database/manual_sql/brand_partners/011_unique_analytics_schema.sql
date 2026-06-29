-- SQL Patch to upgrade brand_partner_views and brand_partner_clicks for unique analytics tracking
-- Run manually on PostgreSQL; do not use Laravel migrations.

-- Upgrade brand_partner_views
ALTER TABLE brand_partner_views 
    ADD COLUMN IF NOT EXISTS session_id VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS ip_address VARCHAR(45) NULL;

-- Upgrade brand_partner_clicks
ALTER TABLE brand_partner_clicks 
    ADD COLUMN IF NOT EXISTS session_id VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS ip_address VARCHAR(45) NULL;

-- Create optimization indexes for views and clicks unique checking
CREATE INDEX IF NOT EXISTS idx_bp_views_unique_tracker ON brand_partner_views(brand_partner_id, user_id, ip_address, session_id, viewed_at);
CREATE INDEX IF NOT EXISTS idx_bp_clicks_unique_tracker ON brand_partner_clicks(brand_partner_id, user_id, ip_address, session_id, created_at);
