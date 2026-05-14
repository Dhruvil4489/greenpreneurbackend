<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ProfileMatchService
{
    private const WEIGHTS = [
        'city' => 10,
        'state' => 5,
        'country' => 3,
        'business_city' => 5,
        'business_state' => 3,
        'business_country' => 2,
        'main_business_category' => 15,
        'business_category' => 15,
        'business_sub_category' => 10,
        'business_type' => 8,
        'company_type' => 5,
        'establishment_year' => 4,
        'annual_revenue_range' => 5,
        'number_of_employees' => 5,
        'products_services' => 8,
        'business_keywords' => 8,
        'designation' => 5,
        'experience_years' => 5,
        'skills' => 10,
        'industries_of_interest' => 8,
        'interests' => 5,
        'collaboration_goals' => 8,
        'help_looking_for' => 15,
        'superpower' => 5,
        'preferred_language' => 3,
        'preferred_meeting_format' => 3,
        'willing_to_mentor' => 2,
        'open_to_cross_city_collaboration' => 2,
        'open_to_speaking_at_events' => 2,
        'same_circle' => 15,
        'profile_completeness' => 10,
    ];

    private const SIMILARITY_THRESHOLD = 0.65;
    private const PRODUCTS_SERVICES_SIMILARITY_THRESHOLD = 0.45;

    /**
     * @var array<string, array<int, string>>
     */
    private array $circleIdsByUserId = [];

    public function calculate(User $authUser, User $member): ?array
    {
        if ((string) $authUser->id === (string) $member->id) {
            return $this->selfMatch();
        }

        $rawScore = 0;
        $possibleScore = 0;
        $matchedFields = [];
        $matchedDetails = [];

        $this->addBooleanMatch($rawScore, $possibleScore, $matchedFields, $matchedDetails, 'city', $this->sameCity($authUser, $member), $this->hasComparableCity($authUser, $member));
        $this->addBooleanMatch($rawScore, $possibleScore, $matchedFields, $matchedDetails, 'state', $this->textEquals($this->attribute($authUser, 'state'), $this->attribute($member, 'state')), $this->hasComparableText($this->attribute($authUser, 'state'), $this->attribute($member, 'state')));
        $this->addBooleanMatch($rawScore, $possibleScore, $matchedFields, $matchedDetails, 'country', $this->textEquals($this->attribute($authUser, 'country'), $this->attribute($member, 'country')), $this->hasComparableText($this->attribute($authUser, 'country'), $this->attribute($member, 'country')));

        $this->addBooleanMatch($rawScore, $possibleScore, $matchedFields, $matchedDetails, 'business_city', $this->textEquals($this->attribute($authUser, 'business_city'), $this->attribute($member, 'business_city')), $this->hasComparableText($this->attribute($authUser, 'business_city'), $this->attribute($member, 'business_city')));
        $this->addBooleanMatch($rawScore, $possibleScore, $matchedFields, $matchedDetails, 'business_state', $this->textEquals($this->attribute($authUser, 'business_state'), $this->attribute($member, 'business_state')), $this->hasComparableText($this->attribute($authUser, 'business_state'), $this->attribute($member, 'business_state')));
        $this->addBooleanMatch($rawScore, $possibleScore, $matchedFields, $matchedDetails, 'business_country', $this->textEquals($this->attribute($authUser, 'business_country'), $this->attribute($member, 'business_country')), $this->hasComparableText($this->attribute($authUser, 'business_country'), $this->attribute($member, 'business_country')));
        $matchedDetails['business_pincode'] = $this->hasComparableText($this->attribute($authUser, 'business_pincode'), $this->attribute($member, 'business_pincode'))
            ? $this->textEquals($this->attribute($authUser, 'business_pincode'), $this->attribute($member, 'business_pincode'))
            : null;

        $this->addBooleanMatch($rawScore, $possibleScore, $matchedFields, $matchedDetails, 'main_business_category', $this->sameScalar($this->attribute($authUser, 'main_business_category_id'), $this->attribute($member, 'main_business_category_id')), $this->hasComparableScalar($this->attribute($authUser, 'main_business_category_id'), $this->attribute($member, 'main_business_category_id')));
        $this->addBooleanMatch($rawScore, $possibleScore, $matchedFields, $matchedDetails, 'business_category', $this->sameScalar($this->attribute($authUser, 'business_category_id'), $this->attribute($member, 'business_category_id')), $this->hasComparableScalar($this->attribute($authUser, 'business_category_id'), $this->attribute($member, 'business_category_id')));
        $this->addBooleanMatch($rawScore, $possibleScore, $matchedFields, $matchedDetails, 'business_sub_category', $this->textEquals($this->attribute($authUser, 'business_sub_category'), $this->attribute($member, 'business_sub_category')), $this->hasComparableText($this->attribute($authUser, 'business_sub_category'), $this->attribute($member, 'business_sub_category')));
        $this->addBooleanMatch($rawScore, $possibleScore, $matchedFields, $matchedDetails, 'business_type', $this->textSimilarity($this->attribute($authUser, 'business_type'), $this->attribute($member, 'business_type')) >= self::SIMILARITY_THRESHOLD, $this->hasComparableText($this->attribute($authUser, 'business_type'), $this->attribute($member, 'business_type')));

        $this->addBooleanMatch($rawScore, $possibleScore, $matchedFields, $matchedDetails, 'company_type', $this->textEquals($this->attribute($authUser, 'company_type'), $this->attribute($member, 'company_type')), $this->hasComparableText($this->attribute($authUser, 'company_type'), $this->attribute($member, 'company_type')));
        $this->addBooleanMatch($rawScore, $possibleScore, $matchedFields, $matchedDetails, 'establishment_year', $this->yearsWithin($this->attribute($authUser, 'year_of_establishment'), $this->attribute($member, 'year_of_establishment'), 5), $this->hasComparableNumeric($this->attribute($authUser, 'year_of_establishment'), $this->attribute($member, 'year_of_establishment')));
        $this->addBooleanMatch($rawScore, $possibleScore, $matchedFields, $matchedDetails, 'annual_revenue_range', $this->textEquals($this->attribute($authUser, 'annual_revenue_range'), $this->attribute($member, 'annual_revenue_range')), $this->hasComparableText($this->attribute($authUser, 'annual_revenue_range'), $this->attribute($member, 'annual_revenue_range')));
        $this->addBooleanMatch($rawScore, $possibleScore, $matchedFields, $matchedDetails, 'number_of_employees', $this->textEquals($this->attribute($authUser, 'number_of_employees'), $this->attribute($member, 'number_of_employees')), $this->hasComparableText($this->attribute($authUser, 'number_of_employees'), $this->attribute($member, 'number_of_employees')));

        $commonProductsServices = $this->getCommonValues($this->attribute($authUser, 'products_services_offered'), $this->attribute($member, 'products_services_offered'));
        $productsServicesMatch = $commonProductsServices !== []
            || $this->textSimilarity($this->attribute($authUser, 'products_services_offered'), $this->attribute($member, 'products_services_offered')) >= self::PRODUCTS_SERVICES_SIMILARITY_THRESHOLD;
        $this->addBooleanMatch($rawScore, $possibleScore, $matchedFields, $matchedDetails, 'products_services', $productsServicesMatch, $this->hasComparableText($this->attribute($authUser, 'products_services_offered'), $this->attribute($member, 'products_services_offered')));
        $matchedDetails['common_products_services'] = $commonProductsServices;

        $commonBusinessKeywords = $this->getCommonValues($this->attribute($authUser, 'business_keywords'), $this->attribute($member, 'business_keywords'));
        $this->addCommonMatch($rawScore, $possibleScore, $matchedFields, $matchedDetails, 'business_keywords', 'common_business_keywords', $commonBusinessKeywords, $this->hasComparableList($this->attribute($authUser, 'business_keywords'), $this->attribute($member, 'business_keywords')));

        $this->addBooleanMatch($rawScore, $possibleScore, $matchedFields, $matchedDetails, 'designation', $this->textSimilarity($this->attribute($authUser, 'designation'), $this->attribute($member, 'designation')) >= self::SIMILARITY_THRESHOLD, $this->hasComparableText($this->attribute($authUser, 'designation'), $this->attribute($member, 'designation')));
        $this->addBooleanMatch($rawScore, $possibleScore, $matchedFields, $matchedDetails, 'experience_years', $this->yearsWithin($this->attribute($authUser, 'experience_years'), $this->attribute($member, 'experience_years'), 3), $this->hasComparableNumeric($this->attribute($authUser, 'experience_years'), $this->attribute($member, 'experience_years')));
        $matchedDetails['experience_summary_similarity'] = $this->textSimilarity($this->attribute($authUser, 'experience_summary'), $this->attribute($member, 'experience_summary'));

        $commonSkills = $this->getCommonValues($this->attribute($authUser, 'skills'), $this->attribute($member, 'skills'));
        $this->addCommonMatch($rawScore, $possibleScore, $matchedFields, $matchedDetails, 'skills', 'common_skills', $commonSkills, $this->hasComparableList($this->attribute($authUser, 'skills'), $this->attribute($member, 'skills')));

        $commonIndustries = $this->getCommonValues($this->attribute($authUser, 'industries_of_interest'), $this->attribute($member, 'industries_of_interest'));
        $this->addCommonMatch($rawScore, $possibleScore, $matchedFields, $matchedDetails, 'industries_of_interest', 'common_industries_of_interest', $commonIndustries, $this->hasComparableList($this->attribute($authUser, 'industries_of_interest'), $this->attribute($member, 'industries_of_interest')));

        $commonInterests = $this->getCommonValues($this->attribute($authUser, 'interests'), $this->attribute($member, 'interests'));
        $this->addCommonMatch($rawScore, $possibleScore, $matchedFields, $matchedDetails, 'interests', 'common_interests', $commonInterests, $this->hasComparableList($this->attribute($authUser, 'interests'), $this->attribute($member, 'interests')));

        $commonCollaborationGoals = $this->getCommonValues($this->attribute($authUser, 'collaboration_goals'), $this->attribute($member, 'collaboration_goals'));
        $this->addCommonMatch($rawScore, $possibleScore, $matchedFields, $matchedDetails, 'collaboration_goals', 'common_collaboration_goals', $commonCollaborationGoals, $this->hasComparableList($this->attribute($authUser, 'collaboration_goals'), $this->attribute($member, 'collaboration_goals')));

        $lookingForHelpMatch = $this->getCommonValues($this->attribute($authUser, 'i_am_looking_for'), $this->attribute($member, 'i_can_help_with'));
        $helpLookingForMatch = $this->getCommonValues($this->attribute($authUser, 'i_can_help_with'), $this->attribute($member, 'i_am_looking_for'));
        $helpMatchApplicable = $this->hasComparableList($this->attribute($authUser, 'i_am_looking_for'), $this->attribute($member, 'i_can_help_with'))
            || $this->hasComparableList($this->attribute($authUser, 'i_can_help_with'), $this->attribute($member, 'i_am_looking_for'));
        $this->addBooleanMatch($rawScore, $possibleScore, $matchedFields, $matchedDetails, 'help_looking_for', $lookingForHelpMatch !== [] || $helpLookingForMatch !== [], $helpMatchApplicable);
        $matchedDetails['my_looking_for_member_can_help'] = $lookingForHelpMatch;
        $matchedDetails['my_can_help_member_looking_for'] = $helpLookingForMatch;
        $superpowerSimilarity = $this->textSimilarity($this->attribute($authUser, 'superpower'), $this->attribute($member, 'superpower'));
        $matchedDetails['superpower_similarity'] = $superpowerSimilarity;
        $this->addBooleanMatch($rawScore, $possibleScore, $matchedFields, $matchedDetails, 'superpower', $superpowerSimilarity >= self::SIMILARITY_THRESHOLD, $this->hasComparableText($this->attribute($authUser, 'superpower'), $this->attribute($member, 'superpower')));

        $this->addBooleanMatch($rawScore, $possibleScore, $matchedFields, $matchedDetails, 'preferred_language', $this->textEquals($this->attribute($authUser, 'preferred_language'), $this->attribute($member, 'preferred_language')), $this->hasComparableText($this->attribute($authUser, 'preferred_language'), $this->attribute($member, 'preferred_language')));
        $this->addBooleanMatch($rawScore, $possibleScore, $matchedFields, $matchedDetails, 'preferred_meeting_format', $this->textEquals($this->attribute($authUser, 'preferred_meeting_format'), $this->attribute($member, 'preferred_meeting_format')), $this->hasComparableText($this->attribute($authUser, 'preferred_meeting_format'), $this->attribute($member, 'preferred_meeting_format')));
        $this->addBooleanMatch($rawScore, $possibleScore, $matchedFields, $matchedDetails, 'willing_to_mentor', $this->bothTruthy($this->attribute($authUser, 'willing_to_mentor'), $this->attribute($member, 'willing_to_mentor')), $this->hasComparableBool($this->attribute($authUser, 'willing_to_mentor'), $this->attribute($member, 'willing_to_mentor')));
        $this->addBooleanMatch($rawScore, $possibleScore, $matchedFields, $matchedDetails, 'open_to_cross_city_collaboration', $this->bothTruthy($this->attribute($authUser, 'open_to_cross_city_collaboration'), $this->attribute($member, 'open_to_cross_city_collaboration')), $this->hasComparableBool($this->attribute($authUser, 'open_to_cross_city_collaboration'), $this->attribute($member, 'open_to_cross_city_collaboration')));
        $this->addBooleanMatch($rawScore, $possibleScore, $matchedFields, $matchedDetails, 'open_to_speaking_at_events', $this->bothTruthy($this->attribute($authUser, 'open_to_speaking_at_events'), $this->attribute($member, 'open_to_speaking_at_events')), $this->hasComparableBool($this->attribute($authUser, 'open_to_speaking_at_events'), $this->attribute($member, 'open_to_speaking_at_events')));

        $commonCircles = $this->commonCircleIds($authUser, $member);
        $circleMatchApplicable = $this->circleIdsFor($authUser) !== [] && $this->circleIdsFor($member) !== [];
        $this->addBooleanMatch($rawScore, $possibleScore, $matchedFields, $matchedDetails, 'same_circle', $commonCircles !== [], $circleMatchApplicable, min(self::WEIGHTS['same_circle'], count($commonCircles) * 8));
        $matchedDetails['common_circles_count'] = count($commonCircles);

        $profileCompleteness = $this->calculateProfileCompleteness($member);
        $possibleScore += self::WEIGHTS['profile_completeness'];
        if ($profileCompleteness > 0) {
            $rawScore += $profileCompleteness;
            $matchedFields[] = 'profile_completeness';
        }
        $matchedDetails['profile_completeness'] = $profileCompleteness;

        $percentage = $this->normalizeScore($rawScore, $possibleScore);

        return [
            'score' => $percentage,
            'percentage' => $percentage,
            'level' => $this->getMatchLevel($percentage),
            'matched_fields' => array_values(array_unique($matchedFields)),
            'matched_details' => $matchedDetails,
        ];
    }

    public function getMatchLevel(int $score): string
    {
        return match (true) {
            $score >= 80 => 'Excellent Match',
            $score >= 60 => 'High Match',
            $score >= 40 => 'Medium Match',
            $score >= 20 => 'Low Match',
            default => 'Very Low Match',
        };
    }

    /**
     * Safely normalize JSON arrays, PostgreSQL arrays serialized as text, comma-delimited text, and simple strings.
     *
     * @return array<int, string>
     */
    public function normalizeArrayField($value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if ($value instanceof Collection) {
            $value = $value->all();
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed === '') {
                return [];
            }

            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = is_array($decoded) ? $decoded : [$decoded];
            } else {
                $value = preg_split('/[,|;]+/', trim($trimmed, '{}[]')) ?: [];
            }
        }

        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->flatMap(fn ($item): array => $this->flattenArrayValue($item))
            ->map(fn ($item): string => $this->normalizeText($item))
            ->filter(fn (string $item): bool => $item !== '')
            ->unique()
            ->values()
            ->all();
    }

    public function textEquals($a, $b): bool
    {
        $a = $this->normalizeText($a);
        $b = $this->normalizeText($b);

        return $a !== '' && $b !== '' && $a === $b;
    }

    public function textSimilarity($a, $b): float
    {
        $a = $this->normalizeText($this->stringifyValue($a));
        $b = $this->normalizeText($this->stringifyValue($b));

        if ($a === '' || $b === '') {
            return 0.0;
        }

        if ($a === $b) {
            return 1.0;
        }

        if (Str::contains($a, $b) || Str::contains($b, $a)) {
            return max(0.85, min(strlen($a), strlen($b)) / max(strlen($a), strlen($b)));
        }

        $aTokens = $this->tokenize($a);
        $bTokens = $this->tokenize($b);
        $tokenScore = 0.0;

        if ($aTokens !== [] && $bTokens !== []) {
            $intersection = count(array_intersect($aTokens, $bTokens));
            $union = count(array_unique(array_merge($aTokens, $bTokens)));
            $tokenScore = $union > 0 ? $intersection / $union : 0.0;
        }

        similar_text($a, $b, $percent);

        return round(max($tokenScore, $percent / 100), 4);
    }

    public function calculateProfileCompleteness(User $user): int
    {
        $fields = [
            'business_website',
            'linkedin_profile',
            'instagram_handle',
            'facebook_profile',
            'youtube_channel',
            'profile_photo_file_id',
            'cover_photo_file_id',
            'profile_video_id',
        ];

        $completed = collect($fields)
            ->filter(fn (string $field): bool => filled($this->attribute($user, $field)))
            ->count();

        return (int) round(($completed / count($fields)) * self::WEIGHTS['profile_completeness']);
    }

    /**
     * @return array<int, string>
     */
    public function getCommonValues($a, $b): array
    {
        $aValues = $this->normalizeArrayField($a);
        $bValues = $this->normalizeArrayField($b);

        if ($aValues === [] || $bValues === []) {
            return [];
        }

        return array_values(array_intersect($aValues, $bValues));
    }

    private function selfMatch(): array
    {
        return [
            'score' => 100,
            'percentage' => 100,
            'level' => 'Self',
            'matched_fields' => ['self'],
            'matched_details' => [
                'is_self' => true,
                'message' => 'This is your own profile.',
            ],
        ];
    }

    private function attribute(User $user, string $field): mixed
    {
        $attributes = $user->getAttributes();

        if (! array_key_exists($field, $attributes)) {
            return null;
        }

        return $attributes[$field];
    }

    private function addBooleanMatch(
        int &$score,
        int &$possibleScore,
        array &$matchedFields,
        array &$matchedDetails,
        string $field,
        bool $matched,
        bool $applicable,
        ?int $matchedWeight = null
    ): void {
        $matchedDetails[$field] = $applicable ? $matched : null;

        if (! $applicable) {
            return;
        }

        $weight = self::WEIGHTS[$field] ?? 0;
        $possibleScore += $weight;

        if (! $matched) {
            return;
        }

        $score += $matchedWeight ?? $weight;
        $matchedFields[] = $field;
    }

    /**
     * @param array<int, string> $commonValues
     */
    private function addCommonMatch(
        int &$score,
        int &$possibleScore,
        array &$matchedFields,
        array &$matchedDetails,
        string $field,
        string $detailKey,
        array $commonValues,
        bool $applicable
    ): void {
        $matchedDetails[$detailKey] = $commonValues;

        if (! $applicable) {
            return;
        }

        $possibleScore += self::WEIGHTS[$field] ?? 0;

        if ($commonValues === []) {
            return;
        }

        $score += self::WEIGHTS[$field] ?? 0;
        $matchedFields[] = $field;
    }

    private function sameCity(User $authUser, User $member): bool
    {
        if ($this->sameScalar($this->attribute($authUser, 'city_id'), $this->attribute($member, 'city_id'))) {
            return true;
        }

        return $this->textEquals($this->cityName($authUser), $this->cityName($member))
            || $this->textEquals($this->attribute($authUser, 'city_of_residence'), $this->attribute($member, 'city_of_residence'));
    }

    private function cityName(User $user): mixed
    {
        if ($user->relationLoaded('city') && $user->city) {
            return $user->city->name ?? null;
        }

        $city = $user->getAttribute('city');

        if (is_array($city)) {
            return $city['name'] ?? null;
        }

        return $city;
    }

    private function sameScalar($a, $b): bool
    {
        if (! $this->hasComparableScalar($a, $b)) {
            return false;
        }

        return (string) $a === (string) $b;
    }

    private function hasComparableCity(User $authUser, User $member): bool
    {
        return $this->hasComparableScalar($this->attribute($authUser, 'city_id'), $this->attribute($member, 'city_id'))
            || $this->hasComparableText($this->cityName($authUser), $this->cityName($member))
            || $this->hasComparableText($this->attribute($authUser, 'city_of_residence'), $this->attribute($member, 'city_of_residence'));
    }

    private function hasComparableScalar($a, $b): bool
    {
        return filled($a) && filled($b);
    }

    private function hasComparableText($a, $b): bool
    {
        return $this->normalizeText($this->stringifyValue($a)) !== ''
            && $this->normalizeText($this->stringifyValue($b)) !== '';
    }

    private function hasComparableNumeric($a, $b): bool
    {
        return is_numeric($a) && is_numeric($b);
    }

    private function hasComparableBool($a, $b): bool
    {
        return $a !== null && $b !== null && $a !== '' && $b !== '';
    }

    private function hasComparableList($a, $b): bool
    {
        return $this->normalizeArrayField($a) !== [] && $this->normalizeArrayField($b) !== [];
    }

    private function yearsWithin($a, $b, int $years): bool
    {
        if (! is_numeric($a) || ! is_numeric($b)) {
            return false;
        }

        return abs((int) $a - (int) $b) <= $years;
    }

    private function bothTruthy($a, $b): bool
    {
        return filter_var($a, FILTER_VALIDATE_BOOL) === true
            && filter_var($b, FILTER_VALIDATE_BOOL) === true;
    }

    /**
     * @return array<int, string>
     */
    private function commonCircleIds(User $authUser, User $member): array
    {
        $authCircleIds = $this->circleIdsFor($authUser);
        $memberCircleIds = $this->circleIdsFor($member);

        if ($authCircleIds === [] || $memberCircleIds === []) {
            return [];
        }

        return array_values(array_intersect($authCircleIds, $memberCircleIds));
    }

    /**
     * Uses eager-loaded circleMemberships when available so member-list matching does not query per row.
     *
     * @return array<int, string>
     */
    private function circleIdsFor(User $user): array
    {
        $userId = (string) $user->id;

        if (array_key_exists($userId, $this->circleIdsByUserId)) {
            return $this->circleIdsByUserId[$userId];
        }

        $memberships = $user->relationLoaded('circleMemberships')
            ? $user->circleMemberships
            : collect();

        return $this->circleIdsByUserId[$userId] = collect($memberships)
            ->pluck('circle_id')
            ->filter()
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, mixed>
     */
    private function flattenArrayValue($value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            if (array_key_exists('id', $value)) {
                return [$value['id']];
            }

            if (array_key_exists('name', $value)) {
                return [$value['name']];
            }

            return collect($value)
                ->flatMap(fn ($nested): array => $this->flattenArrayValue($nested))
                ->all();
        }

        return [$value];
    }

    private function normalizeScore(int $rawScore, int $possibleScore): int
    {
        if ($possibleScore <= 0) {
            return 0;
        }

        return max(0, min(100, (int) round(($rawScore / $possibleScore) * 100)));
    }

    private function stringifyValue($value): string
    {
        if ($value instanceof Collection) {
            $value = $value->all();
        }

        if (is_array($value)) {
            return implode(' ', $this->normalizeArrayField($value));
        }

        return (string) ($value ?? '');
    }

    /**
     * @return array<int, string>
     */
    private function tokenize(string $value): array
    {
        return collect(preg_split('/[^\pL\pN]+/u', $value) ?: [])
            ->map(fn (string $token): string => trim($token))
            ->filter(fn (string $token): bool => strlen($token) > 2)
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeText($value): string
    {
        if ($value === null) {
            return '';
        }

        return Str::of((string) $value)
            ->squish()
            ->lower()
            ->toString();
    }
}
