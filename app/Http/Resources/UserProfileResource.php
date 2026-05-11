<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UserProfileResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                 => $this->id,
            'first_name'         => $this->first_name,
            'last_name'          => $this->last_name,
            'display_name'       => $this->display_name,
            'email'              => $this->email,
            'phone'              => $this->phone,

            'company_name'       => $this->company_name,
            'designation'        => $this->designation,
            'business_type'      => $this->business_type,
            'business_logo_id'   => $this->business_logo_id,
            'business_category_id' => $this->business_category_id,
            'business_sub_category' => $this->business_sub_category,
            'company_type'       => $this->company_type,
            'year_of_establishment' => $this->year_of_establishment,
            'annual_revenue_range' => $this->annual_revenue_range,
            'number_of_employees' => $this->number_of_employees,
            'gst_number'         => $this->gst_number,
            'business_website'   => $this->business_website,

            'about'              => $this->short_bio,
            'superpower'         => $this->superpower,
            'i_can_help_with'    => $this->i_can_help_with ?? [],
            'i_am_looking_for'   => $this->i_am_looking_for ?? [],
            'business_keywords'  => $this->business_keywords ?? [],
            'products_services_offered' => $this->products_services_offered,

            'gender'             => $this->gender,
            'dob'                => optional($this->dob)?->format('Y-m-d'),

            'experience_years'   => $this->experience_years,
            'experience_summary' => $this->experience_summary,
            'life_impacted_count' => (int) ($this->life_impacted_count ?? 0),

            'city'               => $this->city,
            'city_of_residence'  => $this->city_of_residence ?? $this->city,
            'state'              => $this->state,
            'country'            => $this->country,
            'preferred_language' => $this->preferred_language,

            'skills'             => $this->skills ?? [],
            'interests'          => $this->interests ?? [],

            'social_links'       => $this->social_links,
            'secondary_mobile'   => $this->secondary_mobile,
            'linkedin_profile'   => $this->linkedin_profile,
            'instagram_handle'   => $this->instagram_handle,
            'twitter_handle'     => $this->twitter_handle,
            'facebook_profile'   => $this->facebook_profile,
            'youtube_channel'    => $this->youtube_channel,
            'other_website'      => $this->other_website,
            'contact_visibility' => $this->contact_visibility,
            'business_address'   => $this->business_address,
            'business_city'      => $this->business_city,
            'business_state'     => $this->business_state,
            'business_pincode'   => $this->business_pincode,
            'business_country'   => $this->business_country,
            'google_maps_latitude' => $this->google_maps_latitude,
            'google_maps_longitude' => $this->google_maps_longitude,
            'industries_of_interest' => $this->industries_of_interest ?? [],
            'collaboration_goals' => $this->collaboration_goals ?? [],
            'preferred_meeting_format' => $this->preferred_meeting_format,
            'willing_to_mentor'  => $this->willing_to_mentor,
            'open_to_cross_city_collaboration' => $this->open_to_cross_city_collaboration,
            'open_to_speaking_at_events' => $this->open_to_speaking_at_events,

            'profile_photo_id'   => $this->profile_photo_file_id,
            'cover_photo_id'     => $this->cover_photo_file_id,

            'profile_photo_url'  => $this->profile_photo_file_id
                ? url("/api/v1/files/{$this->profile_photo_file_id}")
                : null,
            'cover_photo_url'    => $this->cover_photo_file_id
                ? url("/api/v1/files/{$this->cover_photo_file_id}")
                : null,

            'created_at'         => $this->created_at,
            'updated_at'         => $this->updated_at,
        ];
    }
}
