<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CollaborationPostListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $authUser = $request->user();
        $isPaidAuth = $authUser ? (method_exists($authUser, 'isPaidMember') ? $authUser->isPaidMember() : ! in_array($authUser->membership_status, ['visitor', 'free_peer', 'suspended'], true)) : false;
        $posterPaid = ! in_array($this->user?->membership_status, ['visitor', 'free_peer', 'suspended'], true);
        $profilePhotoFileId = $this->user?->profile_photo_file_id ?? $this->user?->profile_photo_id;

        return [
            'id' => $this->id,
            'user' => [
                'id' => $this->user?->id,
                'name' => $this->user?->display_name ?? trim(($this->user?->first_name ?? '') . ' ' . ($this->user?->last_name ?? '')),
                'city' => $this->user?->city,
                'profile_photo_url' => $profilePhotoFileId ? url('/api/v1/files/' . $profilePhotoFileId) : ($this->user?->profile_photo_url),
            ],
            'member_type' => $posterPaid ? 'Verified' : 'Free',
            'verified_badge' => $posterPaid,
            'industry' => [
                'id' => $this->industry?->id,
                'name' => $this->industry?->name,
                'parent_category_name' => $this->industry?->parent?->name,
            ],
            'collaboration_type' => $this->collaboration_type,
            'title' => $this->title,
            'scope' => $this->scope,
            'countries_of_interest' => $this->countries_of_interest,
            'preferred_model' => $this->preferred_model,
            'business_stage' => $this->business_stage,
            'years_in_operation' => $this->years_in_operation,
            'urgency' => $this->urgency,
            'posted_at' => optional($this->posted_at)->toIso8601String(),
            'posted_days_ago' => $this->posted_at ? $this->posted_at->diffInDays(now()) : null,
            'expires_at' => optional($this->expires_at)->toIso8601String(),
            'status' => $this->status,
            'completion_status' => $this->completion_status ?: 'incomplete',
            'completed_at' => optional($this->completed_at)->toIso8601String(),
            'interests_count' => (int) ($this->interests_count ?? 0),
            'meetings_count' => (int) ($this->meeting_requests_count ?? 0),
            'is_interested_by_me' => (bool) ($this->is_interested_by_me ?? false),
            'can_message_directly' => $isPaidAuth,
        ];
    }
}
