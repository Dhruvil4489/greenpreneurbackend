<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeaderboardApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createLeaderboardSchema();
    }

    public function test_coin_leaderboard_includes_designation_and_related_city_name(): void
    {
        $authUser = $this->createUser([
            'display_name' => 'Auth User',
            'coins_balance' => 1,
        ]);
        Sanctum::actingAs($authUser);

        $city = City::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Ahmedabad',
        ]);

        $leader = $this->createUser([
            'display_name' => 'Pravin Parmar',
            'first_name' => 'Pravin',
            'last_name' => 'Parmar',
            'company_name' => 'Peers Global Business Media Pvt Ltd',
            'designation' => 'Founder',
            'city_id' => $city->id,
            'city' => null,
            'coins_balance' => 151000,
            'life_impacted_count' => 41,
        ]);

        $response = $this->getJson('/api/v1/leaderboards/coins');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.leaderboard_type', 'coins');

        $member = collect($response->json('data.members'))->firstWhere('id', $leader->id);

        $this->assertSame('Founder', $member['designation']);
        $this->assertSame('Ahmedabad', $member['city']);
        $this->assertArrayHasKey('coins_balance', $member);
        $this->assertArrayHasKey('life_impacted_count', $member);
    }

    public function test_impact_leaderboard_includes_designation_and_text_city_fallback(): void
    {
        $authUser = $this->createUser([
            'display_name' => 'Auth User',
            'life_impacted_count' => 1,
        ]);
        Sanctum::actingAs($authUser);

        $leader = $this->createUser([
            'display_name' => 'Text City Leader',
            'first_name' => 'Text',
            'last_name' => 'Leader',
            'designation' => 'Director',
            'city_id' => null,
            'city' => 'Surat',
            'coins_balance' => 10,
            'life_impacted_count' => 99,
        ]);

        $response = $this->getJson('/api/v1/leaderboards/impacts');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.leaderboard_type', 'impacts');

        $member = collect($response->json('data.members'))->firstWhere('id', $leader->id);

        $this->assertSame('Director', $member['designation']);
        $this->assertSame('Surat', $member['city']);
        $this->assertArrayHasKey('coin_medal_rank', $member);
        $this->assertArrayHasKey('profile_photo', $member);
    }

    private function createLeaderboardSchema(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('cities');

        Schema::create('cities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('display_name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('password_hash')->nullable();
            $table->string('company_name')->nullable();
            $table->string('designation')->nullable();
            $table->uuid('city_id')->nullable();
            $table->string('city')->nullable();
            $table->string('business_type')->nullable();
            $table->uuid('profile_photo_file_id')->nullable();
            $table->integer('coins_balance')->default(0);
            $table->integer('life_impacted_count')->default(0);
            $table->string('coin_medal_rank')->nullable();
            $table->string('coin_milestone_title')->nullable();
            $table->text('coin_milestone_meaning')->nullable();
            $table->string('contribution_award_name')->nullable();
            $table->text('contribution_award_recognition')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    private function createUser(array $attributes = []): User
    {
        return User::query()->create(array_merge([
            'id' => (string) Str::uuid(),
            'first_name' => 'Default',
            'last_name' => 'User',
            'display_name' => 'Default User',
            'email' => Str::uuid() . '@example.test',
            'password_hash' => 'not-used',
            'status' => 'active',
            'coins_balance' => 0,
            'life_impacted_count' => 0,
        ], $attributes));
    }
}
