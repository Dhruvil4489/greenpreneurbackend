<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReferralReportController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $records = $this->summaryQuery($filters)
            ->paginate($filters['per_page'])
            ->withQueryString();

        $referredUsersByReferrer = $this->referredUsersForSummaryRows(
            $records->getCollection()->pluck('referrer_user_id')->filter()->map(fn ($id) => (string) $id)->all(),
            $filters
        );

        return view('admin.referral_report.index', [
            'records' => $records,
            'filters' => $filters,
            'referredUsersByReferrer' => $referredUsersByReferrer,
            'hasRewardStatus' => $this->hasReferralColumn('reward_status'),
        ]);
    }

    public function show(Request $request, string $referrer_user_id): View
    {
        $filters = $this->detailFilters($request);

        $records = $this->detailQuery($referrer_user_id, $filters)
            ->orderByDesc(DB::raw($this->referralDateExpression()))
            ->orderByDesc('rd.id')
            ->paginate($filters['per_page'])
            ->withQueryString();

        $summary = $this->summaryQuery(['referrer_user_id' => $referrer_user_id, 'sort' => 'last_referral_date', 'direction' => 'desc'])
            ->first();

        return view('admin.referral_report.show', [
            'records' => $records,
            'summary' => $summary,
            'filters' => $filters,
            'referrerUserId' => $referrer_user_id,
            'hasRewardStatus' => $this->hasReferralColumn('reward_status'),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $filename = 'referral_report_' . now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($filters): void {
            @ini_set('zlib.output_compression', '0');
            @ini_set('output_buffering', '0');
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }

            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'Referrer User ID',
                'Referrer Name',
                'Referrer Email',
                'Referrer Phone',
                'Referrer Company',
                'Referral Code',
                'Total Referred Users Count',
                'Total Coins Granted',
                'Last Referral Date',
                'Referred User ID',
                'Referred User Name',
                'Referred User Email',
                'Referred User Phone',
                'Referred User Company',
                'Referred User City',
                'Coins Granted',
                'Reward Status',
                'Used At / Registered At',
            ]);

            $this->exportRowsQuery($filters)
                ->orderByDesc(DB::raw($this->referralDateExpression()))
                ->orderBy('rd.referrer_user_id')
                ->chunk(500, function ($rows) use ($handle): void {
                    foreach ($rows as $row) {
                        fputcsv($handle, [
                            $row->referrer_user_id,
                            $row->referrer_name ?: 'Deleted / Unknown User',
                            $row->referrer_email ?: '',
                            $row->referrer_phone ?: '',
                            $row->referrer_company ?: '',
                            $row->referral_code ?: '',
                            (int) $row->total_referred_users,
                            (int) $row->total_coins_granted,
                            $row->last_referral_date ?: '',
                            $row->referred_user_id ?: '',
                            $row->referred_name ?: 'Deleted / Unknown User',
                            $row->referred_email ?: '',
                            $row->referred_phone ?: '',
                            $row->company_name ?: '',
                            $row->city ?: '',
                            (int) $row->coins,
                            $row->reward_status ?: '',
                            $row->used_at ?: '',
                        ]);
                    }
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'referral_code' => ['nullable', 'string', 'max:100'],
            'referred_q' => ['nullable', 'string', 'max:255'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'reward_status' => ['nullable', 'string', 'max:50'],
            'sort' => ['nullable', 'in:total_referred_users,last_referral_date'],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'in:10,20,50,100'],
        ]);

        return [
            'q' => trim((string) ($validated['q'] ?? '')),
            'referral_code' => trim((string) ($validated['referral_code'] ?? '')),
            'referred_q' => trim((string) ($validated['referred_q'] ?? '')),
            'from' => (string) ($validated['from'] ?? ''),
            'to' => (string) ($validated['to'] ?? ''),
            'reward_status' => trim((string) ($validated['reward_status'] ?? '')),
            'sort' => (string) ($validated['sort'] ?? 'last_referral_date'),
            'direction' => (string) ($validated['direction'] ?? 'desc'),
            'per_page' => (int) ($validated['per_page'] ?? 20),
        ];
    }

    private function detailFilters(Request $request): array
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'in:10,20,50,100'],
        ]);

        return ['per_page' => (int) ($validated['per_page'] ?? 20)];
    }

    private function summaryQuery(array $filters, bool $applySorting = true): Builder
    {
        $query = DB::table('referraldata as rd')
            ->leftJoin('users as referrer', function ($join): void {
                $join->on(DB::raw('rd.referrer_user_id::text'), '=', DB::raw('referrer.id::text'));
            })
            ->leftJoin('users as referred', function ($join): void {
                $join->on(DB::raw('rd.referred_user_id::text'), '=', DB::raw('referred.id::text'));
            })
            ->selectRaw($this->summarySelectSql())
            ->groupBy('rd.referrer_user_id')
            ->when($this->hasUserColumn('display_name'), fn ($query) => $query->groupBy('referrer.display_name'))
            ->when($this->hasUserColumn('first_name'), fn ($query) => $query->groupBy('referrer.first_name'))
            ->when($this->hasUserColumn('last_name'), fn ($query) => $query->groupBy('referrer.last_name'))
            ->when($this->hasUserColumn('email'), fn ($query) => $query->groupBy('referrer.email'))
            ->when($this->hasUserColumn('phone'), fn ($query) => $query->groupBy('referrer.phone'))
            ->when($this->hasUserColumn('company_name'), fn ($query) => $query->groupBy('referrer.company_name'));

        $this->applySummaryFilters($query, $filters);

        if ($applySorting) {
            $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
            $sort = in_array(($filters['sort'] ?? ''), ['total_referred_users', 'last_referral_date'], true)
                ? $filters['sort']
                : 'last_referral_date';
            $query->orderBy($sort, $direction)->orderBy('referrer_name');
        }

        return $query;
    }

    private function detailQuery(string $referrerUserId, array $filters): Builder
    {
        return DB::table('referraldata as rd')
            ->leftJoin('users as referred', function ($join): void {
                $join->on(DB::raw('rd.referred_user_id::text'), '=', DB::raw('referred.id::text'));
            })
            ->whereRaw('rd.referrer_user_id::text = ?', [$referrerUserId])
            ->selectRaw($this->detailSelectSql());
    }

    private function referredUsersForSummaryRows(array $referrerUserIds, array $filters): \Illuminate\Support\Collection
    {
        if ($referrerUserIds === []) {
            return collect();
        }

        $query = DB::table('referraldata as rd')
            ->leftJoin('users as referred', function ($join): void {
                $join->on(DB::raw('rd.referred_user_id::text'), '=', DB::raw('referred.id::text'));
            })
            ->whereIn(DB::raw('rd.referrer_user_id::text'), $referrerUserIds)
            ->selectRaw($this->detailSelectSql());

        $this->applyReferralDataFilters($query, $filters);
        $this->applyReferredUserFilters($query, $filters);

        return $query
            ->orderByDesc(DB::raw($this->referralDateExpression()))
            ->orderByDesc('rd.id')
            ->get()
            ->groupBy(fn ($row) => (string) $row->referrer_user_id);
    }

    private function exportRowsQuery(array $filters): Builder
    {
        $query = DB::table('referraldata as rd')
            ->leftJoin('users as referrer', function ($join): void {
                $join->on(DB::raw('rd.referrer_user_id::text'), '=', DB::raw('referrer.id::text'));
            })
            ->leftJoin('users as referred', function ($join): void {
                $join->on(DB::raw('rd.referred_user_id::text'), '=', DB::raw('referred.id::text'));
            })
            ->selectRaw($this->exportRowsSelectSql());

        $this->applySummaryFilters($query, $filters);

        return $query;
    }

    private function applySummaryFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['referrer_user_id'])) {
            $query->whereRaw('rd.referrer_user_id::text = ?', [(string) $filters['referrer_user_id']]);
        }

        if (($filters['q'] ?? '') !== '') {
            $like = '%' . $filters['q'] . '%';
            $query->where(function (Builder $inner) use ($like): void {
                if ($this->hasReferralColumn('referral_code')) {
                    $inner->where('rd.referral_code', 'ilike', $like);
                } else {
                    $inner->whereRaw('1 = 0');
                }

                if ($this->hasReferralColumn('referrer_email')) {
                    $inner->orWhere('rd.referrer_email', 'ilike', $like);
                }

                foreach (['display_name', 'first_name', 'last_name', 'email', 'phone'] as $column) {
                    if ($this->hasUserColumn($column)) {
                        $inner->orWhere('referrer.' . $column, 'ilike', $like);
                    }
                }
            });
        }

        $this->applyReferralDataFilters($query, $filters);
        $this->applyReferredUserFilters($query, $filters);
    }

    private function applyReferralDataFilters(Builder $query, array $filters): void
    {
        if (($filters['referral_code'] ?? '') !== '' && $this->hasReferralColumn('referral_code')) {
            $query->where('rd.referral_code', 'ilike', '%' . $filters['referral_code'] . '%');
        }

        $dateExpression = DB::raw($this->referralDateExpression());
        if (($filters['from'] ?? '') !== '') {
            $query->where($dateExpression, '>=', Carbon::parse($filters['from'])->startOfDay()->format('Y-m-d H:i:s'));
        }

        if (($filters['to'] ?? '') !== '') {
            $query->where($dateExpression, '<=', Carbon::parse($filters['to'])->endOfDay()->format('Y-m-d H:i:s'));
        }

        if (($filters['reward_status'] ?? '') !== '' && $this->hasReferralColumn('reward_status')) {
            $query->where('rd.reward_status', $filters['reward_status']);
        }
    }

    private function applyReferredUserFilters(Builder $query, array $filters): void
    {
        if (($filters['referred_q'] ?? '') === '') {
            return;
        }

        $like = '%' . $filters['referred_q'] . '%';
        $query->where(function (Builder $inner) use ($like): void {
            $inner->whereRaw('1 = 0');

            foreach (['display_name', 'first_name', 'last_name', 'email', 'phone'] as $column) {
                if ($this->hasUserColumn($column)) {
                    $inner->orWhere('referred.' . $column, 'ilike', $like);
                }
            }
        });
    }

    private function summarySelectSql(): string
    {
        return implode(",\n", [
            'rd.referrer_user_id',
            $this->referrerNameExpression() . ' as referrer_name',
            $this->referrerEmailSummaryExpression() . ' as referrer_email',
            $this->userTextColumn('referrer', 'phone') . ' as referrer_phone',
            $this->userTextColumn('referrer', 'company_name') . ' as referrer_company',
            $this->referralCodesAggregateExpression() . ' as referral_codes',
            'COUNT(DISTINCT rd.referred_user_id) as total_referred_users',
            $this->coinsGrantedExpression() . ' as total_coins_granted',
            'MAX(' . $this->referralDateExpression() . ') as last_referral_date',
        ]);
    }

    private function detailSelectSql(): string
    {
        return implode(",\n", [
            'rd.id',
            'rd.referrer_user_id',
            'rd.referred_user_id',
            $this->referredNameExpression() . ' as referred_name',
            $this->userTextColumn('referred', 'email') . ' as referred_email',
            $this->userTextColumn('referred', 'phone') . ' as referred_phone',
            $this->userTextColumn('referred', 'company_name') . ' as company_name',
            $this->userTextColumn('referred', 'city') . ' as city',
            $this->referralCodeExpression() . ' as referral_code',
            $this->hasReferralColumn('coins') ? 'COALESCE(rd.coins, 0) as coins' : '0 as coins',
            $this->hasReferralColumn('reward_status') ? "COALESCE(NULLIF(rd.reward_status, ''), 'pending') as reward_status" : "'—' as reward_status",
            $this->referralDateExpression() . ' as used_at',
        ]);
    }


    private function exportRowsSelectSql(): string
    {
        return implode(",\n", [
            'rd.referrer_user_id',
            $this->referrerNameExpression() . ' as referrer_name',
            $this->referrerEmailRowExpression() . ' as referrer_email',
            $this->userTextColumn('referrer', 'phone') . ' as referrer_phone',
            $this->userTextColumn('referrer', 'company_name') . ' as referrer_company',
            $this->referralCodeExpression() . ' as referral_code',
            'COUNT(rd.referred_user_id) OVER (PARTITION BY rd.referrer_user_id) as total_referred_users',
            $this->coinsGrantedWindowExpression() . ' as total_coins_granted',
            'MAX(' . $this->referralDateExpression() . ') OVER (PARTITION BY rd.referrer_user_id) as last_referral_date',
            'rd.referred_user_id',
            $this->referredNameExpression() . ' as referred_name',
            $this->userTextColumn('referred', 'email') . ' as referred_email',
            $this->userTextColumn('referred', 'phone') . ' as referred_phone',
            $this->userTextColumn('referred', 'company_name') . ' as company_name',
            $this->userTextColumn('referred', 'city') . ' as city',
            $this->hasReferralColumn('coins') ? 'COALESCE(rd.coins, 0) as coins' : '0 as coins',
            $this->hasReferralColumn('reward_status') ? "COALESCE(NULLIF(rd.reward_status, ''), 'pending') as reward_status" : "'—' as reward_status",
            $this->referralDateExpression() . ' as used_at',
        ]);
    }

    private function referrerEmailSummaryExpression(): string
    {
        $userEmail = $this->userTextColumn('referrer', 'email');

        if (! $this->hasReferralColumn('referrer_email')) {
            return $userEmail;
        }

        return "COALESCE(NULLIF({$userEmail}, ''), MAX(NULLIF(rd.referrer_email, '')), '')";
    }

    private function referrerEmailRowExpression(): string
    {
        $userEmail = $this->userTextColumn('referrer', 'email');

        if (! $this->hasReferralColumn('referrer_email')) {
            return $userEmail;
        }

        return "COALESCE(NULLIF({$userEmail}, ''), NULLIF(rd.referrer_email, ''), '')";
    }

    private function referralCodesAggregateExpression(): string
    {
        if (! $this->hasReferralColumn('referral_code')) {
            return "''";
        }

        return "COALESCE(string_agg(DISTINCT NULLIF(rd.referral_code, ''), ', '), '')";
    }

    private function referralCodeExpression(): string
    {
        return $this->hasReferralColumn('referral_code') ? "COALESCE(NULLIF(rd.referral_code, ''), '')" : "''";
    }

    private function referrerNameExpression(): string
    {
        $parts = [];
        if ($this->hasUserColumn('first_name')) {
            $parts[] = 'referrer.first_name';
        }
        if ($this->hasUserColumn('last_name')) {
            $parts[] = 'referrer.last_name';
        }

        $fullName = $parts ? "NULLIF(trim(concat_ws(' ', " . implode(', ', $parts) . ")), '')" : 'NULL';
        $display = $this->hasUserColumn('display_name') ? "NULLIF(referrer.display_name, '')" : 'NULL';

        return "COALESCE({$display}, {$fullName}, 'Deleted / Unknown User')";
    }

    private function referredNameExpression(): string
    {
        $parts = [];
        if ($this->hasUserColumn('first_name')) {
            $parts[] = 'referred.first_name';
        }
        if ($this->hasUserColumn('last_name')) {
            $parts[] = 'referred.last_name';
        }

        $fullName = $parts ? "NULLIF(trim(concat_ws(' ', " . implode(', ', $parts) . ")), '')" : 'NULL';
        $display = $this->hasUserColumn('display_name') ? "NULLIF(referred.display_name, '')" : 'NULL';

        return "COALESCE({$display}, {$fullName}, 'Deleted / Unknown User')";
    }

    private function userTextColumn(string $alias, string $column): string
    {
        return $this->hasUserColumn($column) ? "COALESCE(NULLIF({$alias}.{$column}, ''), '')" : "''";
    }

    private function referralDateExpression(): string
    {
        $columns = [];
        if ($this->hasReferralColumn('used_at')) {
            $columns[] = 'rd.used_at';
        }
        if ($this->hasReferralColumn('created_at')) {
            $columns[] = 'rd.created_at';
        }

        return $columns ? 'COALESCE(' . implode(', ', $columns) . ')' : 'NULL';
    }

    private function coinsGrantedExpression(): string
    {
        if (! $this->hasReferralColumn('coins')) {
            return '0';
        }

        if ($this->hasReferralColumn('reward_status')) {
            return "SUM(CASE WHEN rd.reward_status = 'granted' THEN COALESCE(rd.coins, 0) ELSE 0 END)";
        }

        return 'SUM(COALESCE(rd.coins, 0))';
    }

    private function coinsGrantedWindowExpression(): string
    {
        if (! $this->hasReferralColumn('coins')) {
            return '0';
        }

        if ($this->hasReferralColumn('reward_status')) {
            return "SUM(CASE WHEN rd.reward_status = 'granted' THEN COALESCE(rd.coins, 0) ELSE 0 END) OVER (PARTITION BY rd.referrer_user_id)";
        }

        return 'SUM(COALESCE(rd.coins, 0)) OVER (PARTITION BY rd.referrer_user_id)';
    }

    private function hasReferralColumn(string $column): bool
    {
        static $columns = [];
        return $columns[$column] ??= Schema::hasColumn('referraldata', $column);
    }

    private function hasUserColumn(string $column): bool
    {
        static $columns = [];
        return $columns[$column] ??= Schema::hasColumn('users', $column);
    }
}
