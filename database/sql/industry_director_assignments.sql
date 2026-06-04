CREATE EXTENSION IF NOT EXISTS pgcrypto;

CREATE TABLE IF NOT EXISTS industry_director_assignments (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    admin_user_id UUID NOT NULL REFERENCES admin_users(id) ON DELETE CASCADE,
    industry_id UUID NOT NULL,
    assigned_by UUID NULL REFERENCES admin_users(id) ON DELETE SET NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    UNIQUE (admin_user_id)
);

CREATE INDEX IF NOT EXISTS idx_ide_assignments_admin_user_id
ON industry_director_assignments(admin_user_id);

CREATE INDEX IF NOT EXISTS idx_ide_assignments_industry_id
ON industry_director_assignments(industry_id);

CREATE INDEX IF NOT EXISTS idx_ide_assignments_is_active
ON industry_director_assignments(is_active);
