<?php

namespace App\Services\Collaboration;

use App\Models\CollaborationPost;
use App\Models\CollaborationType;
use App\Models\Industry;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CollaborationPostService
{
    public function createForUser(User $user, array $data): CollaborationPost
    {
        $industryId = (string) Arr::get($data, 'industry_id');
        $hasChildren = Industry::query()->active()->where('parent_id', $industryId)->exists();

        if ($hasChildren) {
            throw ValidationException::withMessages([
                'industry_id' => ['Please select a leaf industry.'],
            ]);
        }

        $hasTypeIdColumn = Schema::hasColumn('collaboration_posts', 'collaboration_type_id');
        $hasTypeSlugColumn = Schema::hasColumn('collaboration_posts', 'collaboration_type');

        $typeId = Arr::get($data, 'collaboration_type_id');
        $typeSlug = Arr::get($data, 'collaboration_type');

        if (class_exists(CollaborationType::class) && Schema::hasTable('collaboration_types')) {
            if ($typeId) {
                $type = CollaborationType::query()->findOrFail($typeId);
                $typeId = $type->id;
                $typeSlug = $type->slug ?? $type->name ?? $typeSlug;
            } elseif ($typeSlug) {
                $type = CollaborationType::query()
                    ->where('slug', $typeSlug)
                    ->orWhere('name', $typeSlug)
                    ->first();

                if ($type) {
                    $typeId = $type->id;
                    $typeSlug = $type->slug ?? $type->name ?? $typeSlug;
                }
            }
        }

        $payload = [
            'user_id' => $user->id,
            'title' => $data['title'],
            'description' => $data['description'],
            'scope' => $data['scope'],
            'countries_of_interest' => $data['countries_of_interest'] ?? null,
            'preferred_model' => $data['preferred_model'] ?? null,
            'industry_id' => $data['industry_id'],
            'business_stage' => $data['business_stage'],
            'years_in_operation' => $data['years_in_operation'],
            'urgency' => $data['urgency'],
            'status' => CollaborationPost::STATUS_ACTIVE,
            'completion_status' => CollaborationPost::COMPLETION_INCOMPLETE,
            'posted_at' => now(),
            'expires_at' => now()->addDays(60),
        ];

        if ($hasTypeIdColumn && $typeId) {
            $payload['collaboration_type_id'] = $typeId;
        }

        if ($hasTypeSlugColumn && $typeSlug) {
            $payload['collaboration_type'] = $typeSlug;
        }

        return CollaborationPost::query()->create($payload);
    }
}
