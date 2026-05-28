<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 50)->default('zoho');
            $table->string('event_type', 100)->nullable();
            $table->string('external_event_id', 150)->nullable();
            $table->string('payment_link_id', 100)->nullable();
            $table->string('payment_id', 100)->nullable();
            $table->uuid('registration_id')->nullable();
            $table->string('status', 30)->default('received');
            $table->json('payload');
            $table->json('headers')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index('provider');
            $table->index('event_type');
            $table->index('payment_link_id');
            $table->index('payment_id');
            $table->index('registration_id');
            $table->index('status');
            $table->index('created_at');
            $table->unique(['provider', 'external_event_id'], 'webhook_provider_external_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
