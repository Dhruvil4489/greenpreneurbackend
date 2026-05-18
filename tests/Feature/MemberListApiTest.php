<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MemberListApiTest extends TestCase
{
    public function test_members_index_returns_life_impacted_count_from_users_table(): void
    {
        $this->createSchema();

        $authUser = User::query()->create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Auth',
            'display_name' => 'Auth User',
            'email' => 'auth@example.com',
            'status' => 'active',
            'life_impacted_count' => 0,
        ]);

        $member = User::query()->create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Chirag',
            'display_name' => 'Chirag',
            'email' => 'chirag@example.com',
            'status' => 'active',
            'life_impacted_count' => 10,
        ]);

        Sanctum::actingAs($authUser);

        $response = $this->getJson('/api/v1/members?per_page=10')
            ->assertOk();

        $memberPayload = collect($response->json('data'))
            ->firstWhere('id', $member->id);

        $this->assertSame(10, $memberPayload['life_impacted_count']);
    }

    public function test_members_index_returns_zero_when_users_life_impacted_count_is_null(): void
    {
        $this->createSchema();

        $authUser = User::query()->create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Auth',
            'display_name' => 'Auth User',
            'email' => 'auth@example.com',
            'status' => 'active',
            'life_impacted_count' => 0,
        ]);

        $member = User::query()->create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Null Count',
            'display_name' => 'Null Count',
            'email' => 'null-count@example.com',
            'status' => 'active',
            'life_impacted_count' => null,
        ]);

        Sanctum::actingAs($authUser);

        $response = $this->getJson('/api/v1/members?per_page=10')
            ->assertOk();

        $memberPayload = collect($response->json('data'))
            ->firstWhere('id', $member->id);

        $this->assertSame(0, $memberPayload['life_impacted_count']);
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('circle_subscriptions');
        Schema::dropIfExists('circle_members');
        Schema::dropIfExists('circles');
        Schema::dropIfExists('peer_blocks');
        Schema::dropIfExists('user_follows');
        Schema::dropIfExists('cities');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('public_profile_slug')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('display_name')->nullable();
            $table->string('company_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('password_hash')->nullable();
            $table->string('membership_status')->nullable();
            $table->integer('coins_balance')->nullable();
            $table->integer('life_impacted_count')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->uuid('profile_photo_file_id')->nullable();
            $table->json('media')->nullable();
            $table->uuid('city_id')->nullable();
            $table->string('city')->nullable();
            $table->string('business_type')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('cities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
        });

        Schema::create('user_follows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('follower_id');
            $table->uuid('following_id');
            $table->timestamps();
        });

        Schema::create('peer_blocks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('blocker_user_id');
            $table->uuid('blocked_user_id');
            $table->string('reason')->nullable();
            $table->timestamps();
        });

        Schema::create('circles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->nullable();
        });

        Schema::create('circle_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('circle_id');
            $table->string('status')->nullable();
            $table->string('role')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamp('paid_ends_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->string('joined_via')->nullable();
            $table->string('payment_status')->nullable();
            $table->string('zoho_addon_code')->nullable();
            $table->string('addon_name')->nullable();
            $table->uuid('circle_subscription_id')->nullable();
            $table->string('subscription_status')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('circle_subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable();
            $table->uuid('circle_id')->nullable();
            $table->string('zoho_addon_code')->nullable();
            $table->string('zoho_addon_name')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }
}
