<?php

namespace App\Http\Resources;

use App\Models\CircleCategory;
use App\Models\CircleCategoryLevel2;
use App\Models\CircleCategoryLevel3;
use App\Models\CircleCategoryLevel4;
use App\Models\CircleMemberCategorySelection;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Schema;

class UserResource extends JsonResource
{
    public function toArray($request): array
    {
        $profilePhotoId = $this->profile_photo_file_id ?? $this->profile_photo_id;
        $profilePhotoUrl = $profilePhotoId
            ? url('/api/v1/files/' . $profilePhotoId)
            : null;
        $coverPhotoId = $this->cover_photo_file_id;
        $coverPhotoUrl = $coverPhotoId
            ? url('/api/v1/files/' . $coverPhotoId)
            : null;
        $profileVideoId = $this->profile_video_id;
        $profileVideoUrl = $this->resolveProfileVideoUrl();

        $membershipStatus = $this->effective_membership_status ?? $this->membership_status;
        $resolvedCircle = $this->resolvePrimaryCircleContext();
        $resolvedCircleInfo = $resolvedCircle['circle'] ?? null;

        $resolvedCity = null;
        if ($this->relationLoaded('city') && $this->city) {
            $resolvedCity = $this->city;
        } elseif (filled($this->getAttribute('city'))) {
            $resolvedCity = $this->getAttribute('city');
        }

        return [
            'id'                  => $this->id,
            'public_profile_slug' => $this->public_profile_slug,
            'profile_photo_id'    => $profilePhotoId,
            'cover_photo_id'      => $coverPhotoId,
            'profile_video_id'    => $profileVideoId,
            'profile_video'       => $profileVideoId ? [
                'id' => (string) $profileVideoId,
                'url' => $profileVideoUrl,
            ] : null,
            'profile_video_url'   => $profileVideoUrl,
            'first_name'          => $this->first_name,
            'last_name'           => $this->last_name,
            'display_name'        => $this->display_name,
            'company_name'        => $this->company_name,
            'designation'         => $this->designation,
            'email'               => $this->email,
            'phone'               => $this->phone,
            'city'                => $resolvedCity ? new CityResource($resolvedCity) : null,
            'city_of_residence'   => $this->city_of_residence,
            'membership_status'   => $membershipStatus,
            'membership_expiry'   => $this->membership_ends_at,
            'membership_status_label' => match ($membershipStatus) {
                User::STATUS_FREE_TRIAL => 'Free Trial Peer',
                User::STATUS_FREE => 'Free Peer',
                default => $membershipStatus,
            },
            'membership_starts_at' => $this->membership_starts_at,
            'membership_ends_at' => $this->membership_ends_at,
            'zoho_plan_code' => $this->zoho_plan_code,
            'zoho_last_invoice_id' => $this->zoho_last_invoice_id,
            'active_circle_id' => $resolvedCircle['circle_id'] ?? $this->active_circle_id,
            'active_circle_addon_code' => $resolvedCircle['addon_code'] ?? $this->active_circle_addon_code,
            'active_circle_addon_name' => $resolvedCircle['addon_name'] ?? $this->active_circle_addon_name,
            'circle_joined_at' => $resolvedCircle['joined_at'] ?? $this->circle_joined_at,
            'circle_expires_at' => $resolvedCircle['expires_at'] ?? $this->circle_expires_at,
            'active_circle_subscription_id' => $resolvedCircle['circle_subscription_id'] ?? $this->active_circle_subscription_id,
            'active_circle' => $resolvedCircleInfo
                ? [
                    'id' => $resolvedCircleInfo->id,
                    'name' => $resolvedCircleInfo->name,
                    'slug' => $resolvedCircleInfo->slug,
                    'city' => $resolvedCircleInfo->relationLoaded('cityRef') ? [
                        'id' => optional($resolvedCircleInfo->cityRef)->id,
                        'name' => optional($resolvedCircleInfo->cityRef)->name,
                    ] : null,
                ]
                : $this->whenLoaded('activeCircle', function () {
                    $circle = $this->activeCircle;

                    if (! $circle) {
                        return null;
                    }

                    return [
                        'id' => $circle->id,
                        'name' => $circle->name,
                        'slug' => $circle->slug,
                        'city' => $circle->relationLoaded('cityRef') ? [
                            'id' => optional($circle->cityRef)->id,
                            'name' => optional($circle->cityRef)->name,
                        ] : null,
                    ];
                }),
            'circle_memberships' => $this->resolveCircleMemberships(),
            'followers_count'     => (int) ($this->followers_count ?? 0),
            'following_count'     => (int) ($this->following_count ?? 0),
            'coins_balance'       => $this->coins_balance,
            'life_impacted_count' => (int) ($this->life_impacted_count ?? 0),
            'business_type'       => $this->business_type,
            'turnover_range'      => $this->turnover_range,
            'gender'              => $this->gender,
            'dob'                 => optional($this->dob)?->format('Y-m-d'),
            'experience_years'    => $this->experience_years,
            'experience_summary'  => $this->experience_summary,
            'bio'                 => $this->short_bio,
            'long_bio_html'       => $this->long_bio_html,
            'industry_tags'       => $this->industry_tags ?? [],
            'skills'              => $this->skills ?? [],
            'interests'           => $this->interests ?? [],
            'target_regions'      => $this->target_regions ?? [],
            'target_business_categories' => $this->target_business_categories ?? [],
            'hobbies_interests'   => $this->hobbies_interests ?? [],
            'leadership_roles'    => $this->leadership_roles ?? [],
            'special_recognitions'=> $this->special_recognitions ?? [],
            'social_links'        => $this->resolveSocialLinks(),
            'media'               => $this->mediaValue(),
            'profile_photo_url'   => $profilePhotoUrl,
            'cover_photo_url'     => $coverPhotoUrl,
            'address'             => $this->address ?? null,
            'state'               => $this->state ?? null,
            'country'             => $this->country ?? null,
            'pincode'             => $this->pincode ?? null,
            'is_verified'         => $this->is_verified ?? null,
            'is_sponsored_member' => $this->is_sponsored_member ?? null,
            'last_login_at'       => $this->last_login_at,
            'created_at'          => $this->created_at,
            'updated_at'          => $this->updated_at,
        ];
    }


