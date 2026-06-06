<?php

namespace App\Http\Controllers\Admin;

use Carbon\Carbon;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ActivitiesExportRequest;
use App\Models\BusinessDeal;
use App\Models\LeaderInterestSubmission;
use App\Models\P2pMeeting;
use App\Models\PeerRecommendation;
use App\Models\Referral;
use App\Models\Requirement;
use App\Models\Testimonial;
use App\Models\User;
use App\Models\VisitorRegistration;
use App\Services\Admin\IndustryScopeService;
use App\Support\AdminCircleScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\View\View;

// NOTE: After deploy run `php artisan optimize:clear` (optional) `composer dump-autoload`.
class ActivitiesController extends Controller
{

    public function index(Request $request): View
    {
        $filters = $this->buildFilters($request);

        $admin = auth('admin')->user();

        $summaryQuery = $this->buildPeerSummaryQuery($filters, $admin);

        $members = (clone $summaryQuery)
            ->orderByDesc('p2p_completed_count')
            ->orderByDesc('testimonials_count')
            ->orderByDesc('referrals_count')
            ->orderBy('peer_name')
            ->paginate($filters['per_page'])
            ->withQueryString();

        $topDistrictPeers = $this->buildTopDistrictPeers(clone $summaryQuery);

        $circles = $this->buildCircleFilterOptions($admin);

        return view('admin.activities.index', [
            'members' => $members,
            'filters' => $filters,
            'circles' => $circles,
            'topDistrictPeers' => $topDistrictPeers,
        ]);
    }


    private function buildTopDistrictPeers($summaryQuery)
    {
        return $summaryQuery
            ->get()
            ->map(function ($peer) {
                $peer->performance_score = collect([
                    'testimonials_count',
                    'referrals_count',
                    'requirements_count',
                    'business_deals_count',
                    'p2p_completed_count',
                    'become_leader_count',
                    'recommend_peer_count',
                    'register_visitor_count',
                ])->sum(fn (string $column) => (int) ($peer->{$column} ?? 0));

                return $peer;
            })
            ->sortByDesc('performance_score')
            ->take(5)
            ->values();
    }

