<?php

namespace App\Http\Resources;

use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CollaborationPostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $this->user;
        $name = $user?->display_name ?: trim(($user?->first_name ?? '') . ' ' . ($user?->last_name ?? ''));
        $isVerified = $user ? $user->isPaidMember() : false;
        $photoFileId = $user?->profile_photo_file_id;
        $acceptedBy = $this->acceptedByUser;
        $acceptedByName = $acceptedBy?->display_name ?: trim(($acceptedBy?->first_name ?? '') . ' ' . ($acceptedBy?->last_name ?? ''));
        $acceptedByPhotoFileId = $acceptedBy?->profile_photo_file_id ?? $acceptedBy?->profile_photo_id;

        return [
            'id' => $this->id,
            'post_id' => Post::query()->where('source_type', 'collaboration_post')->where('source_id', $this->id)->where('is_deleted', false)->latest('created_at')->value('id'),
            'collaboration_type' => [
                'id' => $this->collaborationType?->id,
                'name' => $this->collaborationType?->name,
                'slug' => $this->collaborationType?->slug,
            ],
            'title' => $this->title,
            'description' => $this->description,
            'scope' => $this->scope,
            'countries_of_interest' => $this->countries_of_interest,
            'preferred_model' => $this->preferred_model,
            'industry' => [
                'id' => $this->industry?->id,
                'name' => $this->industry?->name,
            ],
            'business_stage' => $this->business_stage,
            'years_in_operation' => $this->years_in_operation,
            'urgency' => $this->urgency,
            'status' => $this->status,
            'completion_status' => $this->completion_status ?: 'incomplete',
            'completed_at' => optional($this->completed_at)->toIso8601String(),
            'accepted_at' => optional($this->accepted_at)->toIso8601String(),
            'accepted_by' => $acceptedBy ? [
                'id' => $acceptedBy->id,
                'name' => $acceptedByName,
                'first_name' => $acceptedBy->first_name,
                'last_name' => $acceptedBy->last_name,
                'email' => $acceptedBy->email,
                'phone' => $acceptedBy->phone,
                'company_name' => $acceptedBy->company_name,
                'designation' => $acceptedBy->designation,
                'city' => $acceptedBy->city,
                'profile_photo_url' => $acceptedByPhotoFileId ? url('/api/v1/files/' . $acceptedByPhotoFileId) : $acceptedBy->profile_photo_url,
            ] : null,
            'posted_at' => optional($this->posted_at)->toIso8601String(),
            'posted_days_ago' => $this->posted_at ? $this->posted_at->diffInDays(now()) : null,
            'expires_at' => optional($this->expires_at)->toIso8601String(),
            'member_type' => $isVerified ? 'Verified' : 'Free',
            'is_verified' => $isVerified,
            'user' => [
                'id' => $user?->id,
                'name' => $name,
                'city' => $user?->city,
                'profile_photo_url' => $photoFileId ? url('/api/v1/files/' . $photoFileId) : null,
            ],
        ];
    }
}