    private function resolveProfileVideoUrl(): ?string
    {
        $media = $this->media;

        if (is_string($media) && $media !== '') {
            $decoded = json_decode($media, true);
            $media = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($media) || $media === []) {
            return null;
        }

        $firstMedia = array_values($media)[0] ?? null;

        if (! is_array($firstMedia)) {
            return null;
        }

        if (! blank($firstMedia['url'] ?? null)) {
            return (string) $firstMedia['url'];
        }

        if (! blank($firstMedia['id'] ?? null)) {
            return url('/api/v1/files/' . $firstMedia['id']);
        }

        return null;
    }

    /**
     * @return array<int, array{id: string, url: string, type: string}>
     */
    private function mediaValue(): array
    {
        $media = $this->media;

        if (! is_array($media)) {
            return [];
        }

        return collect($media)
            ->filter(fn ($item): bool => is_array($item) && ! blank($item['id'] ?? null) && ! blank($item['type'] ?? null))
            ->map(fn (array $item): array => [
                'id' => (string) $item['id'],
                'url' => url('/api/v1/files/' . $item['id']),
                'type' => (string) $item['type'],
            ])
            ->values()
            ->all();
    }

    private function resolveCircleMemberships(): array
    {
        $joinedStatus = (string) config('circle.member_joined_status', 'approved');
        $memberships = $this->resource->relationLoaded('circleMemberships')
            ? $this->resource->circleMemberships
            : $this->resource->circleMemberships()
                ->where('status', $joinedStatus)
                ->whereNull('deleted_at')
                ->whereNull('left_at')
                ->where(function ($query): void {
                    $query->whereNull('paid_ends_at')->orWhere('paid_ends_at', '>=', now());

                    if (Schema::hasColumn('circle_members', 'expires_at')) {
                        $query->orWhere('expires_at', '>=', now());
                    }
                })
                ->orderByDesc('joined_at')
                ->with('circle:id,name,slug')
                ->get();

        if (! $memberships instanceof Collection) {
            return [];
        }

        $subscriptionMap = $this->resource->circleSubscriptions()
            ->whereIn('circle_id', $memberships->pluck('circle_id')->filter()->values())
            ->orderByDesc('paid_at')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('circle_id')
            ->map(fn ($items) => $items->first());

        $selectionByCircleMemberId = collect();
        if (Schema::hasTable('circle_member_category_selections')) {
            $selectionByCircleMemberId = CircleMemberCategorySelection::query()
                ->whereIn('circle_member_id', $memberships->pluck('id')->filter()->values())
                ->get([
                    'circle_member_id',
                    'level1_category_id',
                    'level2_category_id',
                    'level3_category_id',
                    'level4_category_id',
                ])
                ->keyBy(fn (CircleMemberCategorySelection $row) => (string) $row->circle_member_id);
        }

        $level1Ids = $selectionByCircleMemberId->pluck('level1_category_id')->filter()->unique()->values();
        $level2Ids = $selectionByCircleMemberId->pluck('level2_category_id')->filter()->unique()->values();
        $level3Ids = $selectionByCircleMemberId->pluck('level3_category_id')->filter()->unique()->values();
        $level4Ids = $selectionByCircleMemberId->pluck('level4_category_id')->filter()->unique()->values();

        $level1ById = $level1Ids->isEmpty()
            ? collect()
            : CircleCategory::query()->whereIn('id', $level1Ids)->get()->keyBy('id');
        $level2ById = $level2Ids->isEmpty()
            ? collect()
            : CircleCategoryLevel2::query()->whereIn('id', $level2Ids)->get()->keyBy('id');
        $level3ById = $level3Ids->isEmpty()
            ? collect()
            : CircleCategoryLevel3::query()->whereIn('id', $level3Ids)->get()->keyBy('id');
        $level4ById = $level4Ids->isEmpty()
            ? collect()
            : CircleCategoryLevel4::query()->whereIn('id', $level4Ids)->get()->keyBy('id');

        return $memberships->map(function ($membership) use (
            $subscriptionMap,
            $selectionByCircleMemberId,
            $level1ById,
            $level2ById,
            $level3ById,
            $level4ById
        ): array {
            $subscription = $subscriptionMap->get((string) $membership->circle_id);
            $selection = $selectionByCircleMemberId->get((string) $membership->id);
            $level1 = $selection ? $level1ById->get($selection->level1_category_id) : null;
            $level2 = $selection ? $level2ById->get($selection->level2_category_id) : null;
            $level3 = $selection ? $level3ById->get($selection->level3_category_id) : null;
            $level4 = $selection ? $level4ById->get($selection->level4_category_id) : null;

            return [
                'circle_member_id' => $membership->id,
                'circle_id' => $membership->circle_id,
                'circle_name' => optional($membership->circle)->name,
                'circle_slug' => optional($membership->circle)->slug,
                'member_status' => $membership->status,
                'member_role' => $membership->role,
                'joined_at' => $membership->joined_at,
                'expires_at' => $this->resolveCircleMembershipExpiry($membership),
                'joined_via' => $membership->joined_via,
                'payment_status' => $membership->payment_status,
                'zoho_addon_code' => $membership->zoho_addon_code ?: optional($subscription)->zoho_addon_code,
                'addon_name' => $membership->addon_name ?: optional($subscription)->zoho_addon_name,
                'circle_subscription_id' => $membership->circle_subscription_id ?: optional($subscription)->id,
                'subscription_status' => $membership->subscription_status ?: optional($subscription)->status,
                'selected_category_path' => [
                    'level1' => $level1 ? ['id' => $level1->id, 'name' => $level1->name] : null,
                    'level2' => $level2 ? ['id' => $level2->id, 'name' => $level2->name] : null,
                    'level3' => $level3 ? ['id' => $level3->id, 'name' => $level3->name] : null,
                    'level4' => $level4 ? ['id' => $level4->id, 'name' => $level4->name] : null,
                ],
            ];
        })->values()->all();
    }

