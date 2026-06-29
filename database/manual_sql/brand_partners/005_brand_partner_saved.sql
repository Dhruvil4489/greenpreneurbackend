-- Manual PostgreSQL SQL for Brand Partner Saved
-- Run manually; do not use Laravel migrations.

CREATE TABLE IF NOT EXISTS brand_partner_saved (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID NOT NULL,
    brand_partner_id UUID NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_brand_partner_saved UNIQUE (user_id, brand_partner_id)
);
