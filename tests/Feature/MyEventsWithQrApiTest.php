<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventRegistration;
use App\Models\ScanAppUser;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MyEventsWithQrApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        config(['app.url' => 'http://127.0.0.1:8000']);
    }

    public function test_unity_user_can_fetch_registered_events_with_qr_details(): void
    {
        $user = User::query()->create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Unity',
            'last_name' => 'User',
            'display_name' => 'Unity User',
            'email' => 'unity@example.com',
            'phone' => '9999999999',
            'password_hash' => Hash::make('password'),
        ]);
        $otherUser = User::query()->create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Other',
            'email' => 'other@example.com',
            'phone' => '8888888888',
            'password_hash' => Hash::make('password'),
        ]);

        $event = Event::query()->create([
            'id' => (string) Str::uuid(),
            'title' => 'Business Networking Event',
            'start_at' => '2026-06-10 10:00:00',
            'end_at' => '2026-06-10 12:00:00',
            'location_text' => 'Ahmedabad',
            'metadata' => ['venue_name' => 'Hotel ABC', 'city' => 'Ahmedabad'],
            'qr_checkin_enabled' => true,
        ]);
        $occurrence = EventOccurrence::query()->create([
            'id' => (string) Str::uuid(),
            'event_id' => $event->id,
            'occurrence_date' => '2026-06-10',
            'start_at' => '2026-06-10 10:00:00',
            'end_at' => '2026-06-10 12:00:00',
            'status' => 'scheduled',
        ]);
        $registration = EventRegistration::query()->create([
            'id' => (string) Str::uuid(),
            'event_id' => $event->id,
            'occurrence_id' => $occurrence->id,
            'user_id' => $user->id,
            'qr_token' => 'token_here',
            'qr_code_path' => 'event-qrcodes/'.$event->id.'/file.png',
            'status' => 'registered',
            'payment_status' => 'not_required',
            'checkin_status' => 'pending',
            'registered_at' => now(),
        ]);
        EventRegistration::query()->create([
            'id' => (string) Str::uuid(),
            'event_id' => $event->id,
            'occurrence_id' => $occurrence->id,
            'user_id' => $otherUser->id,
            'qr_token' => 'other_token',
            'qr_code_path' => 'event-qrcodes/'.$event->id.'/other.png',
            'status' => 'registered',
            'payment_status' => 'not_required',
            'checkin_status' => 'pending',
            'registered_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/my/events-with-qr')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'My events with QR codes fetched successfully.')
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.event_id', $event->id)
            ->assertJsonPath('data.items.0.occurrence_id', $occurrence->id)
            ->assertJsonPath('data.items.0.registration_id', $registration->id)
            ->assertJsonPath('data.items.0.event_name', 'Business Networking Event')
            ->assertJsonPath('data.items.0.event_date', '2026-06-10')
            ->assertJsonPath('data.items.0.start_time', '10:00')
            ->assertJsonPath('data.items.0.end_time', '12:00')
            ->assertJsonPath('data.items.0.location', 'Ahmedabad')
            ->assertJsonPath('data.items.0.venue', 'Hotel ABC')
            ->assertJsonPath('data.items.0.registration_status', 'registered')
            ->assertJsonPath('data.items.0.payment_status', 'not_required')
            ->assertJsonPath('data.items.0.checkin_status', 'pending')
            ->assertJsonPath('data.items.0.checked_in_at', null)
            ->assertJsonPath('data.items.0.qr_token', 'token_here')
            ->assertJsonPath('data.items.0.qr_code_url', 'http://127.0.0.1:8000/api/v1/event-qrcodes/'.$event->id.'/file.png');
    }

    public function test_scanner_app_user_cannot_access_my_events_with_qr(): void
    {
        $scanner = ScanAppUser::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Scanner',
            'username' => 'scanner',
            'password_hash' => Hash::make('password'),
            'is_active' => true,
        ]);

        Sanctum::actingAs($scanner);

        $this->getJson('/api/v1/my/events-with-qr')
            ->assertForbidden()
            ->assertJson([
                'success' => false,
                'message' => 'This API is only available for Unity users.',
            ]);
    }

    public function test_unity_user_without_registrations_gets_empty_items(): void
    {
        $user = User::query()->create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Empty',
            'email' => 'empty@example.com',
            'phone' => '7777777777',
            'password_hash' => Hash::make('password'),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/my/events-with-qr')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.items', []);
    }

    private function setUpInMemoryDatabase(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('event_registrations');
        Schema::dropIfExists('event_occurrences');
        Schema::dropIfExists('scan_app_users');
        Schema::dropIfExists('events');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('display_name', 150)->nullable();
            $table->string('email', 255)->unique();
            $table->string('phone', 20)->nullable()->unique();
            $table->string('password_hash');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title')->nullable();
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->text('location_text')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('qr_checkin_enabled')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('event_occurrences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->date('occurrence_date')->nullable();
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('event_registrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->uuid('occurrence_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->text('qr_token')->nullable();
            $table->text('qr_code_path')->nullable();
            $table->text('qr_code_url')->nullable();
            $table->string('status')->nullable();
            $table->string('payment_status')->nullable();
            $table->string('checkin_status')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('scan_app_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('username')->unique();
            $table->string('password_hash');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
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
    }
}