    private function resolveCircleMembershipExpiry($membership): mixed
    {
        return $membership->expires_at
            ?? $membership->paid_ends_at
            ?? $this->membership_ends_at
            ?? $this->membership_expiry
            ?? null;
    }

    private function resolvePrimaryCircleContext(): array
    {
        $joinedStatus = (string) config('circle.member_joined_status', 'approved');
        $membership = $this->resource->circleMemberships()
            ->where('status', $joinedStatus)
            ->whereNull('deleted_at')
            ->whereNull('left_at')
            ->where(function ($query): void {
                $query->whereNull('paid_ends_at')->orWhere('paid_ends_at', '>=', now());

                if (Schema::hasColumn('circle_members', 'expires_at')) {
                    $query->orWhere('expires_at', '>=', now());
                }
            })
            // Selection rule for legacy single-circle fields:
            // prefer paid memberships, then latest join.
            ->orderByRaw('CASE WHEN paid_starts_at IS NULL THEN 1 ELSE 0 END ASC')
            ->orderByDesc('paid_starts_at')
            ->orderByDesc('joined_at')
            ->orderByDesc('created_at')
            ->with(['circle:id,name,slug,city_id', 'circle.cityRef:id,name'])
            ->first();

        if (! $membership) {
            return [];
        }

        $subscription = $this->resource->circleSubscriptions()
            ->where('circle_id', $membership->circle_id)
            ->orderByDesc('paid_at')
            ->when(Schema::hasColumn('circle_subscriptions', 'started_at'), function ($query): void {
                $query->orderByDesc('started_at');
            })
            ->when(Schema::hasColumn('circle_subscriptions', 'created_at'), function ($query): void {
                $query->orderByDesc('created_at');
            })
            ->first();

        return [
            'circle_id' => $membership->circle_id ?: $this->active_circle_id,
            'joined_at' => $membership->paid_starts_at
                ?? $membership->joined_at
                ?? optional($subscription)->started_at
                ?? $this->circle_joined_at,
            'expires_at' => $this->resolveCircleMembershipExpiry($membership)
                ?? optional($subscription)->expires_at
                ?? $this->circle_expires_at,
            'addon_code' => $membership->zoho_addon_code
                ?: optional($subscription)->zoho_addon_code
                ?: $this->active_circle_addon_code,
            'addon_name' => optional($subscription)->zoho_addon_name
                ?: $this->active_circle_addon_name,
            'circle_subscription_id' => optional($subscription)->id
                ?: $this->active_circle_subscription_id,
            'circle' => $membership->circle,
            'subscription' => $subscription,
        ];
    }

    private function resolveSocialLinks(): ?array
    {
        $platforms = ['facebook', 'instagram', 'linkedin', 'twitter', 'youtube', 'website'];
        $storedLinks = $this->social_links;

        if (is_string($storedLinks)) {
            $decoded = json_decode($storedLinks, true);
            $storedLinks = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
        }

        $links = [];
        foreach ($platforms as $platform) {
            $value = is_array($storedLinks) ? ($storedLinks[$platform] ?? null) : null;

            if (blank($value)) {
                $columnValue = $this->getAttribute($platform);
                $value = blank($columnValue) ? null : $columnValue;
            }

            $links[$platform] = $value;
        }

        return collect($links)->filter(fn ($link) => ! blank($link))->isNotEmpty()
            ? $links
            : null;
    }
}
