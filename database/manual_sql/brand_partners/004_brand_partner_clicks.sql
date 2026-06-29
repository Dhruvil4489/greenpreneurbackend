-- Manual PostgreSQL SQL for Brand Partner Clicks
-- Run manually; do not use Laravel migrations.

CREATE TABLE IF NOT EXISTS brand_partner_clicks (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID NULL,
    brand_partner_id UUID NOT NULL,
    click_type VARCHAR(30) NOT NULL, -- visit, redeem, share, call, email, website
    ip VARCHAR(45) NULL,
    device TEXT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
