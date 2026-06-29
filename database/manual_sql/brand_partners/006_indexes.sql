-- Manual PostgreSQL SQL for Brand Partner Indices
-- Run manually; do not use Laravel migrations.

CREATE INDEX IF NOT EXISTS idx_brand_partners_category_id ON brand_partners(category_id);
CREATE INDEX IF NOT EXISTS idx_brand_partners_priority ON brand_partners(priority);
CREATE INDEX IF NOT EXISTS idx_brand_partners_is_featured ON brand_partners(is_featured);
CREATE INDEX IF NOT EXISTS idx_brand_partners_is_active ON brand_partners(is_active);
CREATE INDEX IF NOT EXISTS idx_brand_partners_is_verified ON brand_partners(is_verified);
CREATE INDEX IF NOT EXISTS idx_brand_partners_is_sponsored ON brand_partners(is_sponsored);
CREATE INDEX IF NOT EXISTS idx_brand_partners_slug ON brand_partners(slug);

CREATE INDEX IF NOT EXISTS idx_brand_partner_categories_sort_order ON brand_partner_categories(sort_order);
CREATE INDEX IF NOT EXISTS idx_brand_partner_categories_status ON brand_partner_categories(status);

CREATE INDEX IF NOT EXISTS idx_brand_partner_views_user_id ON brand_partner_views(user_id);
CREATE INDEX IF NOT EXISTS idx_brand_partner_views_brand_partner_id ON brand_partner_views(brand_partner_id);
CREATE INDEX IF NOT EXISTS idx_brand_partner_views_viewed_at ON brand_partner_views(viewed_at);

CREATE INDEX IF NOT EXISTS idx_brand_partner_clicks_user_id ON brand_partner_clicks(user_id);
CREATE INDEX IF NOT EXISTS idx_brand_partner_clicks_brand_partner_id ON brand_partner_clicks(brand_partner_id);
CREATE INDEX IF NOT EXISTS idx_brand_partner_clicks_click_type ON brand_partner_clicks(click_type);
CREATE INDEX IF NOT EXISTS idx_brand_partner_clicks_created_at ON brand_partner_clicks(created_at);

CREATE INDEX IF NOT EXISTS idx_brand_partner_saved_user_id ON brand_partner_saved(user_id);
CREATE INDEX IF NOT EXISTS idx_brand_partner_saved_brand_partner_id ON brand_partner_saved(brand_partner_id);
