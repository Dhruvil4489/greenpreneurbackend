<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\ProfileMatchService;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ProfileMatchServiceTest extends TestCase
{
    public function test_calculate_returns_detailed_normalized_profile_match(): void
    {
        $authUser = new User([
            'id' => 'auth-user-id',
            'city' => 'Mumbai',
            'city_of_residence' => 'Mumbai',
            'state' => 'Maharashtra',
            'country' => 'India',
            'business_city' => 'Mumbai',
            'business_state' => 'Maharashtra',
            'business_country' => 'India',
            'main_business_category_id' => 'main-1',
            'business_category_id' => 'category-1',
            'business_sub_category' => 'FinTech',
            'business_type' => 'Software Services',
            'company_type' => 'Private Limited',
            'year_of_establishment' => 2018,
            'annual_revenue_range' => '1cr-5cr',
            'number_of_employees' => '11-50',
            'products_services_offered' => 'CRM Software, Mobile Apps',
            'business_keywords' => ['Software', 'Technology'],
            'designation' => 'Founder',
            'experience_years' => 10,
            'skills' => ['Laravel', 'Flutter'],
            'industries_of_interest' => ['Healthcare'],
            'interests' => ['Business Networking'],
            'collaboration_goals' => ['Partnership'],
            'i_am_looking_for' => ['Marketing'],
            'i_can_help_with' => ['Software Development'],
            'preferred_language' => 'English',
            'preferred_meeting_format' => 'virtual',
            'willing_to_mentor' => true,
            'open_to_cross_city_collaboration' => true,
            'open_to_speaking_at_events' => true,
        ]);
        $authUser->setRelation('circleMemberships', new Collection([
            (object) ['circle_id' => 'circle-1'],
            (object) ['circle_id' => 'circle-2'],
        ]));

        $member = new User([
            'id' => 'member-user-id',
            'city' => ' mumbai ',
            'city_of_residence' => 'Mumbai',
            'state' => 'maharashtra',
            'country' => 'india',
            'business_city' => 'Mumbai',
            'business_state' => 'Maharashtra',
            'business_country' => 'India',
            'main_business_category_id' => 'main-1',
            'business_category_id' => 'category-1',
            'business_sub_category' => 'fintech',
            'business_type' => 'Software',
            'company_type' => 'private limited',
            'year_of_establishment' => 2020,
            'annual_revenue_range' => '1cr-5cr',
            'number_of_employees' => '11-50',
            'products_services_offered' => '["CRM Software","Web Apps"]',
            'business_keywords' => ['Technology', 'Consulting'],
            'designation' => 'Co Founder',
            'experience_years' => 12,
            'skills' => ['Laravel', 'React'],
            'industries_of_interest' => ['Healthcare', 'Retail'],
            'interests' => ['Business Networking'],
            'collaboration_goals' => ['Partnership'],
            'i_can_help_with' => ['Marketing'],
            'i_am_looking_for' => ['Software Development'],
            'preferred_language' => 'english',
            'preferred_meeting_format' => 'virtual',
            'willing_to_mentor' => true,
            'open_to_cross_city_collaboration' => true,
            'open_to_speaking_at_events' => true,
            'business_website' => 'https://example.test',
            'linkedin_profile' => 'https://linkedin.com/in/example',
            'profile_photo_file_id' => 'photo-id',
            'cover_photo_file_id' => 'cover-id',
        ]);
        $member->setRelation('circleMemberships', new Collection([
            (object) ['circle_id' => 'circle-1'],
            (object) ['circle_id' => 'circle-2'],
        ]));

        $match = (new ProfileMatchService())->calculate($authUser, $member);

        $this->assertNotNull($match);
        $this->assertGreaterThanOrEqual(80, $match['score']);
        $this->assertSame($match['score'], $match['percentage']);
        $this->assertSame('Excellent Match', $match['level']);
        $this->assertContains('business_category', $match['matched_fields']);
        $this->assertContains('skills', $match['matched_fields']);
        $this->assertContains('same_circle', $match['matched_fields']);
        $this->assertSame(['laravel'], $match['matched_details']['common_skills']);
        $this->assertSame(['technology'], $match['matched_details']['common_business_keywords']);
        $this->assertSame(2, $match['matched_details']['common_circles_count']);
    }

    public function test_calculate_returns_self_payload_for_self_profile(): void
    {
        $user = new User(['id' => 'same-user-id']);

        $match = (new ProfileMatchService())->calculate($user, $user);

        $this->assertSame(100, $match['score']);
        $this->assertSame(100, $match['percentage']);
        $this->assertSame('Self', $match['level']);
        $this->assertSame(['self'], $match['matched_fields']);
        $this->assertTrue($match['matched_details']['is_self']);
    }

    public function test_normalize_array_field_handles_malformed_json_and_empty_values(): void
    {
        $service = new ProfileMatchService();

        $this->assertSame(['healthcare', 'retail'], $service->normalizeArrayField('Healthcare, Retail'));
        $this->assertSame(['not-json'], $service->normalizeArrayField('[not-json'));
        $this->assertSame([], $service->normalizeArrayField(null));
    }

    public function test_text_helpers_are_case_insensitive_and_trimmed(): void
    {
        $service = new ProfileMatchService();

        $this->assertTrue($service->textEquals('  Private Limited ', 'private limited'));
        $this->assertFalse($service->textEquals('', 'private limited'));
        $this->assertGreaterThanOrEqual(0.65, $service->textSimilarity('Software Services', 'software'));
    }
}