    private function buildPeerSummaryQuery(array $filters, $admin)
    {
        $from = $filters['from_dt'] ?? null;
        $to = $filters['to_dt'] ?? null;

        $query = User::query()
            ->from('users')
            ->leftJoin('cities', 'cities.id', '=', 'users.city_id')
            ->select([
                'users.id',
                'users.email',
                'users.company_name',
                DB::raw("trim(coalesce(users.display_name, '' )) as display_name"),
                DB::raw("coalesce(nullif(trim(coalesce(users.display_name, '')), ''), nullif(trim(concat(coalesce(users.first_name, ''), ' ', coalesce(users.last_name, ''))), ''), users.email) as peer_name"),
                DB::raw("coalesce(nullif(trim(coalesce(users.city, '')), ''), cities.name) as city_name"),
            ]);

        $this->applyCircleScopeToUsersQuery($query, $admin);
        app(IndustryScopeService::class)->applyToUsersQuery($query, $admin);

        $query->selectSub($this->primaryCircleSubquery('name'), 'circle_name');

        $query->selectSub(function ($sub) use ($from, $to) {
            $sub->from('testimonials')
                ->selectRaw('count(*)')
                ->where(function ($query) {
                    $query->whereColumn('testimonials.from_user_id', 'users.id')
                        ->orWhereColumn('testimonials.to_user_id', 'users.id');
                })
                ->where('testimonials.is_deleted', false)
                ->whereNull('testimonials.deleted_at');
            $this->applyDateRangeToSubQuery($sub, $from, $to, 'testimonials.created_at');
        }, 'testimonials_count');

        $query->selectSub(function ($sub) use ($from, $to) {
            $sub->from('referrals')
                ->selectRaw('count(*)')
                ->where(function ($query) {
                    $query->whereColumn('referrals.from_user_id', 'users.id')
                        ->orWhereColumn('referrals.to_user_id', 'users.id');
                })
                ->where('referrals.is_deleted', false)
                ->whereNull('referrals.deleted_at');
            $this->applyDateRangeToSubQuery($sub, $from, $to, 'referrals.created_at');
        }, 'referrals_count');

        $query->selectSub(function ($sub) use ($from, $to) {
            $sub->from('business_deals')
                ->selectRaw('count(*)')
                ->where(function ($query) {
                    $query->whereColumn('business_deals.from_user_id', 'users.id')
                        ->orWhereColumn('business_deals.to_user_id', 'users.id');
                })
                ->where('business_deals.is_deleted', false)
                ->whereNull('business_deals.deleted_at');
            $this->applyDateRangeToSubQuery($sub, $from, $to, 'business_deals.created_at');
        }, 'business_deals_count');

        $query->selectSub(function ($sub) use ($from, $to) {
            $sub->from('p2p_meetings')
                ->selectRaw('count(*)')
                ->where(function ($query) {
                    $query->whereColumn('p2p_meetings.initiator_user_id', 'users.id')
                        ->orWhereColumn('p2p_meetings.peer_user_id', 'users.id');
                })
                ->where('p2p_meetings.is_deleted', false)
                ->whereNull('p2p_meetings.deleted_at')
                ->whereDate('p2p_meetings.meeting_date', '<', now()->toDateString());
            $this->applyDateRangeToSubQuery($sub, $from, $to, 'p2p_meetings.created_at');
        }, 'p2p_completed_count');

        $query->selectSub(function ($sub) use ($from, $to) {
            $sub->from('requirements')
                ->selectRaw('count(*)')
                ->whereColumn('requirements.user_id', 'users.id')
                ->whereNull('requirements.deleted_at');
            $this->applyDateRangeToSubQuery($sub, $from, $to, 'requirements.created_at');
        }, 'requirements_count');

        $query->selectSub(function ($sub) use ($from, $to) {
            $sub->from('leader_interest_submissions')
                ->selectRaw('count(*)')
                ->whereColumn('leader_interest_submissions.user_id', 'users.id');
            $this->applyDateRangeToSubQuery($sub, $from, $to, 'leader_interest_submissions.created_at');
        }, 'become_leader_count');

        $query->selectSub(function ($sub) use ($from, $to) {
            $sub->from('peer_recommendations')
                ->selectRaw('count(*)')
                ->whereColumn('peer_recommendations.user_id', 'users.id');
            $this->applyDateRangeToSubQuery($sub, $from, $to, 'peer_recommendations.created_at');
        }, 'recommend_peer_count');

        $query->selectSub(function ($sub) use ($from, $to) {
            $sub->from('visitor_registrations')
                ->selectRaw('count(*)')
                ->whereColumn('visitor_registrations.user_id', 'users.id');
            $this->applyDateRangeToSubQuery($sub, $from, $to, 'visitor_registrations.created_at');
        }, 'register_visitor_count');

        if ($filters['q'] !== '') {
            $like = "%{$filters['q']}%";
            $query->where(function ($q) use ($like) {
                $q->where('users.display_name', 'ILIKE', $like)
                    ->orWhere('users.first_name', 'ILIKE', $like)
                    ->orWhere('users.last_name', 'ILIKE', $like)
                    ->orWhere('users.company_name', 'ILIKE', $like)
                    ->orWhere('users.city', 'ILIKE', $like)
                    ->orWhere('cities.name', 'ILIKE', $like);
            });
        }


        if (! empty($filters['circle_id']) && $filters['circle_id'] !== 'any') {
            $query->whereExists(function ($sub) use ($filters) {
                $sub->selectRaw('1')
                    ->from('circle_members as cm_filter')
                    ->whereColumn('cm_filter.user_id', 'users.id')
                    ->where('cm_filter.status', 'approved')
                    ->whereNull('cm_filter.deleted_at')
                    ->where('cm_filter.circle_id', $filters['circle_id']);
            });
        }

        return $query;
    }

    private function applyDateRangeToSubQuery($subQuery, ?Carbon $from, ?Carbon $to, string $column = 'created_at'): void
    {
        if ($from) {
            $subQuery->where($column, '>=', $from);
        }

        if ($to) {
            $subQuery->where($column, '<=', $to);
        }
    }

    private function buildFilters(Request $request, int $defaultPerPage = 20): array
    {
        $perPage = $request->integer('per_page') ?: $defaultPerPage;
        $perPage = in_array($perPage, [10, 20, 25, 50, 100, 500], true) ? $perPage : $defaultPerPage;

        $filters = [
            'q' => trim((string) $request->input('q', $request->input('search', ''))),
            'circle_id' => $request->input('circle_id', ''),
            'from' => $request->input('from'),
            'to' => $request->input('to'),
            'per_page' => $perPage,
        ];

        $filters['from_dt'] = $this->normalizeDateTime($filters['from'], false);
        $filters['to_dt'] = $this->normalizeDateTime($filters['to'], true);

        return $filters;
    }

