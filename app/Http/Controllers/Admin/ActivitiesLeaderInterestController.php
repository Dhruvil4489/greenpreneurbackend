<?php

namespace App\Http\Controllers\Admin;

use Carbon\Carbon;
use App\Http\Controllers\Controller;
use App\Models\LeaderInterestSubmission;
use App\Models\User;
use App\Services\Admin\IndustryScopeService;
use App\Support\AdminCircleScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class ActivitiesLeaderInterestController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', $request->query('search', '')));
        $from = $request->query('from');
        $to = $request->query('to');
        $fromAt = $this->parseDayBoundary($from, false);
        $toAt = $this->parseDayBoundary($to, true);
        $applyingFor = $request->query('applying_for', 'all');
        $peerName = trim((string) $request->query('peer_name', ''));
        $peerPhone = trim((string) $request->query('peer_phone', ''));
        $applyingForText = trim((string) $request->query('applying_for', ''));
        $referredName = trim((string) $request->query('referred_name', ''));
        $referredMobile = trim((string) $request->query('referred_mobile', ''));
        $leadershipRoles = trim((string) $request->query('leadership_roles', ''));
        $cityRegion = trim((string) $request->query('city_region', ''));
        $primaryDomain = trim((string) $request->query('primary_domain', ''));

        $query = LeaderInterestSubmission::query()
            ->leftJoin('users as peer', 'peer.id', '=', 'leader_interest_submissions.user_id')
            ->select([
                'leader_interest_submissions.*',
                DB::raw("coalesce(nullif(trim(concat_ws(' ', peer.first_name, peer.last_name)), ''), peer.display_name, '—') as peer_name"),
                DB::raw("coalesce(peer.company_name, '') as peer_company"),
                DB::raw("coalesce(peer.city, '') as peer_city"),
                'peer.phone as peer_phone',
            ]);

        if ($applyingFor && $applyingFor !== 'all') {
            $query->where('applying_for', $applyingFor);
        }

        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
            $query->where(function ($q) use ($like) {
                $q->where('peer.display_name', 'ILIKE', $like)
                    ->orWhere('peer.first_name', 'ILIKE', $like)
                    ->orWhere('peer.last_name', 'ILIKE', $like)
                    ->orWhere('peer.company_name', 'ILIKE', $like)
                    ->orWhere('peer.city', 'ILIKE', $like);
            });
        }


        if ($peerName !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $peerName) . '%';
            $query->where(function ($q) use ($like) {
                $q->where('peer.display_name', 'ILIKE', $like)
                    ->orWhere('peer.first_name', 'ILIKE', $like)
                    ->orWhere('peer.last_name', 'ILIKE', $like)
                    ->orWhere('peer.company_name', 'ILIKE', $like)
                    ->orWhere('peer.city', 'ILIKE', $like);
            });
        }

        if ($peerPhone !== '') {
            $query->where('peer.phone', 'ILIKE', '%' . str_replace(['%', '_'], ['\%', '\_'], $peerPhone) . '%');
        }

        if ($applyingForText !== '') {
            $query->where('applying_for', 'ILIKE', '%' . str_replace(['%', '_'], ['\%', '\_'], $applyingForText) . '%');
        }

        if ($referredName !== '') {
            $query->where('referred_name', 'ILIKE', '%' . str_replace(['%', '_'], ['\%', '\_'], $referredName) . '%');
        }

        if ($referredMobile !== '') {
            $query->where('referred_mobile', 'ILIKE', '%' . str_replace(['%', '_'], ['\%', '\_'], $referredMobile) . '%');
        }

        if ($leadershipRoles !== '') {
            $query->whereRaw("COALESCE(leadership_roles::text, '') ILIKE ?", ['%' . str_replace(['%', '_'], ['\%', '\_'], $leadershipRoles) . '%']);
        }

        if ($cityRegion !== '') {
            $query->where('contribute_city', 'ILIKE', '%' . str_replace(['%', '_'], ['\%', '\_'], $cityRegion) . '%');
        }

        if ($primaryDomain !== '') {
            $query->where('primary_domain', 'ILIKE', '%' . str_replace(['%', '_'], ['\%', '\_'], $primaryDomain) . '%');
        }

        if ($fromAt) {
            $query->where('created_at', '>=', $fromAt);
        }

        if ($toAt) {
            $query->where('created_at', '<=', $toAt);
        }

        if ($request->filled('circle_id')) {
            $query->whereExists(function ($sub) use ($request) {
                $sub->selectRaw('1')
                    ->from('circle_members as cm_filter')
                    ->whereColumn('cm_filter.user_id', 'peer.id')
                    ->where('cm_filter.circle_id', $request->query('circle_id'));
            });
        }

        AdminCircleScope::applyToActivityQuery($query, Auth::guard('admin')->user(), 'leader_interest_submissions.user_id', null);
        app(IndustryScopeService::class)->applyToActivityQuery($query, Auth::guard('admin')->user(), array_filter([
            'leader_interest_submissions.user_id',
            Schema::hasColumn('leader_interest_submissions', 'member_id') ? 'leader_interest_submissions.member_id' : null,
        ]));

        $items = $query
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.activities.become_a_leader.index', [
            'items' => $items,
            'filters' => [
                'q' => $search,
                'from' => $from,
                'to' => $to,
                'circle_id' => $request->query('circle_id'),
                'peer_name' => $peerName,
                'peer_phone' => $peerPhone,
                'applying_for' => $applyingForText,
                'referred_name' => $referredName,
                'referred_mobile' => $referredMobile,
                'leadership_roles' => $leadershipRoles,
                'city_region' => $cityRegion,
                'primary_domain' => $primaryDomain,
            ],
            'circles' => $this->circleOptions(),
        ]);
    }

    private function circleOptions()
    {
        return DB::table('circles')->select(['id','name'])->orderBy('name')->get();
    }

    private function parseDayBoundary($value, bool $endOfDay): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $parsed = Carbon::parse($value);

            return $endOfDay ? $parsed->endOfDay() : $parsed->startOfDay();
        } catch (\Throwable $exception) {
            return null;
        }
    }

    public function show(User $peer, Request $request): View
    {
        $admin = Auth::guard('admin')->user();

        if (! AdminCircleScope::userInScope($admin, $peer->id) || ! app(IndustryScopeService::class)->userInScope($admin, (string) $peer->id)) {
            abort(403);
        }

        $items = LeaderInterestSubmission::query()
            ->where('user_id', $peer->id)
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.activities.become_a_leader.show', [
            'peer' => $peer,
            'items' => $items,
        ]);
    }
}
