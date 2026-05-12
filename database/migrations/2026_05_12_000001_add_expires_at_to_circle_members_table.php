<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('circle_members', 'expires_at')) {
            Schema::table('circle_members', function (Blueprint $table): void {
                $table->timestampTz('expires_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('circle_members', 'expires_at')) {
            Schema::table('circle_members', function (Blueprint $table): void {
                $table->dropColumn('expires_at');
            });
        }
    }
};
