<?php

namespace App\Http\Resources;

use App\Models\JoinedCircleCategory;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Schema;

class CircleMemberResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'circle_id' => $this->circle_id,
            'role' => $this->role,
            'status' => $this->status,
            'joined_at' => $this->joined_at,
            'left_at' => $this->left_at,
            'substitute_count' => $this->substitute_count,
            'role_id' => $this->role_id,

            'user' => $this->whenLoaded('user', function () {
                $user = $this->user;
                $cityName = $this->resolveCityName($user);
                $categories = $this->resolveJoinedCircleCategories($user);
                $primaryCategory = $categories[0]['level1_category'] ?? null;
                $categoryId = $primaryCategory['id'] ?? null;
                $categoryName = $primaryCategory['name'] ?? null;
                $photoFileId = data_get($user, 'profile_photo_file_id')
                    ?: data_get($user, 'image_file_id')
                    ?: data_get($user, 'avatar_file_id')
                    ?: data_get($user, 'profile_image_file_id')
                    ?: data_get($user, 'photo_file_id')
                    ?: data_get($user, 'profile_file_id');

                $name = $user?->name
                    ?? $user?->display_name
                    ?? trim(($user?->first_name ?? '') . ' ' . ($user?->last_name ?? ''))
                    ?: $user?->email;

                return [
                    'id' => $user?->id,
                    'name' => $name,
                    'email' => $user?->email,
                    'phone' => $user?->phone ?? null,
                    'country_code' => $user?->country_code ?? null,
                    'city_id' => $user?->city_id,
                    'city_name' => $cityName,
                    'city' => $cityName,
                    'business_category_id' => $categoryId,
                    'business_category_name' => $categoryName,
                    'business_category' => $categoryName,
                    'business_sub_category' => $user?->business_sub_category,
                    'categories' => $categories,
                    'membership_status' => $user?->membership_status ?? null,
                    'is_active' => $user?->is_active ?? null,
                    'profile_photo_file_id' => $photoFileId,
                    'profile_photo_url' => $photoFileId
                        ? url("/api/v1/files/{$photoFileId}")
                        : null,
                    'designation' => $user?->designation ?? null,
                    'company_name' => $user?->company_name ?? null,
                    'created_at' => optional($user?->created_at)->toISOString(),
                ];
            }),

            'role_details' => $this->whenLoaded('roleModel', function () {
                return [
                    'id' => $this->roleModel->id,
                    'name' => $this->roleModel->name ?? null,
                    'slug' => $this->roleModel->slug ?? null,
                ];
            }),
        ];
    }

    private function resolveCityName($user): ?string
    {
        if (! $user) {
            return null;
        }

        $cityRelation = $user->relationLoaded('city')
            ? $user->getRelationValue('city')
            : ($user->relationLoaded('cityRelation') ? $user->getRelationValue('cityRelation') : null);

        if (is_object($cityRelation)) {
            return $cityRelation->name ?? null;
        }

        $city = $user->getAttribute('city');

        if (is_object($city)) {
            return $city->name ?? null;
        }

        if (is_string($city) && $city !== '') {
            return $city;
        }

        $cityName = $user->getAttribute('city_name');

        return is_string($cityName) && $cityName !== '' ? $cityName : null;
    }

    private function resolveJoinedCircleCategories($user): array
    {
        if (! $user) {
            return [];
        }

        if ($user->relationLoaded('joinedCircleCategories')) {
            $rows = $user->getRelationValue('joinedCircleCategories');
        } elseif (Schema::hasTable('joined_circle_categories')) {
            $rows = JoinedCircleCategory::query()
                ->where('user_id', $user->id)
                ->with([
                    'circle:id,name',
                    'level1Category:id,name',
                    'level2Category:id,name',
                    'level3Category:id,name',
                    'level4Category:id,name',
                ])
                ->orderByDesc('updated_at')
                ->get();
        } else {
            return [];
        }

        return $rows
            ->map(function (JoinedCircleCategory $row): array {
                return [
                    'circle_id' => $row->circle_id,
                    'circle_name' => $row->circle?->name,
                    'level1_category' => $row->level1Category
                        ? ['id' => $row->level1Category->id, 'name' => $row->level1Category->name]
                        : null,
                    'level2_category' => $row->level2Category
                        ? ['id' => $row->level2Category->id, 'name' => $row->level2Category->name]
                        : null,
                    'level3_category' => $row->level3Category
                        ? ['id' => $row->level3Category->id, 'name' => $row->level3Category->name]
                        : null,
                    'level4_category' => $row->level4Category
                        ? ['id' => $row->level4Category->id, 'name' => $row->level4Category->name]
                        : null,
                ];
            })
            ->values()
            ->all();
    }

}
