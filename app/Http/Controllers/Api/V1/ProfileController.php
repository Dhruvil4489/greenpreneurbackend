<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Profile\UpdateProfileRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'message' => 'Profile fetched successfully',
            'data' => $user,
        ]);
    }

    public function update(UpdateProfileRequest $request)
    {
        $user = $request->user();
        $validated = $request->validated();
        $data = collect($validated)
            ->only($this->profileUpdateFields())
            ->toArray();

        foreach ($this->arrayProfileFields() as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $data[$field] ?? [];
            }
        }

        if (array_key_exists('social_links', $validated) && is_array($validated['social_links'])) {
            $data['social_links'] = $validated['social_links'];

            foreach ([
                'linkedin' => 'linkedin_profile',
                'facebook' => 'facebook_profile',
                'instagram' => 'instagram_handle',
                'website' => 'other_website',
            ] as $legacyKey => $column) {
                if (! empty($validated['social_links'][$legacyKey]) && empty($data[$column])) {
                    $data[$column] = $validated['social_links'][$legacyKey];
                }
            }
        }

        if (array_key_exists('city_of_residence', $data) && ! array_key_exists('city', $data)) {
            $data['city'] = $data['city_of_residence'];
        }

        if (array_key_exists('city', $data) && ! array_key_exists('city_of_residence', $data)) {
            $data['city_of_residence'] = $data['city'];
        }

        if (array_key_exists('about', $data)) {
            $data['short_bio'] = $data['about'];
            unset($data['about']);
        }

        if (array_key_exists('profile_photo_id', $data)) {
            $data['profile_photo_file_id'] = $data['profile_photo_id'];
            unset($data['profile_photo_id']);
        }

        if (array_key_exists('cover_photo_id', $data)) {
            $data['cover_photo_file_id'] = $data['cover_photo_id'];
            unset($data['cover_photo_id']);
        }

        if (array_key_exists('first_name', $data) || array_key_exists('last_name', $data)) {
            $displayName = trim(($data['first_name'] ?? $user->first_name ?? '') . ' ' . ($data['last_name'] ?? $user->last_name ?? ''));
            $data['display_name'] = $displayName !== '' ? $displayName : $user->email;
        }

        $user->forceFill($data);
        $user->saveOrFail();
        $this->persistProfilePayloadToUsersTable($user, $data);
        $user->refresh();

        Log::info('profile_update_saved', [
            'user_id' => $user->id,
            'payload_keys' => array_keys($data),
            'secondary_mobile_db' => $user->secondary_mobile,
            'linkedin_profile_db' => $user->linkedin_profile,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'data' => $user,
        ]);
    }

    private function persistProfilePayloadToUsersTable($user, array $payload): void
    {
        if ($payload === []) {
            return;
        }

        $attributes = $user->getAttributes();
        $databasePayload = [];

        foreach (array_keys($payload) as $column) {
            if (array_key_exists($column, $attributes)) {
                $databasePayload[$column] = $attributes[$column];
            }
        }

        if ($databasePayload === []) {
            return;
        }

        if ($user->usesTimestamps() && $user->getUpdatedAtColumn()) {
            $databasePayload[$user->getUpdatedAtColumn()] = $user->freshTimestampString();
        }

        DB::table($user->getTable())
            ->where($user->getKeyName(), $user->getKey())
            ->update($databasePayload);
    }

    /**
     * @return array<int, string>
     */
    private function profileUpdateFields(): array
    {
        return [
            'first_name',
            'last_name',
            'phone',
            'company_name',
            'designation',
            'business_type',
            'about',
            'gender',
            'dob',
            'experience_years',
            'experience_summary',
            'city_id',
            'city',
            'city_of_residence',
            'state',
            'country',
            'preferred_language',
            'skills',
            'interests',
            'social_links',
            'profile_photo_id',
            'cover_photo_id',
            'business_logo_id',
            'business_category_id',
            'business_sub_category',
            'company_type',
            'year_of_establishment',
            'annual_revenue_range',
            'number_of_employees',
            'gst_number',
            'business_website',
            'superpower',
            'i_can_help_with',
            'i_am_looking_for',
            'business_keywords',
            'products_services_offered',
            'secondary_mobile',
            'linkedin_profile',
            'instagram_handle',
            'twitter_handle',
            'facebook_profile',
            'youtube_channel',
            'other_website',
            'contact_visibility',
            'business_address',
            'business_city',
            'business_state',
            'business_pincode',
            'business_country',
            'google_maps_latitude',
            'google_maps_longitude',
            'industries_of_interest',
            'collaboration_goals',
            'preferred_meeting_format',
            'willing_to_mentor',
            'open_to_cross_city_collaboration',
            'open_to_speaking_at_events',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function arrayProfileFields(): array
    {
        return [
            'skills',
            'interests',
            'social_links',
            'i_can_help_with',
            'i_am_looking_for',
            'business_keywords',
            'industries_of_interest',
            'collaboration_goals',
        ];
    }

}
