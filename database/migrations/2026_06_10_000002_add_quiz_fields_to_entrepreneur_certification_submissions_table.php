<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE entrepreneur_certification_submissions
ADD COLUMN IF NOT EXISTS business_start_reason TEXT NULL,
ADD COLUMN IF NOT EXISTS business_failure_reaction TEXT NULL,
ADD COLUMN IF NOT EXISTS successful_entrepreneur_definition TEXT NULL,
ADD COLUMN IF NOT EXISTS business_purpose_frequency TEXT NULL,
ADD COLUMN IF NOT EXISTS business_challenge_approach TEXT NULL,
ADD COLUMN IF NOT EXISTS finance_tracking_frequency TEXT NULL,
ADD COLUMN IF NOT EXISTS pricing_decision_method TEXT NULL,
ADD COLUMN IF NOT EXISTS business_systems_status TEXT NULL,
ADD COLUMN IF NOT EXISTS unhappy_customer_response TEXT NULL,
ADD COLUMN IF NOT EXISTS money_separation_status TEXT NULL,
ADD COLUMN IF NOT EXISTS failure_recovery_action TEXT NULL,
ADD COLUMN IF NOT EXISTS major_decision_method TEXT NULL,
ADD COLUMN IF NOT EXISTS competitor_growth_response TEXT NULL,
ADD COLUMN IF NOT EXISTS new_idea_action TEXT NULL,
ADD COLUMN IF NOT EXISTS risk_approach TEXT NULL,
ADD COLUMN IF NOT EXISTS networking_belief TEXT NULL,
ADD COLUMN IF NOT EXISTS conflict_handling TEXT NULL,
ADD COLUMN IF NOT EXISTS team_motivation_method TEXT NULL,
ADD COLUMN IF NOT EXISTS business_meet_frequency TEXT NULL,
ADD COLUMN IF NOT EXISTS community_growth_belief TEXT NULL,
ADD COLUMN IF NOT EXISTS five_year_business_vision TEXT NULL,
ADD COLUMN IF NOT EXISTS success_meaning TEXT NULL,
ADD COLUMN IF NOT EXISTS work_life_balance_method TEXT NULL,
ADD COLUMN IF NOT EXISTS society_value_belief TEXT NULL,
ADD COLUMN IF NOT EXISTS future_mentorship_belief TEXT NULL,
ADD COLUMN IF NOT EXISTS total_score INTEGER DEFAULT 0,
ADD COLUMN IF NOT EXISTS percentage NUMERIC(5,2) DEFAULT 0,
ADD COLUMN IF NOT EXISTS certification_tier VARCHAR(100) NULL
SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE entrepreneur_certification_submissions
DROP COLUMN IF EXISTS certification_tier,
DROP COLUMN IF EXISTS percentage,
DROP COLUMN IF EXISTS total_score,
DROP COLUMN IF EXISTS future_mentorship_belief,
DROP COLUMN IF EXISTS society_value_belief,
DROP COLUMN IF EXISTS work_life_balance_method,
DROP COLUMN IF EXISTS success_meaning,
DROP COLUMN IF EXISTS five_year_business_vision,
DROP COLUMN IF EXISTS community_growth_belief,
DROP COLUMN IF EXISTS business_meet_frequency,
DROP COLUMN IF EXISTS team_motivation_method,
DROP COLUMN IF EXISTS conflict_handling,
DROP COLUMN IF EXISTS networking_belief,
DROP COLUMN IF EXISTS risk_approach,
DROP COLUMN IF EXISTS new_idea_action,
DROP COLUMN IF EXISTS competitor_growth_response,
DROP COLUMN IF EXISTS major_decision_method,
DROP COLUMN IF EXISTS failure_recovery_action,
DROP COLUMN IF EXISTS money_separation_status,
DROP COLUMN IF EXISTS unhappy_customer_response,
DROP COLUMN IF EXISTS business_systems_status,
DROP COLUMN IF EXISTS pricing_decision_method,
DROP COLUMN IF EXISTS finance_tracking_frequency,
DROP COLUMN IF EXISTS business_challenge_approach,
DROP COLUMN IF EXISTS business_purpose_frequency,
DROP COLUMN IF EXISTS successful_entrepreneur_definition,
DROP COLUMN IF EXISTS business_failure_reaction,
DROP COLUMN IF EXISTS business_start_reason
SQL);
    }
};
