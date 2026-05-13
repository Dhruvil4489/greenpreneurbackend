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

        DB::statement('ALTER TABLE posts ADD COLUMN IF NOT EXISTS active BOOLEAN NOT NULL DEFAULT TRUE');
    }

    public function down(): void
    {
        if (! Schema::hasTable('posts')) {
            return;
        }

        DB::statement('ALTER TABLE posts DROP COLUMN IF EXISTS active');
    }
};
