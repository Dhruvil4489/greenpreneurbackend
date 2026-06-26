<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\User;
use App\Models\Role;
use App\Models\City;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class UsersValidationTest extends TestCase
{
    use DatabaseTransactions;

    protected AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'https://accounts.zoho.in/*' => Http::response([
                'access_token' => 'token-123',
                'expires_in' => 3600,
            ]),
            '*/plans*' => Http::response([
                'plans' => [
                    ['plan_code' => '012', 'name' => 'Unity Peer', 'price' => 200, 'interval' => 'month', 'status' => 'active'],
                ],
            ]),
            'https://subscriptions.zoho.in/api/v1/plans*' => Http::response([
                'plans' => [
                    ['plan_code' => '012', 'name' => 'Unity Peer', 'price' => 200, 'interval' => 'month', 'status' => 'active'],
                ],
            ]),
            'https://subscriptions.zoho.com/api/v1/plans*' => Http::response([
                'plans' => [
                    ['plan_code' => '012', 'name' => 'Unity Peer', 'price' => 200, 'interval' => 'month', 'status' => 'active'],
                ],
            ]),
            'https://www.zohoapis.in/*' => Http::response([
                'plans' => [
                    ['plan_code' => '012', 'name' => 'Unity Peer', 'price' => 200, 'interval' => 'month', 'status' => 'active'],
                ],
            ]),
        ]);

        $this->createTestSchemas();

        // Setup Admin User and authenticate
        $this->admin = AdminUser::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Super Administrator',
            'email' => 'admin@example.com',
        ]);

        $roleKeys = ['global_admin', 'industry_director', 'ded', 'circle_leader'];
        $globalAdminRoleId = null;
        foreach ($roleKeys as $k) {
            $role = new Role();
            $role->id = (string) Str::uuid();
            $role->name = ucfirst(str_replace('_', ' ', $k));
            $role->key = $k;
            $role->save();
            if ($k === 'global_admin') {
                $globalAdminRoleId = $role->id;
            }
        }

        $this->admin->roles()->attach($globalAdminRoleId);
        $this->actingAs($this->admin, 'admin');

        // Create dummy city
        \Illuminate\Support\Facades\DB::table('cities')->insert([
            'id' => (string) Str::uuid(),
            'name' => 'Ahmedabad',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createTestSchemas(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('email_logs');
        Schema::dropIfExists('admin_user_roles');
        Schema::dropIfExists('admin_users');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('circle_members');
        Schema::dropIfExists('joined_circle_categories');
        Schema::dropIfExists('users');
        Schema::dropIfExists('admin_audit_logs');
        Schema::dropIfExists('cities');

        Schema::create('cities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('first_name', 100);
            $table->string('last_name', 100)->nullable();
            $table->string('display_name', 150)->nullable();
            $table->string('email', 255)->unique();
            $table->string('phone', 20)->nullable()->unique();
            $table->string('password_hash');
            $table->string('company_name', 255)->nullable();
            $table->string('designation', 255)->nullable();
            $table->uuid('city_id')->nullable();
            $table->string('city', 255)->nullable();
            $table->string('status', 50)->default('active');
            $table->string('registration_source', 100)->nullable();
            $table->string('membership_status', 50)->default('visitor');
            $table->timestamp('membership_expiry')->nullable();
            $table->timestamp('membership_starts_at')->nullable();
            $table->timestamp('membership_ends_at')->nullable();
            $table->bigInteger('coins_balance')->default(0);
            $table->integer('life_impacted_count')->default(0);
            $table->string('public_profile_slug', 80)->nullable()->unique();
            $table->string('community_directory_listing', 10)->nullable();
            $table->string('website', 255)->nullable();
            $table->text('sustainability_contribution')->nullable();
            $table->json('sustainability_areas')->nullable();
            $table->json('greenpreneur_goals')->nullable();
            $table->json('interests')->nullable();
            $table->text('industry_tags')->nullable();
            $table->text('target_regions')->nullable();
            $table->text('target_business_categories')->nullable();
            $table->text('hobbies_interests')->nullable();
            $table->text('leadership_roles')->nullable();
            $table->text('special_recognitions')->nullable();
            $table->text('skills')->nullable();
            $table->text('social_links')->nullable();
            $table->boolean('is_sponsored_member')->default(false);
            $table->uuid('active_circle_id')->nullable();
            $table->string('active_circle_addon_code')->nullable();
            $table->string('active_circle_addon_name')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('admin_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('key')->unique();
            $table->timestamps();
        });

        Schema::create('admin_user_roles', function (Blueprint $table) {
            $table->uuid('user_id');
            $table->uuid('role_id');
            $table->primary(['user_id', 'role_id']);
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
    }

    public function test_admin_user_creation_fails_when_required_fields_are_empty(): void
    {
        $payload = [
            'first_name' => 'John',
            'email' => 'john.doe@example.com',
            // company_name, designation, and city are missing
        ];

        $response = $this->post(route('admin.users.store'), $payload);
        $response->assertSessionHasErrors(['company_name', 'designation', 'city']);
    }

    public function test_admin_user_creation_succeeds_when_required_fields_are_provided(): void
    {
        $payload = [
            'first_name' => 'John',
            'email' => 'john.doe@example.com',
            'company_name' => 'Acme Corp',
            'designation' => 'CEO',
            'city' => 'Ahmedabad',
            'status' => 'active',
            'membership_status' => 'free_peer',
            'community_directory_listing' => 'No',
        ];

        $response = $this->post(route('admin.users.store'), $payload);
        $response->assertRedirect(route('admin.users.index'));
        $this->assertDatabaseHas('users', [
            'email' => 'john.doe@example.com',
            'company_name' => 'Acme Corp',
            'designation' => 'CEO',
            'city' => 'Ahmedabad',
        ]);
    }

    public function test_admin_user_update_fails_when_required_fields_are_empty(): void
    {
        $user = User::query()->create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Acme User',
            'email' => 'acme.user@example.com',
            'password_hash' => 'dummy_hash',
            'company_name' => 'Old Corp',
            'designation' => 'Manager',
            'city' => 'Surat',
        ]);

        $payload = [
            'first_name' => 'Acme User',
            'email' => 'acme.user@example.com',
            'status' => 'active',
            'membership_status' => 'free_peer',
            'coins_balance' => 0,
            'life_impacted_count' => 0,
            // company_name, designation, and city are empty
            'company_name' => '',
            'designation' => '',
            'city' => '',
        ];

        $response = $this->put(route('admin.users.update', $user->id), $payload);
        $response->assertSessionHasErrors(['company_name', 'designation', 'city']);
    }

    public function test_admin_user_update_succeeds_when_required_fields_are_provided(): void
    {
        $user = User::query()->create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Acme User',
            'email' => 'acme.user@example.com',
            'password_hash' => 'dummy_hash',
            'company_name' => 'Old Corp',
            'designation' => 'Manager',
            'city' => 'Surat',
        ]);

        $payload = [
            'first_name' => 'Acme User Updated',
            'email' => 'acme.user@example.com',
            'status' => 'active',
            'membership_status' => 'free_peer',
            'coins_balance' => 0,
            'life_impacted_count' => 0,
            'company_name' => 'New Corp',
            'designation' => 'Director',
            'city' => 'Vadodara',
            'community_directory_listing' => 'Yes',
        ];

        $response = $this->put(route('admin.users.update', $user->id), $payload);
        $response->assertRedirect(route('admin.users.edit', $user->id));
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'first_name' => 'Acme User Updated',
            'company_name' => 'New Corp',
            'designation' => 'Director',
            'city' => 'Vadodara',
        ]);
    }
}
