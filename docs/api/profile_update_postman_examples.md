# Profile API extended fields

Use Sanctum authentication for both endpoints.

## GET `/api/v1/profile`

Returns the existing profile payload plus the additive extended profile fields. Existing keys such as `first_name`, `last_name`, `company_name`, `designation`, `business_type`, `about`, `gender`, `dob`, `experience_years`, `experience_summary`, `city`, `skills`, `interests`, `social_links`, `profile_photo_id`, and `cover_photo_id` remain unchanged.

## PUT/PATCH `/api/v1/profile`

Partial updates are supported. Send only the fields that should change.

### Sample full request body

```json
{
  "first_name": "Free",
  "last_name": "Trayal",
  "company_name": "Aequitas Information Technology Pvt Ltd",
  "designation": "Technical Consultant",
  "business_type": "IT Services",
  "about": "Tech entrepreneur building Peers Global Unity.",
  "gender": "male",
  "dob": "2004-10-21",
  "experience_years": 12,
  "experience_summary": "Running companies and mentoring entrepreneurs.",
  "city": "Ahmedabad",
  "state": "Gujarat",
  "country": "India",
  "preferred_language": "English",
  "skills": ["Networking", "Leadership", "SaaS"],
  "interests": ["Entrepreneurship", "Startups", "Investing"],
  "business_logo_id": null,
  "business_category_id": "CATEGORY_UUID_HERE",
  "business_sub_category": "Software Development",
  "company_type": "Pvt Ltd",
  "year_of_establishment": 2020,
  "annual_revenue_range": "25L-1Cr",
  "number_of_employees": "21-50",
  "gst_number": "24ABCDE1234F1Z5",
  "business_website": "https://peersglobal.com",
  "superpower": "Business networking",
  "i_can_help_with": ["Business Referral", "Mentorship", "Vendor Connect"],
  "i_am_looking_for": ["Funding Access", "Visibility and PR"],
  "business_keywords": ["CNC", "automation", "software"],
  "products_services_offered": "Software development, mobile apps, Laravel backend",
  "secondary_mobile": "9876543210",
  "linkedin_profile": "https://linkedin.com/in/example",
  "instagram_handle": "https://instagram.com/example",
  "twitter_handle": "https://x.com/example",
  "facebook_profile": "https://facebook.com/example",
  "youtube_channel": "https://youtube.com/@example",
  "other_website": "https://example.com",
  "contact_visibility": "connections",
  "business_address": "Full business address here",
  "business_city": "Ahmedabad",
  "business_state": "Gujarat",
  "business_pincode": "380015",
  "business_country": "India",
  "google_maps_latitude": 23.022505,
  "google_maps_longitude": 72.571365,
  "industries_of_interest": ["Manufacturing", "Export", "SaaS"],
  "collaboration_goals": ["Find a JV Partner", "Get Mentored", "Raise Funding"],
  "preferred_meeting_format": "both",
  "willing_to_mentor": true,
  "open_to_cross_city_collaboration": true,
  "open_to_speaking_at_events": false,
  "profile_photo_id": "019b8d5b-4c91-7393-bba0-1ce8f0b258c3",
  "cover_photo_id": "019b8d59-0d4a-734b-8d81-2d9a301731fe"
}
```

### Legacy social links compatibility

The previous `social_links` object is still accepted and returned. If supplied, the API also maps it into the new flat social columns when those flat fields are not explicitly present:

```json
{
  "social_links": {
    "linkedin": "https://linkedin.com/in/example",
    "facebook": "https://facebook.com/example",
    "instagram": "https://instagram.com/example",
    "website": "https://example.com"
  }
}
```

Mapping:

- `social_links.linkedin` → `linkedin_profile`
- `social_links.facebook` → `facebook_profile`
- `social_links.instagram` → `instagram_handle`
- `social_links.website` → `other_website`

### Validation notes

- Website/profile fields must be valid URLs.
- Array fields: `skills`, `interests`, `i_can_help_with`, `i_am_looking_for`, `business_keywords`, `industries_of_interest`, and `collaboration_goals`.
- `year_of_establishment` must be between `1800` and the current year.
- `google_maps_latitude` must be between `-90` and `90`.
- `google_maps_longitude` must be between `-180` and `180`.
- `contact_visibility`: `everyone`, `connections`, `circle_members`, `leadership_only`, or `private`.
- `preferred_meeting_format`: `in_person`, `virtual`, or `both`.