    private function normalizeDateTime($value, bool $isEndOfDay): ?Carbon
    {
        $value = is_string($value) ? trim($value) : null;
        if (! $value) {
            return null;
        }

        try {
            $parsed = Carbon::parse($value);

            if (! str_contains($value, 'T') && ! str_contains($value, ':')) {
                return $isEndOfDay ? $parsed->endOfDay() : $parsed->startOfDay();
            }

            return $parsed;
        } catch (\Throwable $exception) {
            return null;
        }
    }

    private function primaryCircleSubquery(string $column)
    {
        return DB::table('circle_members as cm_primary')
            ->join('circles as c_primary', 'c_primary.id', '=', 'cm_primary.circle_id')
            ->whereColumn('cm_primary.user_id', 'users.id')
            ->where('cm_primary.status', 'approved')
            ->whereNull('cm_primary.deleted_at')
            ->orderByRaw("case when cm_primary.role::text in ('chair', 'vice_chair', 'secretary', 'founder', 'director', 'committee_leader') then 0 else 1 end")
            ->orderByDesc('cm_primary.joined_at')
            ->orderByDesc('cm_primary.created_at')
            ->limit(1)
            ->select("c_primary.{$column}");
    }

    private function buildCircleFilterOptions($admin)
    {
        $query = DB::table('circles')
            ->select('circles.id', 'circles.name')
            ->orderBy('circles.name');

        if (\App\Support\AdminAccess::isCircleScoped($admin)) {
            $circleId = AdminCircleScope::resolveCircleId($admin);
            if ($circleId) {
                $query->where('circles.id', $circleId);
            } else {
                $query->whereRaw('1=0');
            }
        }

        return $query->get();
    }

    public function testimonials(User $member, Request $request): View
    {
        $admin = auth('admin')->user();
        $this->ensureMemberInScope($member, $admin);
        $filters = $this->dateFilters($request);

        $items = Testimonial::query()
            ->with(['toUser'])
            ->where('from_user_id', $member->id)
            ->where('is_deleted', false)
            ->whereNull('deleted_at')
            ->when($filters['from'], fn ($query) => $query->whereDate('created_at', '>=', $filters['from']))
            ->when($filters['to'], fn ($query) => $query->whereDate('created_at', '<=', $filters['to']))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $allMediaIds = [];
        foreach ($items as $item) {
            $allMediaIds = array_merge($allMediaIds, \App\Models\File::extractIdsFromMedia($item->media));
        }
        $validMediaIds = \App\Models\File::getValidMediaIds(array_unique($allMediaIds));

        return view('admin.activities.list-testimonials', [
            'member' => $member,
            'items' => $items,
            'filters' => $filters,
            'validMediaIds' => $validMediaIds,
        ]);
    }

