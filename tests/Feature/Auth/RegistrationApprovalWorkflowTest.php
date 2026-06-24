<?php

namespace Tests\Feature\Auth;

use App\Models\AdminUser;
use App\Models\EmailLog;
use App\Models\AdminAuditLog;
use App\Models\User;
use App\Models\Role;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class RegistrationApprovalWorkflowTest extends TestCase
{
    use DatabaseTransactions;

    protected AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestSchemas();

        // Setup Admin User and authenticate
        $this->admin = AdminUser::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Super Administrator',
            'email' => 'admin@example.com',
        ]);

        $roleKeys = ['global_admin', 'industry_director', 'ded', 'circle_leader', 'chair', 'vice_chair', 'secretary', 'member'];
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

        // Auto-generate UUID for AdminAuditLog in tests since SQLite does not support gen_random_uuid() and id is not fillable
        \App\Models\AdminAuditLog::creating(function ($log) {
            if (empty($log->id)) {
                $log->id = (string) \Illuminate\Support\Str::uuid();
            }
        });
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

        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('admin_user_id')->nullable();
            $table->string('action');
            $table->string('target_table');
            $table->uuid('target_id');
            $table->json('details')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
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

    public function test_end_to_end_registration_approval_and_rejection(): void
    {
        Mail::fake();
        $this->withoutExceptionHandling();

        // 1. Register a new user
        $payload = [
            'first_name' => 'Workflow',
            'last_name' => 'Tester',
            'email' => 'workflow@example.com',
            'phone' => '9999999999',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        $registerResponse = $this->postJson('/api/v1/auth/register', $payload);
        $registerResponse->assertStatus(201);

        $this->assertDatabaseHas('users', [
            'email' => 'workflow@example.com',
            'status' => 'inactive',
            'registration_source' => 'App',
        ]);

        $user = User::where('email', 'workflow@example.com')->firstOrFail();

        // Verify registration request received email was logged/sent
        Mail::assertSent(\App\Mail\RegistrationRequestReceivedMail::class, function ($mail) use ($user) {
            return $mail->user->id === $user->id;
        });

        $this->assertDatabaseHas('email_logs', [
            'user_id' => $user->id,
            'template_key' => 'registration_request_received',
            'status' => 'sent',
        ]);

        // 2. Inactive user login restriction
        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'workflow@example.com',
            'password' => 'password123',
        ]);
        $loginResponse->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Your registration request is under review. You will receive an email once it is approved.');

        // 3. Inactive user OTP request restriction
        $otpRequestResponse = $this->postJson('/api/v1/auth/request-otp', [
            'email' => 'workflow@example.com',
        ]);
        $otpRequestResponse->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Your registration request is under review. You will receive an email once it is approved.');

        // 4. Admin visibility of inactive registration
        $this->actingAs($this->admin, 'admin');

        $adminIndexResponse = $this->get(route('admin.pending-registrations.index'));
        $adminIndexResponse->assertOk()
            ->assertSee('Workflow Tester')
            ->assertSee('workflow@example.com');

        // 5. Approve flow
        $approveResponse = $this->post(route('admin.pending-registrations.approve', $user->id));
        $approveResponse->assertRedirect();

        $user->refresh();
        $this->assertEquals('active', $user->status);

        // Verify approval email was sent & logged
        Mail::assertSent(\App\Mail\RegistrationApprovedMail::class, function ($mail) use ($user) {
            return $mail->user->id === $user->id;
        });

        $this->assertDatabaseHas('email_logs', [
            'user_id' => $user->id,
            'template_key' => 'registration_approved',
            'status' => 'sent',
        ]);

        // Verify admin audit logs
        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_user_id' => $this->admin->id,
            'action' => 'approve_registration',
            'target_table' => 'users',
            'target_id' => $user->id,
        ]);

        // 6. Login now allowed
        $loginResponse2 = $this->postJson('/api/v1/auth/login', [
            'email' => 'workflow@example.com',
            'password' => 'password123',
        ]);
        $loginResponse2->assertOk()
            ->assertJsonPath('success', true);

        // 7. Reject flow (reverting back to reject for test purposes)
        $rejectResponse = $this->post(route('admin.pending-registrations.reject', $user->id));
        $rejectResponse->assertRedirect();

        $user->refresh();
        $this->assertEquals('rejected', $user->status);

        // Verify rejection email was sent & logged
        Mail::assertSent(\App\Mail\RegistrationRejectedMail::class, function ($mail) use ($user) {
            return $mail->user->id === $user->id;
        });

        $this->assertDatabaseHas('email_logs', [
            'user_id' => $user->id,
            'template_key' => 'registration_rejected',
            'status' => 'sent',
        ]);

        // Verify admin audit logs for rejection
        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_user_id' => $this->admin->id,
            'action' => 'reject_registration',
            'target_table' => 'users',
            'target_id' => $user->id,
        ]);

        // 8. Rejected user login restriction
        $loginResponse3 = $this->postJson('/api/v1/auth/login', [
            'email' => 'workflow@example.com',
            'password' => 'password123',
        ]);
        $loginResponse3->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Your registration request has been rejected. Please contact support for further details.');
    }
}
