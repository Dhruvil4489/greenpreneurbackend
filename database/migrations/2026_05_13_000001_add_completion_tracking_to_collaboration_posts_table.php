<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('collaboration_posts')) {
            return;
        }

        Schema::table('collaboration_posts', function (Blueprint $table): void {
            if (! Schema::hasColumn('collaboration_posts', 'completion_status')) {
                $table->string('completion_status')->default('incomplete')->after('status');
            }

            if (! Schema::hasColumn('collaboration_posts', 'completed_at')) {
                $table->timestampTz('completed_at')->nullable()->after('completion_status');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('collaboration_posts')) {
            return;
        }

        Schema::table('collaboration_posts', function (Blueprint $table): void {
            if (Schema::hasColumn('collaboration_posts', 'completed_at')) {
                $table->dropColumn('completed_at');
            }

            if (Schema::hasColumn('collaboration_posts', 'completion_status')) {
                $table->dropColumn('completion_status');
            }
        });
    }
};
