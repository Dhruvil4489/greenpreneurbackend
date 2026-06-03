<?php

namespace App\Http\Controllers\Admin;

use Carbon\Carbon;
use App\Http\Controllers\Controller;
use App\Models\PeerRecommendation;
use App\Models\User;
use App\Services\Admin\IndustryScopeService;
use App\Support\AdminCircleScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class ActivitiesPeerRecommendationController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', $request->query('search', '')));
        $from = $request->query('from');
        $to = $request->query('to');
        $fromAt = $this->parseDayBoundary($from, false);
        $toAt = $this->parseDayBoundary($to, true);
        $peerNameFilter = trim((string) $request->query('peer_name', ''));
        $peerPhone = trim((string) $request->query('peer_phone', ''));
        $recommendedName = trim((string) $request->query('recommended_peer_name', ''));
        $recommendedMobile = trim((string) $request->query('recommended_peer_mobile', ''));
        $howWellKnown = trim((string) $request->query('how_well_known', ''));
        $isAwareFilter = trim((string) $request->query('is_aware', ''));
        $coinsAwarded = trim((string) $request->query('coins_awarded', ''));

        $query = PeerRecommendation::query()
            ->leftJoin('users as peer', 'peer.id', '=', 'peer_recommendations.user_id')
            ->select([
                'peer_recommendations.*',
                DB::raw("coalesce(nullif(trim(concat_ws(' ', peer.first_name, peer.last_name)), ''), peer.display_name, '—') as from_user_name"),
                DB::raw("coalesce(peer.company_name, '') as from_company"),
                DB::raw("coalesce(peer.city, '') as from_city"),
                'peer.phone as from_phone',
            ]);

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


        if ($peerNameFilter !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $peerNameFilter) . '%';
            $query->where(function ($q) use ($like) {
                $q->where('peer.display_name', 'ILIKE', $like)
                    ->orWhere('peer.first_name', 'ILIKE', $like)
                    ->orWhere('peer.last_name', 'ILIKE', $like);
            });
        }

        if ($peerPhone !== '') {
            $query->where('peer.phone', 'ILIKE', '%' . str_replace(['%', '_'], ['\%', '\_'], $peerPhone) . '%');
        }

        if ($recommendedName !== '') {
            $query->where('peer_recommendations.peer_name', 'ILIKE', '%' . str_replace(['%', '_'], ['\%', '\_'], $recommendedName) . '%');
        }

        if ($recommendedMobile !== '') {
            $query->where('peer_recommendations.peer_mobile', 'ILIKE', '%' . str_replace(['%', '_'], ['\%', '\_'], $recommendedMobile) . '%');
        }

        if ($howWellKnown !== '') {
            $query->where('peer_recommendations.how_well_known', 'ILIKE', '%' . str_replace(['%', '_'], ['\%', '\_'], $howWellKnown) . '%');
        }

        if ($isAwareFilter === 'yes') {
            $query->where('peer_recommendations.is_aware', true);
        }

        if ($isAwareFilter === 'no') {
            $query->where(function ($inner) {
                $inner->where('peer_recommendations.is_aware', false)
                    ->orWhereNull('peer_recommendations.is_aware');
            });
        }

        if ($coinsAwarded !== '' && is_numeric($coinsAwarded)) {
            $query->where('peer_recommendations.coins_awarded', (int) $coinsAwarded);
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

        AdminCircleScope::applyToActivityQuery($query, Auth::guard('admin')->user(), 'peer_recommendations.user_id', null);
        app(IndustryScopeService::class)->applyToActivityQuery($query, Auth::guard('admin')->user(), array_filter([
            'peer_recommendations.user_id',
            Schema::hasColumn('peer_recommendations', 'recommender_id') ? 'peer_recommendations.recommender_id' : null,
            Schema::hasColumn('peer_recommendations', 'recommended_user_id') ? 'peer_recommendations.recommended_user_id' : null,
        ]));

        $items = $query
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.activities.recommend_peer.index', [
            'items' => $items,
            'filters' => [
                'q' => $search,
                'from' => $from,
                'to' => $to,
                'circle_id' => $request->query('circle_id'),
                'peer_name' => $peerNameFilter,
                'peer_phone' => $peerPhone,
                'recommended_peer_name' => $recommendedName,
                'recommended_peer_mobile' => $recommendedMobile,
                'how_well_known' => $howWellKnown,
                'is_aware' => $isAwareFilter,
                'coins_awarded' => $coinsAwarded,
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

        $items = PeerRecommendation::query()
            ->where('user_id', $peer->id)
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.activities.recommend_peer.show', [
            'peer' => $peer,
            'items' => $items,
        ]);
    }
}
