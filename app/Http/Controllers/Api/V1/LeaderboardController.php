<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;

class LeaderboardController extends Controller
{
    public function coins(): JsonResponse
    {
        $members = $this->baseLeaderboardQuery()
            ->orderByRaw('COALESCE(coins_balance, 0) DESC')
            ->orderBy('display_name', 'asc')
            ->limit(20)
            ->get();

        return response()->json([
            'success' => true,
            'message' => null,
            'data' => [
                'leaderboard_type' => 'coins',
                'total' => $members->count(),
                'members' => $this->transformMembers($members),
            ],
        ]);
    }

    public function impacts(): JsonResponse
    {
        $members = $this->baseLeaderboardQuery()
            ->orderByRaw('COALESCE(life_impacted_count, 0) DESC')
            ->orderBy('display_name', 'asc')
            ->limit(20)
            ->get();

        return response()->json([
            'success' => true,
            'message' => null,
            'data' => [
                'leaderboard_type' => 'impacts',
                'total' => $members->count(),
                'members' => $this->transformMembers($members),
            ],
        ]);
    }

    private function baseLeaderboardQuery(): Builder
    {
        $select = [
            'id',
            'display_name',
            'first_name',
            'last_name',
            'company_name',
            'business_type',
            'profile_photo_file_id',
            'coins_balance',
            'life_impacted_count',
            'coin_medal_rank',
            'coin_milestone_title',
            'coin_milestone_meaning',
            'contribution_award_name',
            'contribution_award_recognition',
        ];

        if (Schema::hasColumn('users', 'designation')) {
            $select[] = 'designation';
        }

        if (Schema::hasColumn('users', 'city_id')) {
            $select[] = 'city_id';
        }

        if (Schema::hasColumn('users', 'city')) {
            $select[] = 'city';
        }

        $query = User::query()
            ->select($select)
            ->whereNull('deleted_at');

        if (Schema::hasTable('cities') && Schema::hasColumn('users', 'city_id')) {
            $query->with('city:id,name');
        }

        if (Schema::hasColumn('users', 'status')) {
            $query->where('status', 'active');
        }

        return $query;
    }

    private function transformMembers($members)
    {
        return $members->values()->map(function (User $member, int $index): array {
            $profilePhotoFileId = $member->profile_photo_file_id;

            return [
                'rank' => $index + 1,
                'id' => $member->id,
                'display_name' => $member->display_name,
                'first_name' => $member->first_name,
                'last_name' => $member->last_name,
                'company_name' => $member->company_name,
                'designation' => $member->designation,
                'city' => $this->resolveCityName($member),
                'category' => $member->business_type,
                'profile_photo' => [
                    'file_id' => $profilePhotoFileId,
                    'url' => $profilePhotoFileId ? rtrim((string) config('app.url'), '/') . '/api/v1/files/' . $profilePhotoFileId : null,
                ],
                'coins_balance' => (int) ($member->coins_balance ?? 0),
                'life_impacted_count' => (int) ($member->life_impacted_count ?? 0),
                'coin_medal_rank' => $member->coin_medal_rank,
                'coin_milestone_title' => $member->coin_milestone_title,
                'coin_milestone_meaning' => $member->coin_milestone_meaning,
                'contribution_award_name' => $member->contribution_award_name,
                'contribution_award_recognition' => $member->contribution_award_recognition,
            ];
        });
    }

    private function resolveCityName(User $member): ?string
    {
        if ($member->relationLoaded('city')) {
            $city = $member->getRelation('city');

            if ($city && filled($city->name)) {
                return $city->name;
            }
        }

        $attributes = $member->getAttributes();

        if (! array_key_exists('city', $attributes)) {
            return null;
        }

        $city = $attributes['city'];

        if (! is_string($city)) {
            return $city ?: null;
        }

        $city = trim($city);

        return $city !== '' ? $city : null;
    }
}