    public function referrals(User $member, Request $request): View
    {
        $admin = auth('admin')->user();
        $this->ensureMemberInScope($member, $admin);
        $filters = $this->dateFilters($request);

        $items = Referral::query()
            ->with(['toUser'])
            ->where('from_user_id', $member->id)
            ->where('is_deleted', false)
            ->whereNull('deleted_at')
            ->when($filters['from'], fn ($query) => $query->whereDate('created_at', '>=', $filters['from']))
            ->when($filters['to'], fn ($query) => $query->whereDate('created_at', '<=', $filters['to']))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.activities.list-referrals', [
            'member' => $member,
            'items' => $items,
            'filters' => $filters,
        ]);
    }

    public function businessDeals(User $member, Request $request): View
    {
        $admin = auth('admin')->user();
        $this->ensureMemberInScope($member, $admin);
        $filters = $this->dateFilters($request);

        $items = BusinessDeal::query()
            ->with(['toUser'])
            ->where('from_user_id', $member->id)
            ->where('is_deleted', false)
            ->whereNull('deleted_at')
            ->when($filters['from'], fn ($query) => $query->whereDate('created_at', '>=', $filters['from']))
            ->when($filters['to'], fn ($query) => $query->whereDate('created_at', '<=', $filters['to']))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.activities.list-business-deals', [
            'member' => $member,
            'items' => $items,
            'filters' => $filters,
        ]);
    }

    public function p2pMeetings(User $member, Request $request): View
    {
        $admin = auth('admin')->user();
        $this->ensureMemberInScope($member, $admin);
        $filters = $this->dateFilters($request);

        $items = P2pMeeting::query()
            ->with(['peer'])
            ->where('initiator_user_id', $member->id)
            ->where('is_deleted', false)
            ->whereNull('deleted_at')
            ->when($filters['from'], fn ($query) => $query->whereDate('created_at', '>=', $filters['from']))
            ->when($filters['to'], fn ($query) => $query->whereDate('created_at', '<=', $filters['to']))
            ->orderByDesc('meeting_date')
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.activities.list-p2p-meetings', [
            'member' => $member,
            'items' => $items,
            'filters' => $filters,
        ]);
    }

    public function requirements(User $member, Request $request): View
    {
        $admin = auth('admin')->user();
        $this->ensureMemberInScope($member, $admin);
        $filters = $this->dateFilters($request);

        $items = Requirement::query()
            ->with(['user'])
            ->where('user_id', $member->id)
            ->whereNull('deleted_at')
            ->when($filters['from'], fn ($query) => $query->whereDate('created_at', '>=', $filters['from']))
            ->when($filters['to'], fn ($query) => $query->whereDate('created_at', '<=', $filters['to']))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $allMediaIds = [];
        foreach ($items as $item) {
            $allMediaIds = array_merge($allMediaIds, \App\Models\File::extractIdsFromMedia($item->media));
        }
        $validMediaIds = \App\Models\File::getValidMediaIds(array_unique($allMediaIds));

        return view('admin.activities.list-requirements', [
            'member' => $member,
            'items' => $items,
            'filters' => $filters,
            'validMediaIds' => $validMediaIds,
        ]);
    }

    public function export(ActivitiesExportRequest $request): StreamedResponse
    {
        $validated = $request->validated();
        $activityType = $validated['activity_type'];
        $filenamePrefix = $activityType === 'summary' ? 'activities_summary' : 'activity_' . $activityType;
        $filename = $filenamePrefix . '_' . now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($request, $activityType) {
            @ini_set('zlib.output_compression', '0');
            @ini_set('output_buffering', '0');
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }

            $handle = fopen('php://output', 'w');

            try {
                fwrite($handle, "\xEF\xBB\xBF");
                fputcsv($handle, $this->buildHeaderRow($activityType));
                $this->streamRowsAsCsv($handle, $activityType, $request);
            } catch (\Throwable $e) {
                logger()->error('Activities export failed', [
                    'activity_type' => $activityType,
                    'error' => $e->getMessage(),
                ]);
            } finally {
                fclose($handle);
            }
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    private function countByMember($query, array $memberIds, string $primaryColumn, ?string $peerColumn): array
    {
        if ($memberIds === []) {
            return [];
        }

        $primaryQuery = (clone $query)
            ->whereIn($primaryColumn, $memberIds)
            ->selectRaw("{$primaryColumn} as member_id");

        return DB::query()
            ->fromSub($primaryQuery, 'activity_members')
            ->select('member_id', DB::raw('count(*) as total'))
            ->groupBy('member_id')
            ->pluck('total', 'member_id')
            ->all();
    }

    private function dateFilters(Request $request): array
    {
        return [
            'from' => $request->input('from'),
            'to' => $request->input('to'),
        ];
    }

    private function buildHeaderRow(string $activityType): array
    {
        if ($activityType === 'summary') {
            return [
                'Peer Name',
                'Email',
                'Company',
                'City',
                'Circle',
                'Testimonials',
                'Referrals',
                'Business Deals',
                'P2P Meetings Completed',
                'Requirements',
                'Become A Leader',
                'Recommend A Peer',
                'Register A Visitor',
            ];
        }

        return collect($this->exportColumns($activityType))
            ->pluck('label')
            ->all();
    }

    private function streamRowsAsCsv($handle, string $activityType, ActivitiesExportRequest $request): void
    {
        if ($activityType === 'summary') {
            $filters = $this->buildFilters($request, 500);

            $summaryQuery = $this->buildPeerSummaryQuery($filters, auth('admin')->user());

            if (($request->input('scope') ?? 'selected') === 'selected') {
                $summaryQuery->whereIn('users.id', $request->input('selected_member_ids', []));
            }

            $summaryQuery
                ->orderByDesc('p2p_completed_count')
                ->orderByDesc('testimonials_count')
                ->orderByDesc('referrals_count')
                ->orderBy('peer_name')
                ->chunk(500, function ($rows) use ($handle) {
                    foreach ($rows as $row) {
                        fputcsv($handle, [
                            $row->peer_name,
                            $row->email,
                            $row->company_name,
                            $row->city_name,
                            $row->circle_name ?: 'No Circle',
                            (int) ($row->testimonials_count ?? 0),
                            (int) ($row->referrals_count ?? 0),
                            (int) ($row->business_deals_count ?? 0),
                            (int) ($row->p2p_completed_count ?? 0),
                            (int) ($row->requirements_count ?? 0),
                            (int) ($row->become_leader_count ?? 0),
                            (int) ($row->recommend_peer_count ?? 0),
                            (int) ($row->register_visitor_count ?? 0),
                        ]);
                    }
                });

            return;
        }

        $query = $this->buildExportQuery($activityType, $request, $request->validated());
        $columns = $this->exportColumns($activityType);

        $chunkCallback = function ($rows) use ($handle, $columns) {
            $memberMap = $this->buildMemberMap($rows);

            foreach ($rows as $row) {
                $rowArray = (array) $row;
                $data = [];

                foreach ($columns as $column) {
                    $data[] = $this->resolveExportValue($column['key'], $rowArray, $memberMap);
                }

                fputcsv($handle, $data);
            }
        };

        $query->orderByDesc('activity.created_at')->chunk(500, $chunkCallback);
    }

    private function buildExportQuery(string $activityType, ActivitiesExportRequest $request, array $filters)
    {
        $memberKey = $this->resolveMemberKey($activityType);

        $query = DB::table($this->activityTable($activityType) . ' as activity')
            ->leftJoin('users as member_user', 'member_user.id', '=', 'activity.' . $memberKey)
            ->select($this->exportSelectColumns($activityType));

        $requiresPeer = $this->activityRequiresPeer($activityType);
        if ($requiresPeer) {
            $query->leftJoin('users as related_user', 'related_user.id', '=', $this->relatedUserJoinColumn($activityType));
        }
        $this->applyCircleScopeToActivityQuery(
            $query,
            auth('admin')->user(),
            'activity.' . $memberKey,
            $requiresPeer ? $this->relatedUserJoinColumn($activityType) : null
        );

        if (($filters['scope'] ?? null) === 'selected') {
            $memberIds = $filters['selected_member_ids'] ?? [];
            $query->whereIn('member_user.id', $memberIds);
        } else {
            $search = trim((string) ($filters['q'] ?? ''));
            $membership = $filters['membership_status'] ?? null;

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $like = "%{$search}%";
                    $q->where('member_user.display_name', 'ILIKE', $like)
                        ->orWhere('member_user.first_name', 'ILIKE', $like)
                        ->orWhere('member_user.last_name', 'ILIKE', $like);
                });
            }

            if ($membership && $membership !== 'all') {
                $query->where('member_user.membership_status', $membership);
            }
        }

        return $query;
    }

    private function applyCircleScopeToUsersQuery($query, $admin): void
    {
        AdminCircleScope::applyToUsersQuery($query, $admin);
    }

    private function ensureMemberInScope(User $member, $admin): void
    {
        if (! AdminCircleScope::userInScope($admin, $member->id) || ! app(IndustryScopeService::class)->userInScope($admin, (string) $member->id)) {
            abort(403);
        }
    }

    private function applyCircleScopeToActivityQuery($query, $admin, string $userColumn, ?string $peerColumn): void
    {
        AdminCircleScope::applyToActivityQuery($query, $admin, $userColumn, $peerColumn);
        app(IndustryScopeService::class)->applyToActivityQuery($query, $admin, array_filter([$userColumn, $peerColumn]));
    }

    private function activityRequiresPeer(string $activityType): bool
    {
        return in_array($activityType, ['testimonials', 'referrals', 'business_deals', 'p2p_meetings'], true);
    }

    private function exportColumns(string $activityType): array
    {
        return match ($activityType) {
            'requirements' => [
                ['key' => 'id', 'label' => 'ID'],
                ['key' => 'member_name', 'label' => 'User Name'],
                ['key' => 'member_email', 'label' => 'User Email'],
                ['key' => 'subject', 'label' => 'Subject'],
                ['key' => 'description', 'label' => 'Description'],
                ['key' => 'region', 'label' => 'Region'],
                ['key' => 'category', 'label' => 'Category'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'attachment_url', 'label' => 'Attachment URL'],
                ['key' => 'created_at', 'label' => 'Created At'],
            ],
            'testimonials' => [
                ['key' => 'member_name', 'label' => 'From Member Name'],
                ['key' => 'member_email', 'label' => 'From Member Email'],
                ['key' => 'related_name', 'label' => 'To Member Name'],
                ['key' => 'related_email', 'label' => 'To Member Email'],
                ['key' => 'content', 'label' => 'Content'],
                ['key' => 'attachment_url', 'label' => 'Attachment URL'],
                ['key' => 'created_at', 'label' => 'Created At'],
            ],
            'referrals' => [
                ['key' => 'id', 'label' => 'ID'],
                ['key' => 'related_name', 'label' => 'Referred Member Name'],
                ['key' => 'related_email', 'label' => 'Referred Member Email'],
                ['key' => 'referral_date', 'label' => 'Referral Date'],
                ['key' => 'referral_of', 'label' => 'Referral Of'],
                ['key' => 'referral_type', 'label' => 'Type'],
                ['key' => 'phone', 'label' => 'Phone'],
                ['key' => 'email', 'label' => 'Email'],
                ['key' => 'address', 'label' => 'Address'],
                ['key' => 'hot_value', 'label' => 'Hot Value'],
                ['key' => 'remarks', 'label' => 'Remarks'],
                ['key' => 'created_at', 'label' => 'Created At'],
            ],
            'business_deals' => [
                ['key' => 'id', 'label' => 'ID'],
                ['key' => 'related_name', 'label' => 'Deal With Name'],
                ['key' => 'related_email', 'label' => 'Deal With Email'],
                ['key' => 'deal_date', 'label' => 'Deal Date'],
                ['key' => 'deal_amount', 'label' => 'Amount'],
                ['key' => 'business_type', 'label' => 'Type'],
                ['key' => 'comment', 'label' => 'Comment'],
                ['key' => 'created_at', 'label' => 'Created At'],
            ],
            'p2p_meetings' => [
                ['key' => 'id', 'label' => 'ID'],
                ['key' => 'related_name', 'label' => 'Peer Name'],
                ['key' => 'related_email', 'label' => 'Peer Email'],
                ['key' => 'meeting_date', 'label' => 'Meeting Date'],
                ['key' => 'meeting_place', 'label' => 'Meeting Place'],
                ['key' => 'remarks', 'label' => 'Remarks'],
                ['key' => 'created_at', 'label' => 'Created At'],
            ],
            'become_a_leader' => [
                ['key' => 'member_name', 'label' => 'Peer Name'],
                ['key' => 'member_email', 'label' => 'Peer Email'],
                ['key' => 'applying_for', 'label' => 'Applying For'],
                ['key' => 'referred_name', 'label' => 'Referred Name'],
                ['key' => 'referred_mobile', 'label' => 'Referred Mobile'],
                ['key' => 'leadership_roles', 'label' => 'Leadership Roles'],
                ['key' => 'contribute_city', 'label' => 'City / Region'],
                ['key' => 'primary_domain', 'label' => 'Primary Domain'],
                ['key' => 'why_interested', 'label' => 'Why Interested'],
                ['key' => 'created_at', 'label' => 'Created At'],
            ],
            'recommend_peer' => [
                ['key' => 'member_name', 'label' => 'Peer Name'],
                ['key' => 'member_email', 'label' => 'Peer Email'],
                ['key' => 'peer_name', 'label' => 'Recommended Peer Name'],
                ['key' => 'peer_mobile', 'label' => 'Recommended Peer Mobile'],
                ['key' => 'how_well_known', 'label' => 'How Well Known'],
                ['key' => 'is_aware', 'label' => 'Is Aware'],
                ['key' => 'created_at', 'label' => 'Created At'],
            ],
            'register_visitor' => [
                ['key' => 'member_name', 'label' => 'Peer Name'],
                ['key' => 'member_email', 'label' => 'Peer Email'],
                ['key' => 'event_type', 'label' => 'Event Type'],
                ['key' => 'event_name', 'label' => 'Event Name'],
                ['key' => 'event_date', 'label' => 'Event Date'],
                ['key' => 'visitor_full_name', 'label' => 'Visitor Name'],
                ['key' => 'visitor_mobile', 'label' => 'Visitor Mobile'],
                ['key' => 'visitor_city', 'label' => 'Visitor City'],
                ['key' => 'visitor_business', 'label' => 'Visitor Business'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'coins_awarded', 'label' => 'Coins Awarded'],
                ['key' => 'created_at', 'label' => 'Created At'],
            ],
            default => [],
        };
    }

    private function resolveExportValue(string $key, array $row, array $memberMap)
    {
        return match ($key) {
            'member_name' => $this->resolveMemberName($row['member_id'] ?? null, $memberMap),
            'member_email' => $this->resolveMemberEmail($row['member_id'] ?? null, $memberMap),
            'related_name' => $this->resolveMemberName($row['related_member_id'] ?? null, $memberMap),
            'related_email' => $this->resolveMemberEmail($row['related_member_id'] ?? null, $memberMap),
            'region' => $this->resolveRegion($row['region_filter'] ?? null),
            'category' => $this->resolveCategory($row['category_filter'] ?? null),
            'attachment_url' => $this->resolveAttachmentUrl($row),
            'leadership_roles' => $this->formatLeadershipRoles($row['leadership_roles'] ?? null),
            'is_aware' => $this->formatYesNo($row['is_aware'] ?? null),
            'coins_awarded' => $this->formatYesNo($row['coins_awarded'] ?? null),
            default => $row[$key] ?? null,
        };
    }

    private function formatLeadershipRoles($value): ?string
    {
        if (! $value) {
            return null;
        }

        $decoded = is_string($value) ? json_decode($value, true) : $value;
        if (is_array($decoded)) {
            $decoded = array_filter($decoded);
            return $decoded ? implode(', ', $decoded) : null;
        }

        return (string) $value;
    }

    private function formatYesNo($value): ?string
    {
        if ($value === null) {
            return null;
        }

        return (bool) $value ? 'Yes' : 'No';
    }

    private function resolveRegion($value): ?string
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        if (! is_array($decoded)) {
            return null;
        }

        $regionLabel = $decoded['region_label'] ?? null;
        $cityName = $decoded['city_name'] ?? null;
        $region = trim(($regionLabel ?? '') . ($cityName ? ', ' . $cityName : ''));

        return $region !== '' ? $region : null;
    }

    private function resolveCategory($value): ?string
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        if (! is_array($decoded)) {
            return null;
        }

        return $decoded['category'] ?? null;
    }

    private function resolveMemberKey(string $activityType): string
    {
        return match ($activityType) {
            'testimonials' => 'from_user_id',
            'referrals' => 'from_user_id',
            'business_deals' => 'from_user_id',
            'p2p_meetings' => 'initiator_user_id',
            'requirements' => 'user_id',
            'become_a_leader' => 'user_id',
            'recommend_peer' => 'user_id',
            'register_visitor' => 'user_id',
            default => 'user_id',
        };
    }

    private function activityTable(string $activityType): string
    {
        $modelClass = match ($activityType) {
            'testimonials' => Testimonial::class,
            'referrals' => Referral::class,
            'business_deals' => BusinessDeal::class,
            'p2p_meetings' => P2pMeeting::class,
            'requirements' => Requirement::class,
            'become_a_leader' => LeaderInterestSubmission::class,
            'recommend_peer' => PeerRecommendation::class,
            'register_visitor' => VisitorRegistration::class,
            default => Requirement::class,
        };

        return (new $modelClass())->getTable();
    }

    private function relatedUserJoinColumn(string $activityType): string
    {
        return match ($activityType) {
            'testimonials' => 'activity.to_user_id',
            'referrals' => 'activity.to_user_id',
            'business_deals' => 'activity.to_user_id',
            'p2p_meetings' => 'activity.peer_user_id',
            default => 'activity.to_user_id',
        };
    }

    private function exportSelectColumns(string $activityType): array
    {
        return match ($activityType) {
            'requirements' => [
                'activity.id',
                'activity.subject',
                'activity.description',
                'activity.region_filter',
                'activity.category_filter',
                'activity.status',
                'activity.media',
                'activity.created_at',
                DB::raw('member_user.id as member_id'),
            ],
            'testimonials' => [
                'activity.content',
                'activity.media',
                'activity.created_at',
                DB::raw('member_user.id as member_id'),
                DB::raw('related_user.id as related_member_id'),
            ],
            'referrals' => [
                'activity.id',
                'activity.referral_date',
                'activity.referral_of',
                'activity.referral_type',
                'activity.phone',
                'activity.email',
                'activity.address',
                'activity.hot_value',
                'activity.remarks',
                'activity.created_at',
                DB::raw('related_user.id as related_member_id'),
            ],
            'business_deals' => [
                'activity.id',
                'activity.deal_date',
                'activity.deal_amount',
                'activity.business_type',
                'activity.comment',
                'activity.created_at',
                DB::raw('related_user.id as related_member_id'),
            ],
            'p2p_meetings' => [
                'activity.id',
                'activity.meeting_date',
                'activity.meeting_place',
                'activity.remarks',
                'activity.created_at',
                DB::raw('related_user.id as related_member_id'),
            ],
            'become_a_leader' => [
                'activity.applying_for',
                'activity.referred_name',
                'activity.referred_mobile',
                'activity.leadership_roles',
                'activity.contribute_city',
                'activity.primary_domain',
                'activity.why_interested',
                'activity.created_at',
                DB::raw('member_user.id as member_id'),
            ],
            'recommend_peer' => [
                'activity.peer_name',
                'activity.peer_mobile',
                'activity.how_well_known',
                'activity.is_aware',
                'activity.created_at',
                DB::raw('member_user.id as member_id'),
            ],
            'register_visitor' => [
                'activity.event_type',
                'activity.event_name',
                'activity.event_date',
                'activity.visitor_full_name',
                'activity.visitor_mobile',
                'activity.visitor_city',
                'activity.visitor_business',
                'activity.status',
                'activity.coins_awarded',
                'activity.created_at',
                DB::raw('member_user.id as member_id'),
            ],
            default => [],
        };
    }

    private function buildMemberMap(iterable $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            $rowArray = (array) $row;
            if (! empty($rowArray['member_id'])) {
                $ids[] = $rowArray['member_id'];
            }
            if (! empty($rowArray['related_member_id'])) {
                $ids[] = $rowArray['related_member_id'];
            }
        }

        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }

        return DB::table('users')
            ->whereIn('id', $ids)
            ->get(['id', 'display_name', 'first_name', 'last_name', 'email'])
            ->mapWithKeys(function ($user) {
                $name = $user->display_name;
                if (! $name) {
                    $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
                }

                $name = $name ?: $user->email;

                return [
                    $user->id => [
                        'name' => $name,
                        'email' => $user->email,
                    ],
                ];
            })
            ->all();
    }

    private function resolveMemberName(?string $memberId, array $memberMap): ?string
    {
        if (! $memberId) {
            return null;
        }

        return $memberMap[$memberId]['name'] ?? null;
    }

    private function resolveMemberEmail(?string $memberId, array $memberMap): ?string
    {
        if (! $memberId) {
            return null;
        }

        return $memberMap[$memberId]['email'] ?? null;
    }

    private function resolveAttachmentUrl(array $row): ?string
    {
        foreach (['file_id', 'image_id', 'attachment_file_id', 'media_file_id', 'photo_file_id'] as $column) {
            if (array_key_exists($column, $row)) {
                return $this->formatAttachmentUrl($row[$column] ?? null);
            }
        }

        foreach (['attachments', 'media'] as $column) {
            if (! array_key_exists($column, $row)) {
                continue;
            }

            $media = $row[$column] ?? null;
            if (! $media) {
                continue;
            }

            $decoded = is_string($media) ? json_decode($media, true) : $media;
            if (is_array($decoded)) {
                $first = $decoded[0] ?? null;
                if (is_array($first)) {
                    return $this->formatAttachmentUrl($first['url'] ?? $first['id'] ?? null);
                }
            }

            return $this->formatAttachmentUrl($decoded);
        }

        return null;
    }

    private function formatAttachmentUrl($value): ?string
    {
        if (! $value) {
            return null;
        }

        if (is_string($value) && (str_starts_with($value, 'http://') || str_starts_with($value, 'https://'))) {
            return $value;
        }

        if (is_string($value) && Str::isUuid($value)) {
            return url('/api/v1/files/' . $value);
        }

        return null;
    }
}
