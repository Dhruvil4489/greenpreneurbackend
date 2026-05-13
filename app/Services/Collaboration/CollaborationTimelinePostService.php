<?php

namespace App\Services\Collaboration;

use App\Models\CollaborationPost;
use App\Models\Post;

class CollaborationTimelinePostService
{
    private const SOURCE_TYPE = 'collaboration_post';
    private const EVENT_CREATED = 'created';
    private const EVENT_COMPLETED = 'completed';

    public function createCreatedPost(CollaborationPost $collaboration): bool
    {
        return (bool) $this->createTimelinePost(
            $collaboration,
            self::EVENT_CREATED,
            trim($collaboration->title . "\n\n" . $collaboration->description)
        );
    }

    public function createCompletedPost(CollaborationPost $collaboration): bool
    {
        $this->hideCreatedPost($collaboration);

        $collaboration->loadMissing('user:id,display_name,first_name,last_name');

        $user = $collaboration->user;
        $userName = $user?->display_name ?: trim(($user?->first_name ?? '') . ' ' . ($user?->last_name ?? ''));
        $prefix = $userName !== '' ? $userName : 'A peer';

        return (bool) $this->createTimelinePost(
            $collaboration,
            self::EVENT_COMPLETED,
            "{$prefix} completed collaboration: {$collaboration->title}"
        );
    }

    public function hideCreatedPost(CollaborationPost $collaboration): void
    {
        Post::query()
            ->where('source_type', self::SOURCE_TYPE)
            ->where('source_id', $collaboration->id)
            ->where('source_event', self::EVENT_CREATED)
            ->whereNull('deleted_at')
            ->update([
                'is_deleted' => true,
                'active' => false,
                'deleted_at' => now(),
            ]);
    }

    private function createTimelinePost(CollaborationPost $collaboration, string $event, string $content): ?Post
    {
        $query = Post::query()
            ->where('source_type', self::SOURCE_TYPE)
            ->where('source_id', $collaboration->id)
            ->where('source_event', $event);

        if ($query->exists()) {
            return null;
        }

        return Post::query()->create([
            'user_id' => $collaboration->user_id,
            'circle_id' => null,
            'content_text' => $content,
            'media' => [],
            'tags' => ['collaboration'],
            'visibility' => 'public',
            'moderation_status' => 'pending',
            'sponsored' => false,
            'is_deleted' => false,
            'active' => true,
            'source_type' => self::SOURCE_TYPE,
            'source_id' => $collaboration->id,
            'source_event' => $event,
        ]);
    }
}
