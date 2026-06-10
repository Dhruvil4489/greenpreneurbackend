<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EntrepreneurCertificationSubmission extends Model
{
    use HasUuids;

    public const QUIZ_FIELDS = [
        'business_start_reason',
        'business_failure_reaction',
        'successful_entrepreneur_definition',
        'business_purpose_frequency',
        'business_challenge_approach',
        'finance_tracking_frequency',
        'pricing_decision_method',
        'business_systems_status',
        'unhappy_customer_response',
        'money_separation_status',
        'failure_recovery_action',
        'major_decision_method',
        'competitor_growth_response',
        'new_idea_action',
        'risk_approach',
        'networking_belief',
        'conflict_handling',
        'team_motivation_method',
        'business_meet_frequency',
        'community_growth_belief',
        'five_year_business_vision',
        'success_meaning',
        'work_life_balance_method',
        'society_value_belief',
        'future_mentorship_belief',
    ];

    public const CORRECT_ANSWERS = [
        'business_start_reason' => 'To follow my passion and build something of my own',
        'business_failure_reaction' => 'Pause, analyze, and find what I can improve',
        'successful_entrepreneur_definition' => 'Someone who learns and grows from every failure',
        'business_purpose_frequency' => 'Regularly – I know why I am doing this',
        'business_challenge_approach' => 'Break it into smaller steps and solve',
        'finance_tracking_frequency' => 'Weekly or more often',
        'pricing_decision_method' => 'Based on costs + value to customers',
        'business_systems_status' => 'Yes, I follow clear systems (billing, tracking, etc.)',
        'unhappy_customer_response' => 'Say sorry and promise to fix it',
        'money_separation_status' => 'Yes, always',
        'failure_recovery_action' => 'Review what went wrong and improve it',
        'major_decision_method' => 'Analyze data, feedback, and expert opinions',
        'competitor_growth_response' => 'Learn from them and innovate better',
        'new_idea_action' => 'I try a small pilot or test it quickly',
        'risk_approach' => 'Take small calculated risks',
        'networking_belief' => 'I believe in connecting and learning from others',
        'conflict_handling' => 'Discuss openly and resolve quickly',
        'team_motivation_method' => 'Through appreciation and sharing success stories',
        'business_meet_frequency' => 'Regularly, I attend business meets',
        'community_growth_belief' => 'Yes, I believe collaboration leads to growth',
        'five_year_business_vision' => 'Growing with clear goals',
        'success_meaning' => 'Recognition, stability, and impact',
        'work_life_balance_method' => 'I plan time for family and self-care',
        'society_value_belief' => 'Yes, I solve real problems for people',
        'future_mentorship_belief' => 'Yes, I believe in giving back',
    ];

    protected $table = 'entrepreneur_certification_submissions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'full_name',
        'business_name',
        'email',
        'contact_no',
        'status',
        'notes',
        'business_start_reason',
        'business_failure_reaction',
        'successful_entrepreneur_definition',
        'business_purpose_frequency',
        'business_challenge_approach',
        'finance_tracking_frequency',
        'pricing_decision_method',
        'business_systems_status',
        'unhappy_customer_response',
        'money_separation_status',
        'failure_recovery_action',
        'major_decision_method',
        'competitor_growth_response',
        'new_idea_action',
        'risk_approach',
        'networking_belief',
        'conflict_handling',
        'team_motivation_method',
        'business_meet_frequency',
        'community_growth_belief',
        'five_year_business_vision',
        'success_meaning',
        'work_life_balance_method',
        'society_value_belief',
        'future_mentorship_belief',
        'total_score',
        'percentage',
        'certification_tier',
    ];

    protected $casts = [
        'total_score' => 'integer',
        'percentage' => 'float',
    ];
}
