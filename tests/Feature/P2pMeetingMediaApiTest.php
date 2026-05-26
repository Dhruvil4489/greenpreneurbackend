<?php

namespace Tests\Feature;

use App\Models\FileModel;
use App\Models\User;
use App\Services\Coins\CoinsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class P2pMeetingMediaApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('posts');
        Schema::dropIfExists('p2p_meetings');
        Schema::dropIfExists('files');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('display_name')->nullable();
            $table->string('email')->nullable();
            $table->string('password_hash')->nullable();
            $table->timestamps();
        });

        Schema::create('files', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('uploader_user_id')->nullable();
            $table->string('s3_key')->nullable();
            $table->string('mime_type')->nullable();
            $table->bigInteger('size_bytes')->nullable();
            $table->timestamps();
        });

        Schema::create('p2p_meetings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('initiator_user_id');
            $table->uuid('peer_user_id');
            $table->date('meeting_date');
            $table->string('meeting_place')->nullable();
            $table->text('remarks')->nullable();
            $table->json('media')->nullable();
            $table->boolean('is_deleted')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('circle_id')->nullable();
            $table->text('content_text')->nullable();
            $table->json('media')->nullable();
            $table->json('tags')->nullable();
            $table->string('visibility')->nullable();
            $table->string('moderation_status')->nullable();
            $table->boolean('sponsored')->default(false);
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();
        });

        $coins = Mockery::mock(CoinsService::class);
        $coins->shouldReceive('rewardForActivity')->andReturn(null);
        $this->app->instance(CoinsService::class, $coins);
    }

    public function test_create_without_media_still_works(): void
    {
        [$authUser, $peer] = $this->users();

        $response = $this->actingAs($authUser, 'sanctum')->postJson('/api/v1/activities/p2p-meetings', [
            'peer_user_id' => $peer->id,
            'meeting_place' => 'Vadodara office',
            'remarks' => 'Meeting done',
            'meeting_date' => '2025-12-08',
        ]);

        $response->assertCreated()->assertJsonPath('success', true);
        $this->assertSame([], $response->json('data.media'));
    }

    public function test_create_with_image_and_video_and_list_filters_expand_media(): void
    {
        [$authUser, $peer] = $this->users();

        $image = FileModel::query()->create([
            'id' => (string) Str::uuid(),
            'mime_type' => 'image/png',
            'size_bytes' => 125478,
        ]);

        $video = FileModel::query()->create([
            'id' => (string) Str::uuid(),
            'mime_type' => 'video/mp4',
            'size_bytes' => 455111,
        ]);

        $createResponse = $this->actingAs($authUser, 'sanctum')->postJson('/api/v1/activities/p2p-meetings', [
            'peer_user_id' => $peer->id,
            'meeting_place' => 'Vadodara office',
            'remarks' => 'With media',
            'meeting_date' => '2025-12-08',
            'media_file_ids' => [$image->id, $video->id],
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('data.media.0.media_type', 'image')
            ->assertJsonPath('data.media.0.url', url('/api/v1/files/' . $image->id))
            ->assertJsonPath('data.media.0.mime_type', 'image/png')
            ->assertJsonPath('data.media.0.size', 125478)
            ->assertJsonPath('data.media.1.media_type', 'video')
            ->assertJsonPath('data.media.1.url', url('/api/v1/files/' . $video->id))
            ->assertJsonPath('data.media.1.mime_type', 'video/mp4')
            ->assertJsonPath('data.media.1.size', 455111);

        $given = $this->actingAs($authUser, 'sanctum')->getJson('/api/v1/activities/p2p-meetings?filter=given');
        $given->assertOk()
            ->assertJsonPath('data.items.0.media.0.file_id', $image->id)
            ->assertJsonPath('data.items.0.media.0.media_type', 'image')
            ->assertJsonPath('data.items.0.media.0.url', url('/api/v1/files/' . $image->id))
            ->assertJsonPath('data.items.0.media.0.mime_type', 'image/png')
            ->assertJsonPath('data.items.0.media.0.size', 125478)
            ->assertJsonPath('data.items.0.media.1.media_type', 'video');

        $received = $this->actingAs($peer, 'sanctum')->getJson('/api/v1/activities/p2p-meetings?filter=received');
        $received->assertOk()
            ->assertJsonPath('data.items.0.media.0.url', url('/api/v1/files/' . $image->id))
            ->assertJsonPath('data.items.0.media.1.url', url('/api/v1/files/' . $video->id));
    }

    private function users(): array
    {
        $authUser = User::query()->create([
            'id' => (string) Str::uuid(),
            'display_name' => 'Auth User',
            'email' => Str::uuid() . '@example.com',
        ]);

        $peer = User::query()->create([
            'id' => (string) Str::uuid(),
            'display_name' => 'Peer User',
            'email' => Str::uuid() . '@example.com',
        ]);

        return [$authUser, $peer];
    }
}
