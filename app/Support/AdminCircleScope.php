<?php

namespace App\Support;

use App\Models\AdminUser;
use App\Models\CircleMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Cache;

class AdminCircleScope
{
    private const ROLE_PRIORITY = [
        'circle_leader' => 0,
        'chair' => 1,
        'vice_chair' => 2,
        'secretary' => 3,
        'founder' => 4,
        'director' => 5,
        'committee_leader' => 6,
        'member' => 7,
    ];

    public static function resolveCircleId(?AdminUser $admin): ?string
    {
        if (! $admin || ! AdminAccess::isCircleScoped($admin)) {
            return null;
        }

        $user = AdminAccess::resolveAppUser($admin);
        if (! $user) {
            return null;
        }

        $roles = array_keys(self::ROLE_PRIORITY);
        $orderCases = collect(self::ROLE_PRIORITY)
            ->map(fn ($priority, $role) => "when '{$role}' then {$priority}")
            ->implode(' ');

        $query = CircleMember::query()
            ->select('circle_members.circle_id')
            ->where('circle_members.user_id', $user->id)
            ->where('circle_members.status', 'approved')
            ->whereNull('circle_members.deleted_at')
            ->whereIn(DB::raw('circle_members.role::text'), $roles);

        if (Schema::hasColumn('circles', 'status')) {
            $query->leftJoin('circles', 'circles.id', '=', 'circle_members.circle_id')
                ->orderByRaw("case when circles.status = 'active' then 0 else 1 end");
        }

        $query->orderByRaw("case circle_members.role::text {$orderCases} else 999 end")
            ->orderBy('circle_members.created_at');

        return $query->value('circle_members.circle_id');
    }

    public static function circleUserIdsSubquery(string $circleId): Builder
    {
        return CircleMember::query()
            ->select('user_id')
            ->where('circle_id', $circleId)
            ->where('status', 'approved')
            ->whereNull('deleted_at');
    }

    public static function applyToActivityQuery($query, ?AdminUser $admin, string $primaryColumn, ?string $peerColumn): void
    {
        if (AdminAccess::isDed($admin)) {
            $query->where(function ($districtQuery) use ($admin, $primaryColumn, $peerColumn) {
                self::applyDedDistrictScope($districtQuery, $admin, $primaryColumn);

                if ($peerColumn) {
                    $districtQuery->orWhere(function ($peerDistrictQuery) use ($admin, $peerColumn) {
                        self::applyDedDistrictScope($peerDistrictQuery, $admin, $peerColumn);
                    });
                }
            });
            return;
        }

        if (! AdminAccess::isCircleScoped($admin)) {
            return;
        }

        $circleId = self::resolveCircleId($admin);

        if (! $circleId) {
            $query->whereRaw('1=0');
            return;
        }

        $circleUserIds = self::circleUserIdsSubquery($circleId);

        $query->whereIn($primaryColumn, $circleUserIds);
    }

    public static function applyToUsersQuery($query, ?AdminUser $admin): void
    {
        if (AdminAccess::isDed($admin)) {
            self::applyDedDistrictScope($query, $admin);
            return;
        }

        if (! AdminAccess::isCircleScoped($admin)) {
            return;
        }

        $circleId = self::resolveCircleId($admin);

        if (! $circleId) {
            $query->whereRaw('1=0');
            return;
        }

        $query->whereExists(function ($subQuery) use ($circleId) {
            $subQuery->selectRaw(1)
                ->from('circle_members as cm')
                ->whereColumn('cm.user_id', 'users.id')
                ->where('cm.status', 'approved')
                ->whereNull('cm.deleted_at')
                ->where('cm.circle_id', $circleId);
        });
    }

    private static ?array $cachedCircleIds = null;

    public static function resetCache(): void
    {
        self::$cachedCircleIds = null;
    }

