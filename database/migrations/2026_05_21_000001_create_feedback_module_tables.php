<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('feedback_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('feedback_forms', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('category_id')->constrained('feedback_categories')->cascadeOnDelete();
            $table->string('category', 100);
            $table->string('subject', 255);
            $table->text('question');
            $table->string('status', 50)->default('submitted');
            $table->timestamps();
        });

        Schema::create('feedback_media', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('feedback_form_id')->constrained('feedback_forms')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('file_url');
            $table->string('file_type', 50);
            $table->string('mime_type', 100);
            $table->string('original_name');
            $table->unsignedBigInteger('file_size');
            $table->timestamps();
        });

        $now = now();
        DB::table('feedback_categories')->insert([
            ['id' => (string) Str::uuid(), 'name' => 'General Inquiry', 'is_active' => true, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => (string) Str::uuid(), 'name' => 'Technical Support', 'is_active' => true, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['id' => (string) Str::uuid(), 'name' => 'Account Issues', 'is_active' => true, 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['id' => (string) Str::uuid(), 'name' => 'Feature Request', 'is_active' => true, 'sort_order' => 4, 'created_at' => $now, 'updated_at' => $now],
            ['id' => (string) Str::uuid(), 'name' => 'Feedback', 'is_active' => true, 'sort_order' => 5, 'created_at' => $now, 'updated_at' => $now],
            ['id' => (string) Str::uuid(), 'name' => 'Other', 'is_active' => true, 'sort_order' => 6, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_media');
        Schema::dropIfExists('feedback_forms');
        Schema::dropIfExists('feedback_categories');
    }
};
