<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;

class PostResource extends JsonResource
{
    public function toArray($request): array
    {
        $authUser = auth()->user();

        $isSaved = false;

        if ($authUser) {
            if (isset($this->is_saved_by_me)) {
                $isSaved = (bool) $this->is_saved_by_me;
            } elseif ($this->relationLoaded('saves')) {
                $isSaved = $this->saves->contains('user_id', $authUser->id);
            }
        }

        $savesCount = isset($this->saves_count)
            ? (int) $this->saves_count
            : ($this->relationLoaded('saves') ? $this->saves->count() : 0);

        return [
            'id' => $this->id,

            'content_text'   => $this->content_text,
            'content'        => $this->content_text,
            'media'          => $this->media
                ? collect($this->media)->map(function ($item) {
                    if (!is_array($item)) {
                        return null;
                    }

                    $id = $item['id'] ?? null;

                    return [
                        'id'   => $id,
                        'type' => $item['type'] ?? null,
                        'url'  => $id
                            ? url("/api/v1/files/{$id}")
                            : null,
                    ];
                })->filter()->values()->all()
                : null,
            'tags'           => $this->tags,
            'visibility'     => $this->visibility,
            'moderation_status' => $this->moderation_status ?? null,

            'author' => $this->when(
                ($this->relationLoaded('user') && $this->user)
                || ($this->relationLoaded('author') && $this->author),
                function () {
                    $author = $this->user ?? $this->author;

                    return [
                        'id'               => $author?->id,
                        'display_name'     => $author?->display_name,
                        'first_name'       => $author?->first_name,
                        'last_name'        => $author?->last_name,
                        'profile_photo_url'=> $author?->profile_photo_url,
                    ];
                }
            ),

            'circle' => $this->when(
                $this->relationLoaded('circle') && $this->circle,
                function () {
                    return [
                        'id'   => $this->circle->id,
                        'name' => $this->circle->name,
                    ];
                }
            ),

            'likes_count'    => isset($this->likes_count) ? (int) $this->likes_count : 0,
            'comments_count' => isset($this->comments_count) ? (int) $this->comments_count : 0,

            'is_liked_by_me' => (bool) ($this->is_liked_by_me ?? false),
            'saves_count'    => $savesCount,
            'is_saved'       => $isSaved,

            'created_at' => $this->formatToIstDateTime($this->created_at),
            'updated_at' => $this->formatToIstDateTime($this->updated_at),
        ];
    }

    private function formatToIstDateTime(mixed $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        return Carbon::parse((string) $value)
            ->timezone('Asia/Kolkata')
            ->format('Y-m-d H:i:s');
    }
}