    public static function getDedCircleIds(?AdminUser $admin): array
    {
        if (! $admin || ! AdminAccess::isDed($admin)) {
            return [];
        }

        if (self::$cachedCircleIds !== null) {
            return self::$cachedCircleIds;
        }

        $location = AdminAccess::assignedDedLocation($admin);
        $districtName = $location['district_name'] ?? null;
        $stateName = $location['state_name'] ?? null;
        $districtId = $location['district_id'] ?? null;

        if (! $districtName) {
            return [];
        }

        $cacheKey = 'ded-circle-ids:' . $admin->id;

        self::$cachedCircleIds = Cache::remember($cacheKey, 300, function () use ($districtName, $stateName, $districtId) {
            $query = DB::table('circles as c');
            if (Schema::hasColumn('circles', 'deleted_at')) {
                $query->whereNull('c.deleted_at');
            }

            $query->where(function ($q) use ($districtName, $stateName, $districtId) {
                $hasCond = false;

                if (Schema::hasColumn('circles', 'district_id') && $districtId) {
                    $q->where('c.district_id', $districtId);
                    $hasCond = true;
                }

                if (Schema::hasColumn('circles', 'city')) {
                    $method = $hasCond ? 'orWhere' : 'where';
                    $q->{$method}(function ($sub) use ($districtName) {
                        $sub->whereRaw("LOWER(NULLIF(TRIM(c.city), '')) = ?", [mb_strtolower($districtName)]);
                    });
                    $hasCond = true;
                }

                if (Schema::hasColumn('circles', 'city_id') && Schema::hasTable('cities')) {
                    $method = $hasCond ? 'orWhereExists' : 'whereExists';
                    $q->{$method}(function ($citySubQuery) use ($districtName, $stateName): void {
                        $citySubQuery->selectRaw(1)
                            ->from('cities as ded_scope_circle_cities')
                            ->whereColumn('ded_scope_circle_cities.id', "c.city_id");

                        self::applyCityDistrictPredicate($citySubQuery, 'ded_scope_circle_cities', $districtName, $stateName);
                    });
                }
            });

            $rawCircles = $query->get(['c.id', 'c.name']);

            // Fetch other district names in lowercase
            $otherDistricts = Cache::remember('other-districts-lower:' . mb_strtolower($districtName), 3600, function () use ($districtName) {
                if (! Schema::hasTable('districts')) {
                    return [];
                }
                return DB::table('districts')
                    ->whereRaw('LOWER(name) != ?', [mb_strtolower($districtName)])
                    ->pluck('name')
                    ->map(fn($name) => mb_strtolower($name))
                    ->all();
            });

            $singleWordMap = [];
            $multiWordList = [];

            foreach ($otherDistricts as $d) {
                if (str_contains($d, ' ')) {
                    $multiWordList[] = $d;
                } else {
                    $singleWordMap[$d] = true;
                }
            }

            $filteredIds = [];
            foreach ($rawCircles as $circle) {
                $circleNameLower = mb_strtolower($circle->name);
                
                // 1. Single word check
                $words = preg_split('/[^a-z0-9]+/u', $circleNameLower, -1, PREG_SPLIT_NO_EMPTY);
                $exclude = false;
                foreach ($words as $word) {
                    if (isset($singleWordMap[$word])) {
                        $exclude = true;
                        break;
                    }
                }
                
                // 2. Multi word check
                if (!$exclude) {
                    foreach ($multiWordList as $multi) {
                        $pattern = '/\b' . preg_quote($multi, '/') . '\b/u';
                        if (preg_match($pattern, $circleNameLower)) {
                            $exclude = true;
                            break;
                        }
                    }
                }
                
                if (!$exclude) {
                    $filteredIds[] = $circle->id;
                }
            }

            return $filteredIds;
        });

        return self::$cachedCircleIds;
    }

