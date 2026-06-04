<?php

namespace App\Http\Controllers\Admin;

use Carbon\Carbon;
use App\Http\Controllers\Controller;
use App\Models\VisitorRegistration;
use App\Models\User;
use App\Services\Admin\IndustryScopeService;
use App\Support\AdminCircleScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class ActivitiesVisitorRegistrationController extends Controller
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
        $eventType = trim((string) $request->query('event_type', ''));
        $eventName = trim((string) $request->query('event_name', ''));
        $eventDate = trim((string) $request->query('event_date', ''));
        $visitorName = trim((string) $request->query('visitor_name', ''));
        $visitorMobile = trim((string) $request->query('visitor_mobile', ''));
        $visitorCity = trim((string) $request->query('visitor_city', ''));
        $visitorBusiness = trim((string) $request->query('visitor_business', ''));
        $statusFilter = trim((string) $request->query('status', ''));
        $coinsAwarded = trim((string) $request->query('coins_awarded', ''));

        $query = VisitorRegistration::query()
            ->leftJoin('users as peer', 'peer.id', '=', 'visitor_registrations.user_id')
            ->select([
                'visitor_registrations.*',
                DB::raw("coalesce(nullif(trim(concat_ws(' ', peer.first_name, peer.last_name)), ''), peer.display_name, '—') as peer_name"),
                DB::raw("coalesce(peer.company_name, '') as peer_company"),
                DB::raw("coalesce(peer.city, '') as peer_city"),
                'peer.phone as peer_phone',
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

        if ($eventType !== '') {
            $query->where('event_type', 'ILIKE', '%' . str_replace(['%', '_'], ['\%', '\_'], $eventType) . '%');
        }

        if ($eventName !== '') {
            $query->where('event_name', 'ILIKE', '%' . str_replace(['%', '_'], ['\%', '\_'], $eventName) . '%');
        }

        if ($eventDate !== '') {
            try {
                $query->whereDate('event_date', '=', Carbon::createFromFormat('d-m-Y', $eventDate)->toDateString());
            } catch (\Throwable $exception) {
                try {
                    $query->whereDate('event_date', '=', Carbon::parse($eventDate)->toDateString());
                } catch (\Throwable $exception) {
                    // ignore invalid date
                }
            }
        }

        if ($visitorName !== '') {
            $query->where('visitor_full_name', 'ILIKE', '%' . str_replace(['%', '_'], ['\%', '\_'], $visitorName) . '%');
        }

        if ($visitorMobile !== '') {
            $query->where('visitor_mobile', 'ILIKE', '%' . str_replace(['%', '_'], ['\%', '\_'], $visitorMobile) . '%');
        }

        if ($visitorCity !== '') {
            $query->where('visitor_city', 'ILIKE', '%' . str_replace(['%', '_'], ['\%', '\_'], $visitorCity) . '%');
        }

        if ($visitorBusiness !== '') {
            $query->where('visitor_business', 'ILIKE', '%' . str_replace(['%', '_'], ['\%', '\_'], $visitorBusiness) . '%');
        }

        if ($statusFilter !== '') {
            $query->where('status', 'ILIKE', '%' . str_replace(['%', '_'], ['\%', '\_'], $statusFilter) . '%');
        }

        if ($coinsAwarded !== '' && is_numeric($coinsAwarded)) {
            $query->where('coins_awarded', (int) $coinsAwarded);
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

        AdminCircleScope::applyToActivityQuery($query, Auth::guard('admin')->user(), 'visitor_registrations.user_id', null);
        app(IndustryScopeService::class)->applyToActivityQuery($query, Auth::guard('admin')->user(), array_filter([
            'visitor_registrations.user_id',
            Schema::hasColumn('visitor_registrations', 'visitor_id') ? 'visitor_registrations.visitor_id' : null,
            Schema::hasColumn('visitor_registrations', 'created_by') ? 'visitor_registrations.created_by' : null,
            Schema::hasColumn('visitor_registrations', 'invited_by_user_id') ? 'visitor_registrations.invited_by_user_id' : null,
        ]));

        $items = $query
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.activities.register_visitor.index', [
            'items' => $items,
            'filters' => [
                'q' => $search,
                'from' => $from,
                'to' => $to,
                'circle_id' => $request->query('circle_id'),
                'peer_name' => $peerNameFilter,
                'peer_phone' => $peerPhone,
                'event_type' => $eventType,
                'event_name' => $eventName,
                'event_date' => $eventDate,
                'visitor_name' => $visitorName,
                'visitor_mobile' => $visitorMobile,
                'visitor_city' => $visitorCity,
                'visitor_business' => $visitorBusiness,
                'status' => $statusFilter,
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

        $items = VisitorRegistration::query()
            ->where('user_id', $peer->id)
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.activities.register_visitor.show', [
            'peer' => $peer,
            'items' => $items,
        ]);
    }
}
