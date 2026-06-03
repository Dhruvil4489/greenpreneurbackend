<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Circle;
use App\Models\User;
use App\Support\AdminAccess;
use App\Support\AdminCircleScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $today = now();

        $totalUsers = $this->safeCountTable('users');
        $newSignups = ($this->hasTableColumn('users', 'created_at'))
            ? DB::table('users')->whereDate('created_at', $today->toDateString())->count()
            : 0;
        $premiumUpgrades = ($this->hasTableColumn('users', 'membership_status'))
            ? DB::table('users')->where('membership_status', 'premium')->count()
            : 0;

        $activeCircles = ($this->hasTableColumn('circles', 'status'))
            ? DB::table('circles')->where('status', 'active')->count()
            : $this->safeCountTable('circles');
        $pendingApprovals = ($this->hasTableColumn('circles', 'status'))
            ? DB::table('circles')->where('status', 'pending')->count()
            : 0;

        $activitiesToday = ($this->hasTableColumn('activities', 'created_at'))
            ? DB::table('activities')->whereDate('created_at', $today->toDateString())->count()
            : 0;

        $supportRequests = $this->safeCountTable('support_requests');
        $reportedPosts = $this->safeReportedPostsCount();

        $coinsIssued = $this->safeCountTable('coin_ledgers');
        $walletCollections = $this->safeCountTable('wallet_transactions');

        $stats = [
            'newSignups' => (int) $newSignups,
            'premiumUpgrades' => (int) $premiumUpgrades,
            'activeCircles' => (int) $activeCircles,
            'pendingApprovals' => (int) $pendingApprovals,
            'coinsIssued' => (int) $coinsIssued,
            'walletCollections' => (int) $walletCollections,
            'supportRequests' => (int) $supportRequests,
            'activitiesToday' => (int) $activitiesToday,
            'reportedPosts' => (int) $reportedPosts,
            // Legacy keys for existing blade usage
            'total_users' => (int) $totalUsers,
            'active_circles' => (int) $activeCircles,
            'pending_approvals' => (int) $pendingApprovals,
            'new_signups' => (int) $newSignups,
        ];

        $pendingItems = [
            ['title' => 'Pending Activities Today', 'count' => (int) $activitiesToday],
            ['title' => 'Circles Awaiting Review', 'count' => (int) $pendingApprovals],
            ['title' => 'Reported Posts', 'count' => (int) $reportedPosts],
            ['title' => 'Support Requests', 'count' => (int) $supportRequests],
        ];

        return view('admin.dashboard', [
            'stats' => $stats,
            'pendingItems' => $pendingItems,
        ]);
    }


    public function ded(): View
    {
        $admin = Auth::guard('admin')->user();
        abort_unless(AdminAccess::isDed($admin), 403);

        $dedLocation = AdminAccess::assignedDedLocation($admin);
        $districtId = $dedLocation['district_id'] ?? null;
        $districtName = $dedLocation['district_name'] ?? null;
        $stateName = $dedLocation['state_name'] ?? null;

        if (! $districtId || ! $districtName) {
            return view('admin.ded-dashboard', [
                'districtName' => null,
                'stats' => [],
                'pendingItems' => [],
                'recentPeers' => collect(),
            ]);
        }

        $today = now();
        $usersQuery = User::query();
        AdminCircleScope::applyToUsersQuery($usersQuery, $admin);

        $newSignupsQuery = User::query();
        AdminCircleScope::applyToUsersQuery($newSignupsQuery, $admin);

        $activeCirclesQuery = Circle::query();
        if (Schema::hasColumn('circles', 'district_id')) {
            $activeCirclesQuery->where('district_id', $districtId);
        } elseif (Schema::hasColumn('circles', 'city_id') && Schema::hasTable('cities')) {
            $activeCirclesQuery->whereExists(function ($subQuery) use ($districtId, $districtName, $stateName) {
                $subQuery->selectRaw(1)
                    ->from('cities as ded_scope_cities')
                    ->whereColumn('ded_scope_cities.id', 'circles.city_id');

                if (Schema::hasColumn('cities', 'district_id')) {
                    $subQuery->where('ded_scope_cities.district_id', $districtId);
                } elseif (Schema::hasColumn('cities', 'district')) {
                    $subQuery->whereRaw('LOWER(ded_scope_cities.district) = ?', [mb_strtolower($districtName)]);

                    if ($stateName && Schema::hasColumn('cities', 'state')) {
                        $subQuery->whereRaw('LOWER(ded_scope_cities.state) = ?', [mb_strtolower($stateName)]);
                    }
                } else {
                    $subQuery->whereRaw('1=0');
                }
            });
        } else {
            $activeCirclesQuery->whereRaw('1=0');
        }

        if (Schema::hasColumn('circles', 'status')) {
            $activeCirclesQuery->where('status', 'active');
        }

        $activitiesToday = 0;
        if ($this->hasTableColumn('activities', 'created_at')) {
            $activityQuery = DB::table('activities')->whereDate('activities.created_at', $today->toDateString());
            AdminCircleScope::applyToActivityQuery($activityQuery, $admin, 'activities.user_id', null);
            $activitiesToday = $activityQuery->count();
        }

        $recentPeersQuery = User::query()->with('city')->latest('created_at')->limit(8);
        AdminCircleScope::applyToUsersQuery($recentPeersQuery, $admin);

        $stats = [
            'total_users' => (int) $usersQuery->count(),
            'active_circles' => (int) $activeCirclesQuery->count(),
            'new_signups' => (int) $newSignupsQuery->whereDate('users.created_at', $today->toDateString())->count(),
            'activities_today' => (int) $activitiesToday,
        ];

        $pendingItems = [
            ['title' => 'Pending Activities Today', 'count' => (int) $activitiesToday],
            ['title' => 'District Peers', 'count' => $stats['total_users']],
            ['title' => 'Active District Circles', 'count' => $stats['active_circles']],
        ];

        return view('admin.ded-dashboard', [
            'districtName' => $districtName,
            'stats' => $stats,
            'pendingItems' => $pendingItems,
            'recentPeers' => $recentPeersQuery->get(),
        ]);
    }

    private function safeCountTable(string $table): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        return (int) DB::table($table)->count();
    }

    private function hasTableColumn(string $table, string $column): bool
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, $column);
    }

    private function safeReportedPostsCount(): int
    {
        if (Schema::hasTable('post_reports')) {
            return (int) DB::table('post_reports')->distinct()->count('post_id');
        }

        if (Schema::hasTable('reported_posts')) {
            return (int) DB::table('reported_posts')->count();
        }

        if ($this->hasTableColumn('posts', 'is_reported')) {
            return (int) DB::table('posts')->where('is_reported', true)->count();
        }

        if ($this->hasTableColumn('posts', 'reported_at')) {
            return (int) DB::table('posts')->whereNotNull('reported_at')->count();
        }

        return 0;
    }
}