    public static function applyDedDistrictScope($query, ?AdminUser $admin, ?string $userColumn = null): void
    {
        if (! AdminAccess::isDed($admin)) {
            return;
        }

        $location = AdminAccess::assignedDedLocation($admin);
        $districtName = $location['district_name'] ?? null;
        $stateName = $location['state_name'] ?? null;

        if (! $districtName) {
            $query->whereRaw('1=0');
            return;
        }

        $allowedCircleIds = self::getDedCircleIds($admin);

        $query->where(function ($scopeQuery) use ($districtName, $stateName, $userColumn, $allowedCircleIds): void {
            $userIdExpression = $userColumn ?: 'users.id';

            // 1. Direct user location check
            $scopeQuery->where(function ($directQuery) use ($districtName, $stateName, $userColumn): void {
                if ($userColumn) {
                    $directQuery->whereExists(function ($subQuery) use ($userColumn, $districtName, $stateName) {
                        $subQuery->selectRaw(1)
                            ->from('users as ded_scope_users')
                            ->leftJoin('cities as ded_scope_cities', 'ded_scope_cities.id', '=', 'ded_scope_users.city_id')
                            ->whereColumn('ded_scope_users.id', $userColumn);

                        self::applyUserLocationPredicate($subQuery, 'ded_scope_users', 'ded_scope_cities', $districtName, $stateName);
                    });
                } else {
                    $directQuery->where(function ($directUserQuery) use ($districtName, $stateName) {
                        self::applyDirectUserCityPredicate($directUserQuery, 'users', $districtName, $stateName);
                    });

                    if (Schema::hasTable('cities') && Schema::hasColumn('users', 'city_id')) {
                        $directQuery->orWhereExists(function ($subQuery) use ($districtName, $stateName) {
                            $subQuery->selectRaw(1)
                                ->from('cities as ded_scope_cities')
                                ->whereColumn('ded_scope_cities.id', 'users.city_id');

                            self::applyCityDistrictPredicate($subQuery, 'ded_scope_cities', $districtName, $stateName);
                        });
                    }
                }
            });

            // 2. User is a member of a circle in the district
            if (!empty($allowedCircleIds)) {
                $scopeQuery->orWhereExists(function ($subQuery) use ($userIdExpression, $allowedCircleIds): void {
                    $subQuery->selectRaw(1)
                        ->from('circle_members as scm')
                        ->whereColumn('scm.user_id', $userIdExpression)
                        ->whereIn('scm.circle_id', $allowedCircleIds)
                        ->whereNull('scm.deleted_at');
                });
            }

            // 2.5 User is a founder, director, or industry director of an allowed circle
            if (!empty($allowedCircleIds)) {
                $scopeQuery->orWhereExists(function ($subQuery) use ($userIdExpression, $allowedCircleIds): void {
                    $subQuery->selectRaw(1)
                        ->from('circles as sc')
                        ->whereIn('sc.id', $allowedCircleIds)
                        ->where(function ($q) use ($userIdExpression) {
                            $q->whereColumn('sc.founder_user_id', $userIdExpression)
                              ->orWhereColumn('sc.director_user_id', $userIdExpression)
                              ->orWhereColumn('sc.industry_director_user_id', $userIdExpression);
                        });
                });
            }

            // 3. Has referrals inside district circles
            if (Schema::hasTable('referrals') && !empty($allowedCircleIds)) {
                $scopeQuery->orWhereExists(function ($subQuery) use ($userIdExpression, $allowedCircleIds): void {
                    $subQuery->selectRaw(1)
                        ->from('referrals as sr')
                        ->where(function ($q) use ($userIdExpression) {
                            $q->whereColumn('sr.from_user_id', $userIdExpression)
                              ->orWhereColumn('sr.to_user_id', $userIdExpression);
                        })
                        ->where('sr.is_deleted', false)
                        ->whereNull('sr.deleted_at')
                        ->whereExists(function ($cmQuery) use ($allowedCircleIds) {
                            $cmQuery->selectRaw(1)
                                ->from('circle_members as scm')
                                ->where(function ($orQ) {
                                    $orQ->whereColumn('scm.user_id', 'sr.from_user_id')
                                        ->orWhereColumn('scm.user_id', 'sr.to_user_id');
                                })
                                ->whereIn('scm.circle_id', $allowedCircleIds)
                                ->where('scm.status', 'approved')
                                ->whereNull('scm.deleted_at');
                        });
                });
            }

            // 4. Has testimonials inside district circles
            if (Schema::hasTable('testimonials') && !empty($allowedCircleIds)) {
                $scopeQuery->orWhereExists(function ($subQuery) use ($userIdExpression, $allowedCircleIds): void {
                    $subQuery->selectRaw(1)
                        ->from('testimonials as st')
                        ->where(function ($q) use ($userIdExpression) {
                            $q->whereColumn('st.from_user_id', $userIdExpression)
                              ->orWhereColumn('st.to_user_id', $userIdExpression);
                        })
                        ->where('st.is_deleted', false)
                        ->whereNull('st.deleted_at')
                        ->whereExists(function ($cmQuery) use ($allowedCircleIds) {
                            $cmQuery->selectRaw(1)
                                ->from('circle_members as scm')
                                ->where(function ($orQ) {
                                    $orQ->whereColumn('scm.user_id', 'st.from_user_id')
                                        ->orWhereColumn('scm.user_id', 'st.to_user_id');
                                })
                                ->whereIn('scm.circle_id', $allowedCircleIds)
                                ->where('scm.status', 'approved')
                                ->whereNull('scm.deleted_at');
                        });
                });
            }

            // 5. Has requirements inside district circles
            if (Schema::hasTable('requirements') && !empty($allowedCircleIds)) {
                $scopeQuery->orWhereExists(function ($subQuery) use ($userIdExpression, $allowedCircleIds): void {
                    $subQuery->selectRaw(1)
                        ->from('requirements as srq')
                        ->where(function ($q) use ($userIdExpression) {
                            $q->whereColumn('srq.user_id', $userIdExpression);

                            if (Schema::hasTable('requirement_interests')) {
                                $q->orWhereExists(function ($interestQ) use ($userIdExpression) {
                                    $interestQ->selectRaw(1)
                                        ->from('requirement_interests as sri')
                                        ->whereColumn('sri.requirement_id', 'srq.id')
                                        ->whereColumn('sri.user_id', $userIdExpression);
                                });
                            }
                        })
                        ->whereNull('srq.deleted_at')
                        ->whereExists(function ($cmQuery) use ($allowedCircleIds) {
                            $cmQuery->selectRaw(1)
                                ->from('circle_members as scm')
                                ->where(function ($orQ) {
                                    $orQ->whereColumn('scm.user_id', 'srq.user_id');

                                    if (Schema::hasTable('requirement_interests')) {
                                        $orQ->orWhereExists(function ($interestInnerQ) {
                                            $interestInnerQ->selectRaw(1)
                                                ->from('requirement_interests as sri2')
                                                ->whereColumn('sri2.requirement_id', 'srq.id')
                                                ->whereColumn('sri2.user_id', 'scm.user_id');
                                        });
                                    }
                                })
                                ->whereIn('scm.circle_id', $allowedCircleIds)
                                ->where('scm.status', 'approved')
                                ->whereNull('scm.deleted_at');
                        });
                });
            }

            // 6. Has business deals inside district circles
            if (Schema::hasTable('business_deals') && !empty($allowedCircleIds)) {
                $scopeQuery->orWhereExists(function ($subQuery) use ($userIdExpression, $allowedCircleIds): void {
                    $subQuery->selectRaw(1)
                        ->from('business_deals as sbd')
                        ->where(function ($q) use ($userIdExpression) {
                            $q->whereColumn('sbd.from_user_id', $userIdExpression)
                              ->orWhereColumn('sbd.to_user_id', $userIdExpression);
                        })
                        ->where('sbd.is_deleted', false)
                        ->whereNull('sbd.deleted_at')
                        ->whereExists(function ($cmQuery) use ($allowedCircleIds) {
                            $cmQuery->selectRaw(1)
                                ->from('circle_members as scm')
                                ->where(function ($orQ) {
                                    $orQ->whereColumn('scm.user_id', 'sbd.from_user_id')
                                        ->orWhereColumn('scm.user_id', 'sbd.to_user_id');
                                })
                                ->whereIn('scm.circle_id', $allowedCircleIds)
                                ->where('scm.status', 'approved')
                                ->whereNull('scm.deleted_at');
                        });
                });
            }

            // 7. Has P2P meetings inside district circles
            if (Schema::hasTable('p2p_meetings') && !empty($allowedCircleIds)) {
                $scopeQuery->orWhereExists(function ($subQuery) use ($userIdExpression, $allowedCircleIds): void {
                    $subQuery->selectRaw(1)
                        ->from('p2p_meetings as spm')
                        ->where(function ($q) use ($userIdExpression) {
                            $q->whereColumn('spm.initiator_user_id', $userIdExpression)
                              ->orWhereColumn('spm.peer_user_id', $userIdExpression);
                        })
                        ->where('spm.is_deleted', false)
                        ->whereNull('spm.deleted_at')
                        ->whereExists(function ($cmQuery) use ($allowedCircleIds) {
                            $cmQuery->selectRaw(1)
                                ->from('circle_members as scm')
                                ->where(function ($orQ) {
                                    $orQ->whereColumn('scm.user_id', 'spm.initiator_user_id')
                                        ->orWhereColumn('scm.user_id', 'spm.peer_user_id');
                                })
                                ->whereIn('scm.circle_id', $allowedCircleIds)
                                ->where('scm.status', 'approved')
                                ->whereNull('scm.deleted_at');
                        });
                });
            }

            // 8. Has any activity associated with district circles
            if (Schema::hasTable('activities') && !empty($allowedCircleIds)) {
                $scopeQuery->orWhereExists(function ($subQuery) use ($userIdExpression, $allowedCircleIds): void {
                    $subQuery->selectRaw(1)
                        ->from('activities as sa')
                        ->whereIn('sa.circle_id', $allowedCircleIds)
                        ->where(function ($q) use ($userIdExpression) {
                            $q->whereColumn('sa.user_id', $userIdExpression)
                              ->orWhereColumn('sa.related_user_id', $userIdExpression);
                        });
                });
            }

            // 9. Has any join request inside district circles
            if (Schema::hasTable('circle_join_requests') && !empty($allowedCircleIds)) {
                $scopeQuery->orWhereExists(function ($subQuery) use ($userIdExpression, $allowedCircleIds): void {
                    $subQuery->selectRaw(1)
                        ->from('circle_join_requests as scjr')
                        ->whereColumn('scjr.user_id', $userIdExpression)
                        ->whereIn('scjr.circle_id', $allowedCircleIds)
                        ->whereIn('scjr.status', ['pending_cd_approval', 'pending_id_approval', 'pending_circle_fee']);
                });
            }
        });
    }

