<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LeadershipCertificationSubmission extends Model
{
    use HasUuids;

    public const QUIZ_FIELDS = [
        'team_struggling_action',
        'leader_definition',
        'junior_challenged_idea',
        'leader_when_wrong',
        'team_motivation',
        'leadership_meaning',
        'different_background_team_first_step',
        'group_task_approach',
        'team_conflict_action',
        'leader_makes_others_feel',
        'team_big_achievement_action',
        'guide_new_entrepreneurs',
        'local_business_group_thought',
        'silent_team_meeting_action',
        'leadership_starts_with',
        'business_community_approach',
        'low_confidence_person_action',
        'support_most_in_team',
        'good_leadership_means',
        'feedback_frequency',
        'unhappy_customer_action',
        'new_network_person_action',
        'local_event_speaking_action',
        'leadership_role_offer_action',
        'great_leader_opinion',
    ];

    public const CORRECT_ANSWERS = [
        'team_struggling_action' => 'Offer help and ask what’s stopping them',
        'leader_definition' => 'Helps others succeed',
        'junior_challenged_idea' => 'Think openly and discuss',
        'leader_when_wrong' => 'Takes responsibility and finds a solution',
        'team_motivation' => 'Appreciation, trust and clear goals',
        'leadership_meaning' => 'Taking people forward together',
        'different_background_team_first_step' => 'Know them and align goals',
        'group_task_approach' => 'Involve everyone and guide the team',
        'team_conflict_action' => 'Listen to both and solve calmly',
        'leader_makes_others_feel' => 'Important and confident',
        'team_big_achievement_action' => 'Celebrate with them',
        'guide_new_entrepreneurs' => 'Happily share your journey and tips',
        'local_business_group_thought' => 'Interesting – I like helping and learning',
        'silent_team_meeting_action' => 'Ask open questions to involve them',
        'leadership_starts_with' => 'Self-awareness and action',
        'business_community_approach' => 'Learn, contribute, and connect with others',
        'low_confidence_person_action' => 'Encourage and support them',
        'support_most_in_team' => 'Anyone trying to grow',
        'good_leadership_means' => 'Building trust and results together',
        'feedback_frequency' => 'Regularly',
        'unhappy_customer_action' => 'Listen fully and fix the issue',
        'new_network_person_action' => 'Welcome and ask what they do',
        'local_event_speaking_action' => 'Accept and prepare to share something useful',
        'leadership_role_offer_action' => 'Ask for clarity and say yes if it fits your goals',
        'great_leader_opinion' => 'Focused, kind, and action-oriented',
    ];

    protected $table = 'leadership_certification_submissions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'full_name',
        'business_name',
        'email',
        'contact_no',
        'status',
        'notes',
        'team_struggling_action',
        'leader_definition',
        'junior_challenged_idea',
        'leader_when_wrong',
        'team_motivation',
        'leadership_meaning',
        'different_background_team_first_step',
        'group_task_approach',
        'team_conflict_action',
        'leader_makes_others_feel',
        'team_big_achievement_action',
        'guide_new_entrepreneurs',
        'local_business_group_thought',
        'silent_team_meeting_action',
        'leadership_starts_with',
        'business_community_approach',
        'low_confidence_person_action',
        'support_most_in_team',
        'good_leadership_means',
        'feedback_frequency',
        'unhappy_customer_action',
        'new_network_person_action',
        'local_event_speaking_action',
        'leadership_role_offer_action',
        'great_leader_opinion',
        'total_score',
        'percentage',
        'certification_level',
    ];

    protected $casts = [
        'total_score' => 'integer',
        'percentage' => 'float',
    ];
}
