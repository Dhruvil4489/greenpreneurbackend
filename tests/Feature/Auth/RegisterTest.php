<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
    }

    public function test_users_with_same_name_can_register(): void
    {
        $firstPayload = [
            'first_name'   => 'Pravin',
            'last_name'    => 'Parmar',
            'email'        => 'user1@example.com',
            'phone'        => '1111111111',
            'password'     => 'password123',
            'password_confirmation' => 'password123',
        ];

        $secondPayload = [
            'first_name'   => 'Pravin',
            'last_name'    => 'Parmar',
            'email'        => 'user2@example.com',
            'phone'        => '2222222222',
            'password'     => 'password123',
            'password_confirmation' => 'password123',
        ];

        $firstResponse = $this->postJson('/api/v1/auth/register', $firstPayload);
        $firstResponse->assertStatus(201)->assertJson(['success' => true]);

        $secondResponse = $this->postJson('/api/v1/auth/register', $secondPayload);
        $secondResponse->assertStatus(201)->assertJson(['success' => true]);

        $this->assertNotSame(
            $firstResponse->json('data.user.public_profile_slug'),
            $secondResponse->json('data.user.public_profile_slug'),
            'Users with the same name should receive different profile slugs.'
        );
    }


    public function test_registration_assigns_free_trial_membership_for_new_users(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-23 09:00:00'));

        $payload = [
            'first_name' => 'Trial',
            'last_name' => 'User',
            'email' => 'trial-user@example.com',
            'phone' => '4444444444',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.user.membership_status', User::STATUS_FREE_TRIAL)
            ->assertJsonPath('data.user.status', 'inactive');

        $this->assertDatabaseHas('users', [
            'email' => 'trial-user@example.com',
            'membership_status' => User::STATUS_FREE_TRIAL,
            'status' => 'inactive',
            'registration_source' => 'App',
        ]);

        $user = User::query()->where('email', 'trial-user@example.com')->firstOrFail();

        $this->assertTrue($user->membership_starts_at->equalTo(now()));
        $this->assertTrue($user->membership_ends_at->equalTo(now()->copy()->addDays(3)));
        $this->assertTrue($user->membership_expiry->equalTo(now()->copy()->addDays(3)));

        Carbon::setTestNow();
    }

    public function test_registration_rejects_duplicate_email(): void
    {
        $payload = [
            'first_name'   => 'Alex',
            'last_name'    => 'Smith',
            'email'        => 'duplicate@example.com',
            'phone'        => '3333333333',
            'password'     => 'password123',
            'password_confirmation' => 'password123',
        ];

        $this->postJson('/api/v1/auth/register', $payload)->assertStatus(201);

        $duplicateEmailResponse = $this->postJson('/api/v1/auth/register', $payload);
        $duplicateEmailResponse->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_registration_with_new_fields_fully_filled(): void
    {
        $payload = [
            'first_name' => 'Green',
            'last_name' => 'Preneur',
            'email' => 'greenpreneur-full@example.com',
            'phone' => '9999999999',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'website' => 'https://sustainability.com',
            'sustainability_contribution' => 'Our business uses 100% solar power.',
            'sustainability_areas' => ['Renewable Energy', 'Waste Management'],
            'greenpreneur_goals' => ['Business Growth', 'Partnerships'],
            'interests' => ['Speaking Opportunities', 'Panel Discussions'],
            'community_directory_listing' => 'Yes',
        ];

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.user.website', 'https://sustainability.com')
            ->assertJsonPath('data.user.sustainability_contribution', 'Our business uses 100% solar power.')
            ->assertJsonPath('data.user.sustainability_areas', ['Renewable Energy', 'Waste Management'])
            ->assertJsonPath('data.user.greenpreneur_goals', ['Business Growth', 'Partnerships'])
            ->assertJsonPath('data.user.interests', ['Speaking Opportunities', 'Panel Discussions'])
            ->assertJsonPath('data.user.community_directory_listing', 'Yes');

        $this->assertDatabaseHas('users', [
            'email' => 'greenpreneur-full@example.com',
            'website' => 'https://sustainability.com',
            'sustainability_contribution' => 'Our business uses 100% solar power.',
            'community_directory_listing' => 'Yes',
        ]);

        $user = User::where('email', 'greenpreneur-full@example.com')->firstOrFail();
        $this->assertEquals(['Renewable Energy', 'Waste Management'], $user->sustainability_areas);
        $this->assertEquals(['Business Growth', 'Partnerships'], $user->greenpreneur_goals);
        $this->assertEquals(['Speaking Opportunities', 'Panel Discussions'], $user->interests);
    }

    public function test_registration_with_new_fields_partially_filled(): void
    {
        $payload = [
            'first_name' => 'Green',
            'last_name' => 'Preneur',
            'email' => 'greenpreneur-partial@example.com',
            'phone' => '8888888888',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'website' => 'https://solar.com',
            // Omitted: sustainability_contribution, sustainability_areas, greenpreneur_goals, interests, community_directory_listing
        ];

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.user.website', 'https://solar.com')
            ->assertJsonPath('data.user.sustainability_contribution', null)
            ->assertJsonPath('data.user.sustainability_areas', [])
            ->assertJsonPath('data.user.greenpreneur_goals', [])
            ->assertJsonPath('data.user.interests', [])
            ->assertJsonPath('data.user.community_directory_listing', 'No'); // Should default to No

        $this->assertDatabaseHas('users', [
            'email' => 'greenpreneur-partial@example.com',
            'website' => 'https://solar.com',
            'sustainability_contribution' => null,
            'community_directory_listing' => 'No',
        ]);
    }

    private function setUpInMemoryDatabase(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('users');
        Schema::dropIfExists('circle_members');
        Schema::dropIfExists('email_logs');
        Schema::dropIfExists('joined_circle_categories');

        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('first_name', 100);
            $table->string('last_name', 100)->nullable();
            $table->string('display_name', 150)->nullable();
            $table->string('email', 255)->unique();
            $table->string('phone', 20)->nullable()->unique();
            $table->string('password_hash');
            $table->string('company_name', 150)->nullable();
            $table->string('designation', 100)->nullable();
            $table->uuid('city_id')->nullable();
            $table->string('status', 50)->default('inactive');
            $table->string('registration_source', 100)->nullable();
            $table->string('membership_status', 50)->default('visitor');
            $table->timestamp('membership_expiry')->nullable();
            $table->timestamp('membership_starts_at')->nullable();
            $table->timestamp('membership_ends_at')->nullable();
            $table->bigInteger('coins_balance')->default(0);
            $table->string('public_profile_slug', 80)->nullable()->unique();
            $table->string('website', 255)->nullable();
            $table->text('sustainability_contribution')->nullable();
            $table->json('sustainability_areas')->nullable();
            $table->json('greenpreneur_goals')->nullable();
            $table->json('interests')->nullable();
            $table->string('community_directory_listing', 10)->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('tokenable_type');
            $table->uuid('tokenable_id');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['tokenable_type', 'tokenable_id']);
        });

        Schema::create('circle_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('circle_id');
            $table->uuid('user_id');
            $table->string('role', 50)->default('member');
            $table->string('status', 50)->default('approved');
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('email_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable();
            $table->string('to_email');
            $table->string('to_name')->nullable();
            $table->string('template_key')->nullable();
            $table->string('subject')->nullable();
            $table->string('source_module')->nullable();
            $table->string('related_type')->nullable();
            $table->string('related_id')->nullable();
            $table->string('source_type')->nullable();
            $table->string('source_id')->nullable();
            $table->string('source_event')->nullable();
            $table->string('status');
            $table->text('body_html')->nullable();
            $table->text('payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('joined_circle_categories', function (Blueprint $table) {
            $table->id();
            $table->uuid('user_id');
            $table->uuid('circle_id');
            $table->uuid('circle_member_id');
            $table->integer('level1_category_id')->nullable();
            $table->integer('level2_category_id')->nullable();
            $table->integer('level3_category_id')->nullable();
            $table->integer('level4_category_id')->nullable();
            $table->timestamps();
        });
    }
}