    private static function applyUserLocationPredicate($query, string $userAlias, string $cityAlias, string $districtName, ?string $stateName): void
    {
        $query->where(function ($locationQuery) use ($userAlias, $cityAlias, $districtName, $stateName) {
            self::applyDirectUserCityPredicate($locationQuery, $userAlias, $districtName, $stateName);

            if (Schema::hasTable('cities')) {
                $locationQuery->orWhere(function ($cityQuery) use ($cityAlias, $districtName, $stateName) {
                    self::applyCityDistrictPredicate($cityQuery, $cityAlias, $districtName, $stateName);
                });
            }
        });
    }

    private static function applyDirectUserCityPredicate($query, string $userAlias, string $districtName, ?string $stateName): void
    {
        if (! Schema::hasColumn('users', 'city')) {
            $query->whereRaw('1=0');
            return;
        }

        $query->whereRaw("LOWER(NULLIF(TRIM({$userAlias}.city), '')) = ?", [mb_strtolower($districtName)]);
    }

    private static function applyCityDistrictPredicate($query, string $cityAlias, string $districtName, ?string $stateName): void
    {
        $query->where(function ($cityQuery) use ($cityAlias, $districtName, $stateName) {
            $hasLocationColumn = false;

            if (Schema::hasColumn('cities', 'name')) {
                $cityQuery->whereRaw("LOWER(NULLIF(TRIM({$cityAlias}.name), '')) = ?", [mb_strtolower($districtName)]);
                $hasLocationColumn = true;
            }

            if (Schema::hasColumn('cities', 'district')) {
                $method = $hasLocationColumn ? 'orWhereRaw' : 'whereRaw';
                $cityQuery->{$method}("LOWER(NULLIF(TRIM({$cityAlias}.district), '')) = ?", [mb_strtolower($districtName)]);
                $hasLocationColumn = true;
            }

            if (! $hasLocationColumn) {
                $cityQuery->whereRaw('1=0');
            }
        });

        if ($stateName && Schema::hasColumn('cities', 'state')) {
            $query->where(function ($stateQuery) use ($cityAlias, $stateName) {
                $stateQuery->whereNull("{$cityAlias}.state")
                    ->orWhereRaw("NULLIF(TRIM({$cityAlias}.state), '') IS NULL")
                    ->orWhereRaw("LOWER(NULLIF(TRIM({$cityAlias}.state), '')) = ?", [mb_strtolower($stateName)]);
            });
        }
    }

