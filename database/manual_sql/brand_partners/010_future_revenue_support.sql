-- SQL Patch for Brand Partners Monetization & Redemption Support
-- Run manually on PostgreSQL; do not use Laravel migrations.

-- Alter brand_partners table to add future revenue columns
ALTER TABLE brand_partners 
    ADD COLUMN IF NOT EXISTS affiliate_link VARCHAR(500) NULL,
    ADD COLUMN IF NOT EXISTS lead_generation_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS qr_code_redemption_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS cpc_rate NUMERIC(10,4) NULL,
    ADD COLUMN IF NOT EXISTS cpm_rate NUMERIC(10,4) NULL,
    ADD COLUMN IF NOT EXISTS cpa_rate NUMERIC(10,4) NULL,
    ADD COLUMN IF NOT EXISTS billing_plan VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS geo_targeting_rules JSONB NULL,
    ADD COLUMN IF NOT EXISTS personalized_campaigns_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS campaign_start_date TIMESTAMPTZ NULL,
    ADD COLUMN IF NOT EXISTS campaign_end_date TIMESTAMPTZ NULL;

-- Create Brand Partner Coupon Redemptions history table
CREATE TABLE IF NOT EXISTS brand_partner_coupon_redemptions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    brand_partner_id UUID NOT NULL,
    user_id UUID NOT NULL,
    coupon_code VARCHAR(100) NOT NULL,
    redeemed_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    metadata JSONB NULL,
    CONSTRAINT fk_coupon_redemptions_partner FOREIGN KEY (brand_partner_id) REFERENCES brand_partners (id) ON DELETE CASCADE,
    CONSTRAINT fk_coupon_redemptions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

-- Indexes for performance optimizations on redemptions
CREATE INDEX IF NOT EXISTS idx_coupon_redemptions_partner ON brand_partner_coupon_redemptions(brand_partner_id);
CREATE INDEX IF NOT EXISTS idx_coupon_redemptions_user ON brand_partner_coupon_redemptions(user_id);
