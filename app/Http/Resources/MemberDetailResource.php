<?php

namespace App\Http\Resources;

use App\Models\JoinedCircleCategory;
use Illuminate\Support\Facades\Schema;

class MemberDetailResource extends UserResource
{
    public function toArray($request): array
    {
        $data = parent::toArray($request);

        $data['medal_rank'] = $this->coin_medal_rank;
        $data['title'] = $this->coin_milestone_title;
        $data['meaning_and_vibe'] = $this->coin_milestone_meaning;
        $data['contribution_award_name'] = $this->contribution_award_name;
        $data['contribution_recognition'] = $this->contribution_award_recognition;
        $data['main_business_category_id'] = $this->main_business_category_id;
        $data['business_category_id'] = $this->business_category_id;
        $data['main_business_category'] = $this->formatCategory($this->mainBusinessCategory);
        $data['business_category'] = $this->formatCategory($this->businessCategory);

        $data = array_merge($data, $this->extendedProfileFields());

        $data['categories'] = $this->appendRegisteredBusinessCategory(
            $this->resolveJoinedCircleCategories()
        );

        return $data;
    }


    /**
     * @return array<string, mixed>
     */
    private function extendedProfileFields(): array
    {
        return [
            'state' => $this->state,
            'country' => $this->country,
            'preferred_language' => $this->preferred_language,
            'business_logo_id' => $this->business_logo_id,
            'business_category_id' => $this->business_category_id,
            'business_category' => $this->formatCategory($this->businessCategory),
            'business_sub_category' => $this->business_sub_category,
            'company_type' => $this->company_type,
            'business_type' => $this->business_type,
            'year_of_establishment' => $this->year_of_establishment,
            'annual_revenue_range' => $this->annual_revenue_range,
            'number_of_employees' => $this->number_of_employees,
            'gst_number' => $this->gst_number,
            'business_website' => $this->business_website,
            'about' => $this->short_bio,
            'superpower' => $this->superpower,
            'i_can_help_with' => $this->arrayValue('i_can_help_with'),
            'i_am_looking_for' => $this->arrayValue('i_am_looking_for'),
            'business_keywords' => $this->arrayValue('business_keywords'),
            'products_services_offered' => $this->products_services_offered,
            'secondary_mobile' => $this->secondary_mobile,
            'linkedin_profile' => $this->linkedin_profile,
            'instagram_handle' => $this->instagram_handle,
            'twitter_handle' => $this->twitter_handle,
            'facebook_profile' => $this->facebook_profile,
            'youtube_channel' => $this->youtube_channel,
            'other_website' => $this->other_website,
            'contact_visibility' => $this->contact_visibility ?? 'connections',
            'social_links' => $this->socialLinksObject(),
            'business_address' => $this->business_address,
            'business_city' => $this->business_city,
            'business_state' => $this->business_state,
            'business_pincode' => $this->business_pincode,
            'business_country' => $this->business_country ?? 'India',
            'google_maps_latitude' => $this->google_maps_latitude,
            'google_maps_longitude' => $this->google_maps_longitude,
            'industries_of_interest' => $this->arrayValue('industries_of_interest'),
            'collaboration_goals' => $this->arrayValue('collaboration_goals'),
            'preferred_meeting_format' => $this->preferred_meeting_format,
            'willing_to_mentor' => (bool) ($this->willing_to_mentor ?? false),
            'open_to_cross_city_collaboration' => (bool) ($this->open_to_cross_city_collaboration ?? false),
            'open_to_speaking_at_events' => (bool) ($this->open_to_speaking_at_events ?? false),
            'skills' => $this->arrayValue('skills'),
            'interests' => $this->arrayValue('interests'),
            'experience_years' => $this->experience_years,
            'experience_summary' => $this->experience_summary,
            'gender' => $this->gender,
            'dob' => optional($this->dob)?->format('Y-m-d'),
            'life_impacted_count' => (int) ($this->life_impacted_count ?? 0),
        ];
    }

    private function appendRegisteredBusinessCategory(array $categories): array
    {
        $mainCategory = $this->formatCategory($this->mainBusinessCategory);
        $businessCategory = $this->formatCategory($this->businessCategory);

        if (! $mainCategory && ! $businessCategory) {
            return $categories;
        }

        $alreadyExists = collect($categories)->contains(function (array $category) use ($mainCategory, $businessCategory): bool {
            $existingMainId = data_get($category, 'main_category.id')
                ?? data_get($category, 'level1_category.id');
            $existingBusinessId = data_get($category, 'business_category.id')
                ?? data_get($category, 'level4_category.id');

            return ($mainCategory === null || (string) $existingMainId === (string) $mainCategory['id'])
                && ($businessCategory === null || (string) $existingBusinessId === (string) $businessCategory['id']);
        });

        if (! $alreadyExists) {
            $categories[] = [
                'main_category' => $mainCategory,
                'business_category' => $businessCategory,
            ];
        }

        return array_values($categories);
    }

    private function formatCategory($category): ?array
    {
        if (! $category) {
            return null;
        }

        $level = $category->getAttribute('level')
            ?? ($category->getAttribute('circle_category_id') !== null ? 'level4' : null);
        $parentId = $category->getAttribute('parent_id')
            ?? $category->getAttribute('level3_id')
            ?? $category->getAttribute('circle_category_id');

        return [
            'id' => $category->id,
            'name' => (string) $category->name,
            'level' => $level,
            'parent_id' => $parentId,
        ];
    }

    private function arrayValue(string $attribute): array
    {
        $value = $this->getAttribute($attribute);

        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function socialLinksObject(): object|array
    {
        $socialLinks = $this->arrayValue('social_links');

        foreach ([
            'linkedin' => 'linkedin_profile',
            'facebook' => 'facebook_profile',
            'instagram' => 'instagram_handle',
            'twitter' => 'twitter_handle',
            'youtube' => 'youtube_channel',
            'website' => 'other_website',
        ] as $legacyKey => $column) {
            $value = $this->getAttribute($column);

            if (! blank($value) && blank($socialLinks[$legacyKey] ?? null)) {
                $socialLinks[$legacyKey] = $value;
            }
        }

        return $socialLinks === [] ? (object) [] : $socialLinks;
    }

    private function resolveJoinedCircleCategories(): array
    {
        if (! Schema::hasTable('joined_circle_categories')) {
            return [];
        }

        return JoinedCircleCategory::query()
            ->where('user_id', $this->id)
            ->with([
                'circle:id,name',
                'level1Category:id,name',
                'level2Category:id,name',
                'level3Category:id,name',
                'level4Category:id,name',
            ])
            ->orderByDesc('updated_at')
            ->get()
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