    public static function applyToCirclesQuery($query, ?AdminUser $admin, string $circleAlias = 'circles'): void
    {
        if (! AdminAccess::isDed($admin)) {
            return;
        }

        $allowedCircleIds = self::getDedCircleIds($admin);

        if (empty($allowedCircleIds)) {
            $query->whereRaw('1=0');
        } else {
            $query->whereIn("{$circleAlias}.id", $allowedCircleIds);
        }
    }

    public static function applyToEventsQuery($query, ?AdminUser $admin, string $eventTable = 'events'): void
    {
        if (! AdminAccess::isDed($admin)) {
            return;
        }

        if (! Schema::hasColumn($eventTable, 'circle_id') || ! Schema::hasTable('circles')) {
            $query->whereRaw('1=0');
            return;
        }

        $query->whereExists(function ($subQuery) use ($eventTable, $admin) {
            $subQuery->selectRaw(1)
                ->from('circles as ded_scope_circles')
                ->whereColumn('ded_scope_circles.id', "{$eventTable}.circle_id");

            self::applyToCirclesQuery($subQuery, $admin, 'ded_scope_circles');
        });
    }

    public static function eventInScope(?AdminUser $admin, string $eventId): bool
    {
        if (! AdminAccess::isDed($admin)) {
            return true;
        }

        $query = \App\Models\Event::query()->whereKey($eventId);
        self::applyToEventsQuery($query, $admin);

        return $query->exists();
    }

    public static function userInScope(?AdminUser $admin, string $userId): bool
    {
        if (AdminAccess::isDed($admin)) {
            $query = User::query()->whereKey($userId);
            self::applyDedDistrictScope($query, $admin);

            return $query->exists();
        }

        if (! AdminAccess::isCircleScoped($admin)) {
            return true;
        }

        $circleId = self::resolveCircleId($admin);

        if (! $circleId) {
            return false;
        }

        return CircleMember::query()
            ->where('user_id', $userId)
            ->where('circle_id', $circleId)
            ->where('status', 'approved')
            ->whereNull('deleted_at')
            ->exists();
    }
}
