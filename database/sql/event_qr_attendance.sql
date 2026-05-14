-- Manual SQL for Event + QR Attendance. Run manually; this is intentionally not a Laravel migration.

ALTER TABLE events
    ADD COLUMN IF NOT EXISTS event_category VARCHAR(100),
    ADD COLUMN IF NOT EXISTS mode VARCHAR(20) NOT NULL DEFAULT 'offline',
    ADD COLUMN IF NOT EXISTS recurrence_type VARCHAR(20) NOT NULL DEFAULT 'none',
    ADD COLUMN IF NOT EXISTS recurrence_interval INTEGER NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS recurrence_day_of_week INTEGER,
    ADD COLUMN IF NOT EXISTS recurrence_week_of_month INTEGER,
    ADD COLUMN IF NOT EXISTS recurrence_day_of_month INTEGER,
    ADD COLUMN IF NOT EXISTS recurrence_month INTEGER,
    ADD COLUMN IF NOT EXISTS recurrence_ends_at TIMESTAMPTZ;

CREATE TABLE IF NOT EXISTS event_occurrences (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    event_id UUID NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    start_at TIMESTAMPTZ NOT NULL,
    end_at TIMESTAMPTZ,
    status VARCHAR(30) NOT NULL DEFAULT 'scheduled',
    sequence INTEGER NOT NULL DEFAULT 1,
    metadata JSONB,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at TIMESTAMPTZ,
    CONSTRAINT uq_event_occurrence_start UNIQUE (event_id, start_at)
);

CREATE INDEX IF NOT EXISTS idx_event_occurrences_event_id ON event_occurrences(event_id);
CREATE INDEX IF NOT EXISTS idx_event_occurrences_start_at ON event_occurrences(start_at);

CREATE TABLE IF NOT EXISTS event_registrations (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    event_id UUID NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    occurrence_id UUID NOT NULL REFERENCES event_occurrences(id) ON DELETE CASCADE,
    user_id UUID REFERENCES users(id) ON DELETE CASCADE,
    qr_token TEXT NOT NULL UNIQUE,
    qr_code_path TEXT,
    qr_code_svg TEXT,
    status VARCHAR(30) NOT NULL DEFAULT 'registered',
    checkin_status VARCHAR(30) NOT NULL DEFAULT 'pending',
    registered_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    checked_in_at TIMESTAMPTZ,
    checked_in_by_user_id UUID REFERENCES users(id) ON DELETE SET NULL,
    source VARCHAR(30) NOT NULL DEFAULT 'app',
    visitor_name VARCHAR(255),
    visitor_email VARCHAR(255),
    visitor_phone VARCHAR(50),
    visitor_company VARCHAR(255),
    visitor_city VARCHAR(255),
    zoho_form_entry_id VARCHAR(255),
    zoho_payment_id VARCHAR(255),
    zoho_payment_status VARCHAR(100),
    metadata JSONB,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at TIMESTAMPTZ
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_event_registration_member_occurrence
    ON event_registrations(occurrence_id, user_id)
    WHERE user_id IS NOT NULL AND deleted_at IS NULL AND status <> 'cancelled';

CREATE UNIQUE INDEX IF NOT EXISTS uq_event_registration_zoho_entry
    ON event_registrations(occurrence_id, zoho_form_entry_id)
    WHERE zoho_form_entry_id IS NOT NULL AND deleted_at IS NULL AND status <> 'cancelled';

CREATE INDEX IF NOT EXISTS idx_event_registrations_event_id ON event_registrations(event_id);
CREATE INDEX IF NOT EXISTS idx_event_registrations_occurrence_id ON event_registrations(occurrence_id);
CREATE INDEX IF NOT EXISTS idx_event_registrations_user_id ON event_registrations(user_id);
CREATE INDEX IF NOT EXISTS idx_event_registrations_qr_token ON event_registrations(qr_token);
CREATE INDEX IF NOT EXISTS idx_event_registrations_checkin_status ON event_registrations(checkin_status);
