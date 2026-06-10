<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE leadership_certification_submissions
ADD COLUMN IF NOT EXISTS team_struggling_action TEXT NULL,
ADD COLUMN IF NOT EXISTS leader_definition TEXT NULL,
ADD COLUMN IF NOT EXISTS junior_challenged_idea TEXT NULL,
ADD COLUMN IF NOT EXISTS leader_when_wrong TEXT NULL,
ADD COLUMN IF NOT EXISTS team_motivation TEXT NULL,
ADD COLUMN IF NOT EXISTS leadership_meaning TEXT NULL,
ADD COLUMN IF NOT EXISTS different_background_team_first_step TEXT NULL,
ADD COLUMN IF NOT EXISTS group_task_approach TEXT NULL,
ADD COLUMN IF NOT EXISTS team_conflict_action TEXT NULL,
ADD COLUMN IF NOT EXISTS leader_makes_others_feel TEXT NULL,
ADD COLUMN IF NOT EXISTS team_big_achievement_action TEXT NULL,
ADD COLUMN IF NOT EXISTS guide_new_entrepreneurs TEXT NULL,
ADD COLUMN IF NOT EXISTS local_business_group_thought TEXT NULL,
ADD COLUMN IF NOT EXISTS silent_team_meeting_action TEXT NULL,
ADD COLUMN IF NOT EXISTS leadership_starts_with TEXT NULL,
ADD COLUMN IF NOT EXISTS business_community_approach TEXT NULL,
ADD COLUMN IF NOT EXISTS low_confidence_person_action TEXT NULL,
ADD COLUMN IF NOT EXISTS support_most_in_team TEXT NULL,
ADD COLUMN IF NOT EXISTS good_leadership_means TEXT NULL,
ADD COLUMN IF NOT EXISTS feedback_frequency TEXT NULL,
ADD COLUMN IF NOT EXISTS unhappy_customer_action TEXT NULL,
ADD COLUMN IF NOT EXISTS new_network_person_action TEXT NULL,
ADD COLUMN IF NOT EXISTS local_event_speaking_action TEXT NULL,
ADD COLUMN IF NOT EXISTS leadership_role_offer_action TEXT NULL,
ADD COLUMN IF NOT EXISTS great_leader_opinion TEXT NULL,
ADD COLUMN IF NOT EXISTS total_score INTEGER DEFAULT 0,
ADD COLUMN IF NOT EXISTS percentage NUMERIC(5,2) DEFAULT 0,
ADD COLUMN IF NOT EXISTS certification_level VARCHAR(100) NULL
SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE leadership_certification_submissions
DROP COLUMN IF EXISTS certification_level,
DROP COLUMN IF EXISTS percentage,
DROP COLUMN IF EXISTS total_score,
DROP COLUMN IF EXISTS great_leader_opinion,
DROP COLUMN IF EXISTS leadership_role_offer_action,
DROP COLUMN IF EXISTS local_event_speaking_action,
DROP COLUMN IF EXISTS new_network_person_action,
DROP COLUMN IF EXISTS unhappy_customer_action,
DROP COLUMN IF EXISTS feedback_frequency,
DROP COLUMN IF EXISTS good_leadership_means,
DROP COLUMN IF EXISTS support_most_in_team,
DROP COLUMN IF EXISTS low_confidence_person_action,
DROP COLUMN IF EXISTS business_community_approach,
DROP COLUMN IF EXISTS leadership_starts_with,
DROP COLUMN IF EXISTS silent_team_meeting_action,
DROP COLUMN IF EXISTS local_business_group_thought,
DROP COLUMN IF EXISTS guide_new_entrepreneurs,
DROP COLUMN IF EXISTS team_big_achievement_action,
DROP COLUMN IF EXISTS leader_makes_others_feel,
DROP COLUMN IF EXISTS team_conflict_action,
DROP COLUMN IF EXISTS group_task_approach,
DROP COLUMN IF EXISTS different_background_team_first_step,
DROP COLUMN IF EXISTS leadership_meaning,
DROP COLUMN IF EXISTS team_motivation,
DROP COLUMN IF EXISTS leader_when_wrong,
DROP COLUMN IF EXISTS junior_challenged_idea,
DROP COLUMN IF EXISTS leader_definition,
DROP COLUMN IF EXISTS team_struggling_action
SQL);
    }
};
