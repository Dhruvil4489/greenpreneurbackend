<?php

namespace App\Services;

use App\Models\BusinessDeal;
use App\Models\LifeImpactHistory;
use App\Models\P2pMeeting;
use App\Models\Referral;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PeerMonthlyImpactScriptService
{
    private const CHECKLIST_DEFINITIONS = [
        'qualified_referrals_given' => 'Qualified referrals given',
        'mentoring_given' => 'Mentoring given',
        'collaboration_connections' => 'Collaboration connections',
        'knowledge_shared' => 'Knowledge shared',
        'business_challenge_help' => 'Business challenge help',
        'vendor_or_service_help' => 'Vendor or service help',
        'funding_or_capital_help' => 'Funding or capital help',
        'media_or_recognition_help' => 'Media or recognition help',
        'personal_or_business_support' => 'Personal or business support',
        'helped_get_things_done' => 'Helped get things done',
    ];

    private const HISTORY_ACTION_ALIASES = [
        'mentoring_given' => ['mentoring_given', 'mentor', 'mentoring', 'mentorship', 'guided_peer', 'coaching'],
        'collaboration_connections' => ['collaboration_connections', 'collaboration_connection', 'collaboration', 'p2p_meeting', 'p2p', 'connection'],
        'knowledge_shared' => ['knowledge_shared', 'knowledge_share', 'knowledge', 'shared_knowledge', 'education', 'training'],
        'business_challenge_help' => ['business_challenge_help', 'business_challenge', 'challenge_help', 'problem_solving'],
        'vendor_or_service_help' => ['vendor_or_service_help', 'vendor_help', 'service_help', 'vendor', 'service_provider'],
        'funding_or_capital_help' => ['funding_or_capital_help', 'funding_help', 'capital_help', 'funding', 'capital'],
        'media_or_recognition_help' => ['media_or_recognition_help', 'media_help', 'recognition_help', 'media', 'recognition'],
        'personal_or_business_support' => ['personal_or_business_support', 'personal_support', 'business_support', 'support'],
        'helped_get_things_done' => ['helped_get_things_done', 'execution_help', 'got_things_done', 'things_done'],
    ];

    public function buildForUser(User $user): array
    {
        $now = Carbon::now();
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd = $now->copy()->endOfMonth();

        $userId = (string) $user->id;
        $monthlyLivesImpacted = $this->totalLivesImpacted($userId, $monthStart, $monthEnd);
        $lifetimeLivesImpacted = $this->lifetimeLivesImpacted($user, $userId);
        $businessDeals = $this->businessDealsThisMonth($userId, $monthStart, $monthEnd);
        $checklistItems = $this->checklistItems($userId, $monthStart, $monthEnd);
        $userInfo = $this->userInfo($user);
        $summary = [
            'total_lives_impacted_this_month' => $monthlyLivesImpacted,
            'total_business_done_with_peers_this_month' => $businessDeals['total_amount'],
            'lifetime_total_lives_impacted' => $lifetimeLivesImpacted,
            'lifetime_total_business_done_with_peers' => $this->lifetimeBusinessDoneWithPeers($userId),
        ];

        return [
            'user' => $userInfo,
            'period' => [
                'month' => $now->format('F'),
                'year' => (int) $now->year,
                'month_start_date' => $monthStart->toDateString(),
                'month_end_date' => $monthEnd->toDateString(),
                'total_lives_impacted_this_month' => $monthlyLivesImpacted,
                'total_business_done_with_peers_this_month' => $businessDeals['total_amount'],
            ],
            'summary' => $summary,
            'checklist_items' => $checklistItems,
            'business_deals_this_month' => $businessDeals,
            'form_fields' => [
                'meaningful_progress_this_month' => null,
                'goal_for_next_month' => null,
                'experience_or_story_optional' => null,
            ],
            'script' => $this->script($userInfo, $summary, $businessDeals, $checklistItems),
        ];
    }

    private function userInfo(User $user): array
    {
        $displayName = $this->displayName($user);
        $companyName = $this->stringOrNull($user->company_name ?? null);
        $industryTags = $this->normalizeArray($user->industry_tags ?? []);
        $category = $this->stringOrNull($user->business_type ?? null) ?? ($industryTags[0] ?? null);

        return [
            'user_id' => (string) $user->id,
            'display_name' => $displayName,
            'first_name' => $this->stringOrNull($user->first_name ?? null),
            'last_name' => $this->stringOrNull($user->last_name ?? null),
            'business_name' => $companyName,
            'company_name' => $companyName,
            'category' => $category,
            'business_type' => $this->stringOrNull($user->business_type ?? null),
            'industry_tags' => $industryTags,
            'profile_photo_url' => $this->profilePhotoUrl($user),
        ];
    }

    private function totalLivesImpacted(string $userId, Carbon $start, Carbon $end): int
    {
        if (Schema::hasTable('life_impact_histories')) {
            $table = (new LifeImpactHistory())->getTable();
            $query = DB::table($table)->where('user_id', $userId);
            $this->applyHistoryCountableFilters($query, $table);
            $this->applyDateRange($query, $table, ['created_at'], $start, $end);

            return (int) $query->sum(DB::raw($this->lifeImpactSumExpression($table)));
        }

        if (! Schema::hasTable('impacts')) {
            return 0;
        }

        $query = DB::table('impacts')->where('user_id', $userId);
        if (Schema::hasColumn('impacts', 'status')) {
            $query->where('status', 'approved');
        }
        $this->applyDateRange($query, 'impacts', ['impact_date', 'approved_at', 'created_at'], $start, $end);

        return (int) $query->sum(DB::raw(Schema::hasColumn('impacts', 'life_impacted') ? 'COALESCE(life_impacted, 1)' : '1'));
    }

    private function lifetimeLivesImpacted(User $user, string $userId): int
    {
        $existingTotal = (int) ($user->life_impacted_count ?? 0);
        if ($existingTotal > 0) {
            return $existingTotal;
        }

        if (! Schema::hasTable('life_impact_histories')) {
            return 0;
        }

        $table = (new LifeImpactHistory())->getTable();
        $query = DB::table($table)->where('user_id', $userId);
        $this->applyHistoryCountableFilters($query, $table);

        return (int) $query->sum(DB::raw($this->lifeImpactSumExpression($table)));
    }

    private function businessDealsThisMonth(string $userId, Carbon $start, Carbon $end): array
    {
        if (! Schema::hasTable('business_deals')) {
            return [
                'total_amount' => 0.0,
                'deals_count' => 0,
                'peers' => [],
            ];
        }

        $query = $this->businessDealBaseQuery($userId);
        $this->applyDateRange($query, 'business_deals', ['deal_date', 'created_at'], $start, $end);

        $deals = $query
            ->with([
                'fromUser:id,display_name,first_name,last_name,company_name',
                'toUser:id,display_name,first_name,last_name,company_name',
            ])
            ->orderByDesc('deal_date')
            ->orderByDesc('created_at')
            ->get();

        return [
            'total_amount' => round((float) $deals->sum(fn (BusinessDeal $deal): float => (float) ($deal->deal_amount ?? 0)), 2),
            'deals_count' => $deals->count(),
            'peers' => $deals->map(fn (BusinessDeal $deal): array => $this->businessDealPeer($deal, $userId))->values()->all(),
        ];
    }

    private function lifetimeBusinessDoneWithPeers(string $userId): float
    {
        if (! Schema::hasTable('business_deals')) {
            return 0.0;
        }

        $query = $this->businessDealBaseQuery($userId)->toBase();

        return round((float) $query->sum(DB::raw('COALESCE(deal_amount, 0)')), 2);
    }

    private function checklistItems(string $userId, Carbon $start, Carbon $end): array
    {
        $items = collect(self::CHECKLIST_DEFINITIONS)
            ->map(fn (string $label, string $key): array => $this->emptyChecklistItem($key, $label))
            ->all();

        $items['qualified_referrals_given'] = $this->referralChecklistItem($userId, $start, $end);
        $items['collaboration_connections'] = $this->collaborationChecklistItem($userId, $start, $end, $items['collaboration_connections']);
        $items = $this->mergeHistoryChecklistItems($items, $userId, $start, $end);

        return array_values($items);
    }

    private function referralChecklistItem(string $userId, Carbon $start, Carbon $end): array
    {
        $label = self::CHECKLIST_DEFINITIONS['qualified_referrals_given'];
        if (! Schema::hasTable('referrals')) {
            return $this->emptyChecklistItem('qualified_referrals_given', $label);
        }

        $query = Referral::query()
            ->with('toUser:id,display_name,first_name,last_name,company_name')
            ->where('from_user_id', $userId);
        $this->applySoftDeleteFilters($query, 'referrals');
        $this->applyDateRange($query, 'referrals', ['referral_date', 'created_at'], $start, $end);

        $referrals = $query->orderByDesc('referral_date')->orderByDesc('created_at')->get();
        $relatedItems = $referrals->map(fn (Referral $referral): array => [
            'id' => (string) $referral->id,
            'peer_id' => $referral->to_user_id ? (string) $referral->to_user_id : null,
            'peer_name' => $this->displayName($referral->toUser),
            'company_name' => $this->stringOrNull($referral->toUser?->company_name),
            'referral_of' => $this->stringOrNull($referral->referral_of ?? null),
            'referral_date' => $this->formatDate($referral->referral_date ?? null),
        ])->values()->all();

        return $this->checklistItem('qualified_referrals_given', $label, $referrals->count(), $relatedItems, true);
    }

    private function collaborationChecklistItem(string $userId, Carbon $start, Carbon $end, array $default): array
    {
        if (! Schema::hasTable('p2p_meetings')) {
            return $default;
        }

        $query = P2pMeeting::query()
            ->with('peer:id,display_name,first_name,last_name,company_name')
            ->where('initiator_user_id', $userId);
        $this->applySoftDeleteFilters($query, 'p2p_meetings');
        $this->applyDateRange($query, 'p2p_meetings', ['meeting_date', 'created_at'], $start, $end);

        $meetings = $query->orderByDesc('meeting_date')->orderByDesc('created_at')->get();
        if ($meetings->isEmpty()) {
            return $default;
        }

        $relatedItems = $meetings->map(fn (P2pMeeting $meeting): array => [
            'id' => (string) $meeting->id,
            'peer_id' => $meeting->peer_user_id ? (string) $meeting->peer_user_id : null,
            'peer_name' => $this->displayName($meeting->peer),
            'company_name' => $this->stringOrNull($meeting->peer?->company_name),
            'meeting_date' => $this->formatDate($meeting->meeting_date ?? null),
        ])->values()->all();

        return $this->checklistItem('collaboration_connections', self::CHECKLIST_DEFINITIONS['collaboration_connections'], $meetings->count(), $relatedItems, true);
    }

    private function mergeHistoryChecklistItems(array $items, string $userId, Carbon $start, Carbon $end): array
    {
        if (! Schema::hasTable('life_impact_histories')) {
            return $items;
        }

        $table = (new LifeImpactHistory())->getTable();
        $query = LifeImpactHistory::query()->where('user_id', $userId);
        $this->applyHistoryCountableFilters($query, $table);
        $this->applyDateRange($query, $table, ['created_at'], $start, $end);

        $histories = $query->orderByDesc('created_at')->get();

        foreach (self::HISTORY_ACTION_ALIASES as $checklistKey => $aliases) {
            $matches = $histories->filter(fn (LifeImpactHistory $history): bool => $this->historyMatches($history, $aliases));
            if ($matches->isEmpty()) {
                continue;
            }

            $existingRelatedItems = collect($items[$checklistKey]['related_items'] ?? []);
            $historyRelatedItems = $matches->map(fn (LifeImpactHistory $history): array => [
                'id' => (string) $history->id,
                'activity_type' => $this->stringOrNull($history->activity_type ?? null),
                'activity_id' => $this->stringOrNull($history->activity_id ?? null),
                'title' => $this->stringOrNull($history->title ?? null),
                'description' => $this->stringOrNull($history->description ?? null),
                'impact_value' => $history->resolveImpactValue(),
                'created_at' => $this->formatDate($history->created_at ?? null),
            ]);

            $count = max((int) ($items[$checklistKey]['count'] ?? 0), $matches->count());
            if ($checklistKey === 'collaboration_connections') {
                $count = max((int) ($items[$checklistKey]['count'] ?? 0), $matches->count());
            }

            $items[$checklistKey] = $this->checklistItem(
                $checklistKey,
                self::CHECKLIST_DEFINITIONS[$checklistKey],
                $count,
                $existingRelatedItems->merge($historyRelatedItems)->values()->all(),
                true
            );
        }

        return $items;
    }

    private function businessDealBaseQuery(string $userId)
    {
        $query = BusinessDeal::query()
            ->where(function ($subQuery) use ($userId): void {
                $subQuery->where('from_user_id', $userId)
                    ->orWhere('to_user_id', $userId);
            });

        $this->applySoftDeleteFilters($query, 'business_deals');

        if (Schema::hasColumn('business_deals', 'status')) {
            $query->whereIn('status', ['approved', 'completed']);
        }

        return $query;
    }

    private function businessDealPeer(BusinessDeal $deal, string $userId): array
    {
        $peer = (string) ($deal->from_user_id ?? '') === $userId ? $deal->toUser : $deal->fromUser;

        return [
            'peer_id' => $peer?->id ? (string) $peer->id : null,
            'peer_name' => $this->displayName($peer),
            'company_name' => $this->stringOrNull($peer?->company_name),
            'amount' => $deal->deal_amount !== null ? (float) $deal->deal_amount : null,
            'deal_date' => $this->formatDate($deal->deal_date ?? null),
        ];
    }

    private function script(array $userInfo, array $summary, array $businessDeals, array $checklistItems): array
    {
        $displayName = $userInfo['display_name'] ?: 'Peer';
        $companyName = $userInfo['company_name'] ?: 'my business';
        $category = $userInfo['category'] ?: 'business';

        return [
            'greeting_text' => 'Hello Peers,',
            'introduction_text' => "My name is {$displayName}. I run {$companyName} in the {$category} category.",
            'monthly_lives_impacted_text' => 'This month I impacted ' . (int) $summary['total_lives_impacted_this_month'] . ' lives through Peers activities.',
            'monthly_business_done_text' => 'This month I did business worth ' . $this->formatAmount((float) $summary['total_business_done_with_peers_this_month']) . ' with Peers.',
            'checklist_items' => $checklistItems,
            'lifetime_impact_text' => 'My lifetime lives impacted count is ' . (int) $summary['lifetime_total_lives_impacted'] . '.',
            'business_deals_text' => 'I recorded ' . (int) $businessDeals['deals_count'] . ' business deal(s) this month totalling ' . $this->formatAmount((float) $businessDeals['total_amount']) . '.',
            'meaningful_progress_label' => 'Meaningful progress I made this month',
            'next_month_goal_label' => 'My goal for next month',
            'story_label' => 'Experience or story I would like to share (optional)',
            'closing_text' => 'Thank you Peers for the support, referrals, collaboration, and opportunities.',
        ];
    }

    private function emptyChecklistItem(string $key, string $label): array
    {
        return $this->checklistItem($key, $label, 0, [], false);
    }

    private function checklistItem(string $key, string $label, int $count, array $relatedItems, bool $available): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'count' => $count,
            'related_items' => $relatedItems,
            'display_text' => $count > 0 ? $label . ': ' . $count : $label,
            'is_available' => $available,
        ];
    }

    private function historyMatches(LifeImpactHistory $history, array $aliases): bool
    {
        $values = [
            $history->action_key ?? null,
            $history->action_label ?? null,
            $history->activity_type ?? null,
            $history->impact_category ?? null,
            $history->title ?? null,
        ];

        $normalizedAliases = collect($aliases)->map(fn (string $alias): string => $this->normalizeKey($alias))->all();

        foreach ($values as $value) {
            $normalizedValue = $this->normalizeKey($value);
            if ($normalizedValue === '') {
                continue;
            }

            foreach ($normalizedAliases as $alias) {
                if ($normalizedValue === $alias || Str::contains($normalizedValue, $alias)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function applyHistoryCountableFilters($query, string $table): void
    {
        if (Schema::hasColumn($table, 'status')) {
            $query->where('status', 'approved');
        }

        if (Schema::hasColumn($table, 'counted_in_total')) {
            $query->where(function ($subQuery): void {
                $subQuery->where('counted_in_total', true)->orWhereNull('counted_in_total');
            });
        }
    }

    private function applySoftDeleteFilters($query, string $table): void
    {
        if (Schema::hasColumn($table, 'is_deleted')) {
            $query->where(function ($subQuery): void {
                $subQuery->where('is_deleted', false)->orWhereNull('is_deleted');
            });
        }

        if (Schema::hasColumn($table, 'deleted_at')) {
            $query->whereNull('deleted_at');
        }
    }

    private function applyDateRange($query, string $table, array $columns, Carbon $start, Carbon $end): void
    {
        $dateColumn = collect($columns)->first(fn (string $column): bool => Schema::hasColumn($table, $column));

        if (! $dateColumn) {
            return;
        }

        $query->whereDate($dateColumn, '>=', $start->toDateString())
            ->whereDate($dateColumn, '<=', $end->toDateString());
    }

    private function lifeImpactSumExpression(string $table): string
    {
        if (Schema::hasColumn($table, 'life_impacted') && Schema::hasColumn($table, 'impact_value')) {
            return 'COALESCE(life_impacted, impact_value, 0)';
        }

        if (Schema::hasColumn($table, 'life_impacted')) {
            return 'COALESCE(life_impacted, 0)';
        }

        if (Schema::hasColumn($table, 'impact_value')) {
            return 'COALESCE(impact_value, 0)';
        }

        return '0';
    }

    private function profilePhotoUrl(User $user): ?string
    {
        if (! empty($user->profile_photo_file_id)) {
            return url('/api/v1/files/' . $user->profile_photo_file_id);
        }

        return $this->stringOrNull($user->profile_photo_url ?? null);
    }

    private function displayName(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        $displayName = $this->stringOrNull($user->display_name ?? null);
        if ($displayName) {
            return $displayName;
        }

        $fullName = trim((string) ($user->first_name ?? '') . ' ' . (string) ($user->last_name ?? ''));

        return $fullName !== '' ? $fullName : null;
    }

    private function normalizeArray(mixed $value): array
    {
        if ($value instanceof Collection) {
            $value = $value->all();
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(fn ($item): string => trim((string) $item))
            ->filter(fn (string $item): bool => $item !== '')
            ->values()
            ->all();
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    private function formatDate(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    private function normalizeKey(mixed $value): string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return '';
        }

        return Str::of((string) $value)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->toString();
    }
}
