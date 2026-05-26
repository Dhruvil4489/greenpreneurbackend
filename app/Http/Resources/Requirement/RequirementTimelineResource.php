<?php

namespace App\Http\Resources\Requirement;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RequirementTimelineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $creator = $this->whenLoaded('user');

        return [
            'id' => $this->id,
            'post_id' => $this->post_id,
            'user_name' => data_get($creator, 'name')
                ?: data_get($creator, 'full_name')
                ?: data_get($creator, 'display_name')
                ?: trim((string) data_get($creator, 'first_name', '') . ' ' . (string) data_get($creator, 'last_name', '')),
            'company' => data_get($creator, 'company') ?: data_get($creator, 'company_name', ''),
            'city' => data_get($creator, 'city', ''),
            'profile_photo_url' => $this->resolveProfilePhotoUrl($creator),
            'subject' => $this->subject,
            'description' => $this->description,
            'media' => collect($this->media ?? [])->map(function ($item) {
                if (is_string($item)) {
                    return ['type' => 'unknown', 'file_id' => null, 'url' => $item];
                }

                $fileId = data_get($item, 'file_id') ?: data_get($item, 'id');

                return [
                    'type' => data_get($item, 'type', 'image'),
                    'file_id' => $fileId,
                    'url' => data_get($item, 'url') ?: ($fileId ? url('/api/v1/files/' . $fileId) : null),
                ];
            })->values()->all(),
            'region_filter' => $this->region_filter ?? [],
            'category_filter' => $this->category_filter ?? [],
            'status' => $this->status,
            'created_at' => optional($this->created_at)?->toISOString(),
        ];
    }

    private function resolveProfilePhotoUrl(mixed $creator): ?string
    {
        if (! $creator) {
            return null;
        }

        $profilePhotoId = data_get($creator, 'profile_photo_id') ?: data_get($creator, 'profile_photo_file_id');

        if ($profilePhotoId) {
            return url('/api/v1/files/' . $profilePhotoId);
        }

        return data_get($creator, 'profile_photo_url');
    }
}
