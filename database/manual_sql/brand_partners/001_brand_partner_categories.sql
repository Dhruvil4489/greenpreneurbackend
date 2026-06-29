-- Manual PostgreSQL SQL for Brand Partner Categories
-- Run manually; do not use Laravel migrations.

CREATE TABLE IF NOT EXISTS brand_partner_categories (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name VARCHAR(100) NOT NULL,
    icon VARCHAR(100) NULL,
    color VARCHAR(7) NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
