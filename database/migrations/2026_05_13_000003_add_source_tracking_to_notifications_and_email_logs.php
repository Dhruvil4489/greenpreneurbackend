<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notifications')) {
            DB::statement('ALTER TABLE notifications ADD COLUMN IF NOT EXISTS title VARCHAR(255) NULL');
            DB::statement('ALTER TABLE notifications ADD COLUMN IF NOT EXISTS message TEXT NULL');
            DB::statement('ALTER TABLE notifications ADD COLUMN IF NOT EXISTS source_type VARCHAR(100) NULL');
            DB::statement('ALTER TABLE notifications ADD COLUMN IF NOT EXISTS source_id UUID NULL');
            DB::statement('ALTER TABLE notifications ADD COLUMN IF NOT EXISTS source_event VARCHAR(100) NULL');
            DB::statement('CREATE INDEX IF NOT EXISTS notifications_source_type_source_id_index ON notifications (source_type, source_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS notifications_source_type_source_id_source_event_index ON notifications (source_type, source_id, source_event)');
        }

        if (Schema::hasTable('email_logs')) {
            DB::statement('ALTER TABLE email_logs ADD COLUMN IF NOT EXISTS user_id UUID NULL');
            DB::statement('ALTER TABLE email_logs ADD COLUMN IF NOT EXISTS to_name VARCHAR(255) NULL');
            DB::statement('ALTER TABLE email_logs ADD COLUMN IF NOT EXISTS subject VARCHAR(255) NULL');
            DB::statement('ALTER TABLE email_logs ADD COLUMN IF NOT EXISTS source_module VARCHAR(100) NULL');
            DB::statement('ALTER TABLE email_logs ADD COLUMN IF NOT EXISTS related_type VARCHAR(255) NULL');
            DB::statement('ALTER TABLE email_logs ADD COLUMN IF NOT EXISTS related_id VARCHAR(100) NULL');
            DB::statement('ALTER TABLE email_logs ADD COLUMN IF NOT EXISTS body_html TEXT NULL');
            DB::statement('ALTER TABLE email_logs ADD COLUMN IF NOT EXISTS error_message TEXT NULL');
            DB::statement('ALTER TABLE email_logs ADD COLUMN IF NOT EXISTS source_type VARCHAR(100) NULL');
            DB::statement('ALTER TABLE email_logs ADD COLUMN IF NOT EXISTS source_id UUID NULL');
            DB::statement('ALTER TABLE email_logs ADD COLUMN IF NOT EXISTS source_event VARCHAR(100) NULL');
            DB::statement('CREATE INDEX IF NOT EXISTS email_logs_source_type_source_id_index ON email_logs (source_type, source_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS email_logs_to_email_source_type_source_id_source_event_index ON email_logs (to_email, source_type, source_id, source_event)');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('notifications')) {
            DB::statement('DROP INDEX IF EXISTS notifications_source_type_source_id_source_event_index');
            DB::statement('DROP INDEX IF EXISTS notifications_source_type_source_id_index');
            DB::statement('ALTER TABLE notifications DROP COLUMN IF EXISTS source_event');
            DB::statement('ALTER TABLE notifications DROP COLUMN IF EXISTS source_id');
            DB::statement('ALTER TABLE notifications DROP COLUMN IF EXISTS source_type');
            DB::statement('ALTER TABLE notifications DROP COLUMN IF EXISTS message');
            DB::statement('ALTER TABLE notifications DROP COLUMN IF EXISTS title');
        }

        if (Schema::hasTable('email_logs')) {
            DB::statement('DROP INDEX IF EXISTS email_logs_to_email_source_type_source_id_source_event_index');
            DB::statement('DROP INDEX IF EXISTS email_logs_source_type_source_id_index');
            DB::statement('ALTER TABLE email_logs DROP COLUMN IF EXISTS source_event');
            DB::statement('ALTER TABLE email_logs DROP COLUMN IF EXISTS source_id');
            DB::statement('ALTER TABLE email_logs DROP COLUMN IF EXISTS source_type');
        }
    }
};
