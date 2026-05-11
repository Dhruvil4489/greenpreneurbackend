<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'business_logo_id')) {
                $table->uuid('business_logo_id')->nullable();
            }

            if (! Schema::hasColumn('users', 'state')) {
                $table->string('state', 100)->nullable();
            }

            if (! Schema::hasColumn('users', 'country')) {
                $table->string('country', 100)->nullable()->default('India');
            }

            if (! Schema::hasColumn('users', 'preferred_language')) {
                $table->string('preferred_language', 50)->nullable();
            }

            if (! Schema::hasColumn('users', 'business_category_id')) {
                $table->uuid('business_category_id')->nullable();
            }

            if (! Schema::hasColumn('users', 'business_sub_category')) {
                $table->string('business_sub_category', 255)->nullable();
            }

            if (! Schema::hasColumn('users', 'company_type')) {
                $table->string('company_type', 100)->nullable();
            }

            if (! Schema::hasColumn('users', 'year_of_establishment')) {
                $table->integer('year_of_establishment')->nullable();
            }

            if (! Schema::hasColumn('users', 'annual_revenue_range')) {
                $table->string('annual_revenue_range', 100)->nullable();
            }

            if (! Schema::hasColumn('users', 'number_of_employees')) {
                $table->string('number_of_employees', 50)->nullable();
            }

            if (! Schema::hasColumn('users', 'gst_number')) {
                $table->string('gst_number', 30)->nullable();
            }

            if (! Schema::hasColumn('users', 'business_website')) {
                $table->string('business_website', 500)->nullable();
            }

            if (! Schema::hasColumn('users', 'superpower')) {
                $table->string('superpower', 100)->nullable();
            }

            if (! Schema::hasColumn('users', 'i_can_help_with')) {
                $table->jsonb('i_can_help_with')->nullable();
            }

            if (! Schema::hasColumn('users', 'i_am_looking_for')) {
                $table->jsonb('i_am_looking_for')->nullable();
            }

            if (! Schema::hasColumn('users', 'business_keywords')) {
                $table->jsonb('business_keywords')->nullable();
            }

            if (! Schema::hasColumn('users', 'products_services_offered')) {
                $table->text('products_services_offered')->nullable();
            }

            if (! Schema::hasColumn('users', 'secondary_mobile')) {
                $table->string('secondary_mobile', 30)->nullable();
            }

            if (! Schema::hasColumn('users', 'linkedin_profile')) {
                $table->string('linkedin_profile', 500)->nullable();
            }

            if (! Schema::hasColumn('users', 'instagram_handle')) {
                $table->string('instagram_handle', 255)->nullable();
            }

            if (! Schema::hasColumn('users', 'twitter_handle')) {
                $table->string('twitter_handle', 255)->nullable();
            }

            if (! Schema::hasColumn('users', 'facebook_profile')) {
                $table->string('facebook_profile', 500)->nullable();
            }

            if (! Schema::hasColumn('users', 'youtube_channel')) {
                $table->string('youtube_channel', 500)->nullable();
            }

            if (! Schema::hasColumn('users', 'other_website')) {
                $table->string('other_website', 500)->nullable();
            }

            if (! Schema::hasColumn('users', 'contact_visibility')) {
                $table->string('contact_visibility', 50)->nullable()->default('connections');
            }

            if (! Schema::hasColumn('users', 'business_address')) {
                $table->text('business_address')->nullable();
            }

            if (! Schema::hasColumn('users', 'business_city')) {
                $table->string('business_city', 100)->nullable();
            }

            if (! Schema::hasColumn('users', 'business_state')) {
                $table->string('business_state', 100)->nullable();
            }

            if (! Schema::hasColumn('users', 'business_pincode')) {
                $table->string('business_pincode', 20)->nullable();
            }

            if (! Schema::hasColumn('users', 'business_country')) {
                $table->string('business_country', 100)->nullable()->default('India');
            }

            if (! Schema::hasColumn('users', 'google_maps_latitude')) {
                $table->decimal('google_maps_latitude', 10, 7)->nullable();
            }

            if (! Schema::hasColumn('users', 'google_maps_longitude')) {
                $table->decimal('google_maps_longitude', 10, 7)->nullable();
            }

            if (! Schema::hasColumn('users', 'industries_of_interest')) {
                $table->jsonb('industries_of_interest')->nullable();
            }

            if (! Schema::hasColumn('users', 'collaboration_goals')) {
                $table->jsonb('collaboration_goals')->nullable();
            }

            if (! Schema::hasColumn('users', 'preferred_meeting_format')) {
                $table->string('preferred_meeting_format', 50)->nullable();
            }

            if (! Schema::hasColumn('users', 'willing_to_mentor')) {
                $table->boolean('willing_to_mentor')->nullable()->default(false);
            }

            if (! Schema::hasColumn('users', 'open_to_cross_city_collaboration')) {
                $table->boolean('open_to_cross_city_collaboration')->nullable()->default(false);
            }

            if (! Schema::hasColumn('users', 'open_to_speaking_at_events')) {
                $table->boolean('open_to_speaking_at_events')->nullable()->default(false);
            }
        });
    }

    public function down(): void
    {
        // Intentionally no-op: this migration is additive and guards every column
        // with Schema::hasColumn(). Avoid dropping profile columns that may have
        // existed before this migration on live databases.
    }

};
