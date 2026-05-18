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
    ADD COLUMN IF NOT EXISTS recurrence_ends_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS visitor_registration_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS member_registration_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    ADD COLUMN IF NOT EXISTS online_meeting_url TEXT,
    ADD COLUMN IF NOT EXISTS zoho_form_url TEXT;

CREATE TABLE IF NOT EXISTS event_occurrences (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    event_id UUID NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    occurrence_date DATE NOT NULL,
    start_at TIMESTAMPTZ NOT NULL,
    end_at TIMESTAMPTZ,
    status VARCHAR(30) NOT NULL DEFAULT 'scheduled',
    sequence INTEGER NOT NULL DEFAULT 1,
    registration_limit INTEGER,
    registered_count INTEGER NOT NULL DEFAULT 0,
    checked_in_count INTEGER NOT NULL DEFAULT 0,
    metadata JSONB,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at TIMESTAMPTZ,
    CONSTRAINT uq_event_occurrence_date UNIQUE (event_id, occurrence_date)
);

ALTER TABLE event_occurrences ADD COLUMN IF NOT EXISTS occurrence_date DATE;
UPDATE event_occurrences SET occurrence_date = start_at::date WHERE occurrence_date IS NULL;
ALTER TABLE event_occurrences ALTER COLUMN occurrence_date SET NOT NULL;
ALTER TABLE event_occurrences ADD COLUMN IF NOT EXISTS registration_limit INTEGER;
ALTER TABLE event_occurrences ADD COLUMN IF NOT EXISTS registered_count INTEGER NOT NULL DEFAULT 0;
ALTER TABLE event_occurrences ADD COLUMN IF NOT EXISTS checked_in_count INTEGER NOT NULL DEFAULT 0;

CREATE UNIQUE INDEX IF NOT EXISTS uq_event_occurrences_event_date ON event_occurrences(event_id, occurrence_date) WHERE deleted_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_event_occurrences_event_id ON event_occurrences(event_id);
CREATE INDEX IF NOT EXISTS idx_event_occurrences_occurrence_date ON event_occurrences(occurrence_date);
CREATE INDEX IF NOT EXISTS idx_event_occurrences_start_at ON event_occurrences(start_at);

CREATE TABLE IF NOT EXISTS event_registrations (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    event_id UUID NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    occurrence_id UUID NOT NULL REFERENCES event_occurrences(id) ON DELETE CASCADE,
    user_id UUID REFERENCES users(id) ON DELETE CASCADE,
    qr_token TEXT NOT NULL UNIQUE,
    qr_code_path TEXT,
    qr_code_url TEXT,
    qr_code_svg TEXT,
    qr_generated_at TIMESTAMPTZ,
    last_qr_scan_at TIMESTAMPTZ,
    scan_device_info TEXT,
    attendance_source VARCHAR(50),
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

ALTER TABLE event_registrations ADD COLUMN IF NOT EXISTS qr_code_url TEXT;
ALTER TABLE event_registrations ADD COLUMN IF NOT EXISTS qr_generated_at TIMESTAMPTZ;
ALTER TABLE event_registrations ADD COLUMN IF NOT EXISTS last_qr_scan_at TIMESTAMPTZ;
ALTER TABLE event_registrations ADD COLUMN IF NOT EXISTS scan_device_info TEXT;
ALTER TABLE event_registrations ADD COLUMN IF NOT EXISTS attendance_source VARCHAR(50);
ALTER TABLE event_registrations ADD COLUMN IF NOT EXISTS payment_required BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE event_registrations ADD COLUMN IF NOT EXISTS payment_status VARCHAR(30) NOT NULL DEFAULT 'not_required';
ALTER TABLE event_registrations ADD COLUMN IF NOT EXISTS amount NUMERIC(12,2) NOT NULL DEFAULT 0;
ALTER TABLE event_registrations ADD COLUMN IF NOT EXISTS currency VARCHAR(10) NOT NULL DEFAULT 'INR';
ALTER TABLE event_registrations ADD COLUMN IF NOT EXISTS zoho_customer_id VARCHAR(255);
ALTER TABLE event_registrations ADD COLUMN IF NOT EXISTS zoho_hosted_page_id VARCHAR(255);
ALTER TABLE event_registrations ADD COLUMN IF NOT EXISTS zoho_checkout_url TEXT;
ALTER TABLE event_registrations ADD COLUMN IF NOT EXISTS zoho_invoice_id VARCHAR(255);
ALTER TABLE event_registrations ADD COLUMN IF NOT EXISTS zoho_invoice_number VARCHAR(255);
ALTER TABLE event_registrations ADD COLUMN IF NOT EXISTS payment_completed_at TIMESTAMPTZ;
ALTER TABLE event_registrations ADD COLUMN IF NOT EXISTS registration_type VARCHAR(30);
CREATE INDEX IF NOT EXISTS idx_event_registrations_payment_status ON event_registrations(payment_status);
CREATE INDEX IF NOT EXISTS idx_event_registrations_zoho_hosted_page_id ON event_registrations(zoho_hosted_page_id);
CREATE INDEX IF NOT EXISTS idx_event_registrations_zoho_invoice_id ON event_registrations(zoho_invoice_id);
ALTER TABLE event_registrations ADD COLUMN IF NOT EXISTS razorpay_order_id VARCHAR(255);
ALTER TABLE event_registrations ADD COLUMN IF NOT EXISTS razorpay_payment_id VARCHAR(255);
ALTER TABLE event_registrations ADD COLUMN IF NOT EXISTS razorpay_signature TEXT;
ALTER TABLE event_registrations ADD COLUMN IF NOT EXISTS razorpay_payment_status VARCHAR(50);
ALTER TABLE event_registrations ADD COLUMN IF NOT EXISTS razorpay_paid_at TIMESTAMPTZ;
ALTER TABLE event_registrations ADD COLUMN IF NOT EXISTS zoho_invoice_url TEXT;
ALTER TABLE event_registrations ADD COLUMN IF NOT EXISTS zoho_invoice_pdf_url TEXT;
ALTER TABLE event_registrations ADD COLUMN IF NOT EXISTS zoho_invoice_synced_at TIMESTAMPTZ;
ALTER TABLE event_registrations ADD COLUMN IF NOT EXISTS zoho_invoice_sync_error TEXT;
CREATE INDEX IF NOT EXISTS idx_event_registrations_razorpay_order_id ON event_registrations(razorpay_order_id);
CREATE INDEX IF NOT EXISTS idx_event_registrations_razorpay_payment_id ON event_registrations(razorpay_payment_id);

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
