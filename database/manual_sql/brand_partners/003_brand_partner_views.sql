-- Manual PostgreSQL SQL for Brand Partner Views
-- Run manually; do not use Laravel migrations.

CREATE TABLE IF NOT EXISTS brand_partner_views (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID NULL,
    brand_partner_id UUID NOT NULL,
    viewed_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
