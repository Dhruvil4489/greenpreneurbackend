<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('posts')) {
            return;
        }

        DB::statement('ALTER TABLE posts ADD COLUMN IF NOT EXISTS source_type VARCHAR(100) NULL');
        DB::statement('ALTER TABLE posts ADD COLUMN IF NOT EXISTS source_id UUID NULL');
        DB::statement('ALTER TABLE posts ADD COLUMN IF NOT EXISTS source_event VARCHAR(100) NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS posts_source_type_source_id_index ON posts (source_type, source_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS posts_source_type_source_id_source_event_index ON posts (source_type, source_id, source_event)');
    }

    public function down(): void
    {
        if (! Schema::hasTable('posts')) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS posts_source_type_source_id_source_event_index');
        DB::statement('DROP INDEX IF EXISTS posts_source_type_source_id_index');
        DB::statement('ALTER TABLE posts DROP COLUMN IF EXISTS source_event');
        DB::statement('ALTER TABLE posts DROP COLUMN IF EXISTS source_id');
        DB::statement('ALTER TABLE posts DROP COLUMN IF EXISTS source_type');
    }
};
