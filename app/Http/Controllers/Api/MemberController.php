<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\ConnectionResource;
use App\Http\Resources\MemberDetailResource;
use App\Http\Resources\UserResource;
use App\Models\Connection;
use App\Models\User;
use App\Models\UserFollow;
use App\Services\Blocks\PeerBlockService;
use App\Services\Notifications\NotifyUserService;
use App\Services\ProfileMatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class MemberController extends BaseApiController
{
    public function index(Request $request, PeerBlockService $peerBlockService, ProfileMatchService $profileMatchService)
    {
        $selectColumns = [
            'id',
            'public_profile_slug',
            'first_name',
            'last_name',
            'display_name',
            'company_name',
            'email',
            'phone',
            'membership_status',
            'coins_balance',
            'life_impacted_count',
            'last_login_at',
            'created_at',
            'updated_at',
            'profile_photo_file_id',
            'media',
            'city_id',
            'city',
            'business_type',
        ];

        $profileMatchColumns = [
            'city_of_residence',
            'state',
            'country',
            'business_city',
            'business_state',
            'business_country',
            'business_pincode',
            'main_business_category_id',
            'business_category_id',
            'business_sub_category',
            'company_type',
            'year_of_establishment',
            'annual_revenue_range',
            'number_of_employees',
            'products_services_offered',
            'business_keywords',
            'designation',
            'experience_years',
            'experience_summary',
            'skills',
            'industries_of_interest',
            'interests',
            'collaboration_goals',
            'i_can_help_with',
            'i_am_looking_for',
            'superpower',
            'preferred_language',
            'preferred_meeting_format',
            'willing_to_mentor',
            'open_to_cross_city_collaboration',
            'open_to_speaking_at_events',
            'business_website',
            'linkedin_profile',
            'instagram_handle',
            'facebook_profile',
            'youtube_channel',
            'cover_photo_file_id',
            'profile_video_id',
        ];

        foreach ($profileMatchColumns as $column) {
            if (Schema::hasColumn('users', $column)) {
                $selectColumns[] = $column;
            }
        }

        $selectColumns = array_values(array_unique($selectColumns));

        $query = User::query()
            ->select($selectColumns)
            ->with([
                'city:id,name',
                'circleMemberships' => fn ($query) => $this->joinedCircleMembershipsQuery($query),
            ])
            ->withCount([
                'followers as followers_count',
                'following as following_count',
            ]);

        // Manual test: inactive members should be excluded from the members list API.
        $query->where(function ($statusQuery) {
            $statusQuery->whereNull('status')->orWhere('status', 'active');
        });


        $authUser = auth('sanctum')->user();

        if ($authUser) {
            $authUser->loadMissing([
                'city:id,name',
                'circleMemberships' => fn ($query) => $this->joinedCircleMembershipsQuery($query),
            ]);

            $excludedUserIds = array_values(array_unique(array_filter(array_merge(
                $peerBlockService->blockedUserIdsFor((string) $authUser->id),
                $peerBlockService->usersWhoBlockedMeIdsFor((string) $authUser->id)
            ))));

            if (! empty($excludedUserIds)) {
                $query->whereNotIn('id', $excludedUserIds);
            }
        }

        if ($search = trim((string) $request->input('q', ''))) {
            $query->whereRaw(
                "search_vector @@ plainto_tsquery('simple', unaccent(?))",
                [$search]
            );
        }

        if ($cityId = $request->input('city_id')) {
            $query->where('city_id', $cityId);
        }

        $tags = $request->input('industry_tags');
        if ($tags) {
            if (is_string($tags)) {
                $tags = array_filter(array_map('trim', explode(',', $tags)));
            }

            if (is_array($tags) && count($tags) > 0) {
                $query->where(function ($q) use ($tags) {
                    foreach ($tags as $tag) {
                        $q->orWhereJsonContains('industry_tags', $tag);
                    }
                });
            }
        }

        if ($request->has('business_type')) {
            $query->where('business_type', $request->input('business_type'));
        }

        if ($authUser && filled($authUser->business_type)) {
            $query->orderByRaw(
                'CASE WHEN business_type = ? THEN 0 ELSE 1 END',
                [$authUser->business_type]
            );
        }

        $query->orderByDesc('created_at');

        $request->attributes->set('profile_match_enabled', true);
        $request->attributes->set('profile_match_auth_user', $authUser);
        $request->attributes->set('profile_match_service', $profileMatchService);

        $members = $query->get();

        if ($authUser) {
            $members = $this->applyProfileMatchOrdering(
                $members,
                $authUser,
                $profileMatchService,
                $selectColumns,
                false
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Members fetched successfully.',
            'data' => UserResource::collection($members),
        ]);
    }


    private function applyProfileMatchOrdering(
        Collection $members,
        User $authUser,
        ProfileMatchService $profileMatchService,
        array $selectColumns,
        bool $includeAuthUserWhenMissing = true
    ): Collection
    {
        $authUserId = (string) $authUser->id;

        if ($includeAuthUserWhenMissing && ! $members->contains(fn (User $member): bool => (string) $member->id === $authUserId)) {
            $self = User::query()
                ->select($selectColumns)
                ->with([
                    'city:id,name',
                    'circleMemberships' => fn ($query) => $this->joinedCircleMembershipsQuery($query),
                ])
                ->withCount([
                    'followers as followers_count',
                    'following as following_count',
                ])
                ->find($authUserId);

            if ($self) {
                $members->push($self);
            }
        }

        return $members
            ->map(function (User $member) use ($authUser, $profileMatchService): User {
                $member->setAttribute('profile_match', $profileMatchService->calculate($authUser, $member));

                return $member;
            })
            ->sort(function (User $a, User $b) use ($authUserId): int {
                if ((string) $a->id === $authUserId) {
                    return -1;
                }

                if ((string) $b->id === $authUserId) {
                    return 1;
                }

                $aScore = (int) data_get($a->getAttribute('profile_match'), 'percentage', 0);
                $bScore = (int) data_get($b->getAttribute('profile_match'), 'percentage', 0);

                return $bScore <=> $aScore;
            })
            ->values();
    }

    public function names(Request $request, PeerBlockService $peerBlockService)
    {
        $members = User::query()
            ->select('id', 'display_name')
            ->whereNull('deleted_at')
            ->where(function ($statusQuery) {
                $statusQuery->whereNull('status')->orWhere('status', 'active');
            });

        $excludedUserIds = array_values(array_unique(array_filter(array_merge(
            $peerBlockService->blockedUserIdsFor((string) $request->user()->id),
            $peerBlockService->usersWhoBlockedMeIdsFor((string) $request->user()->id)
        ))));

        if (! empty($excludedUserIds)) {
            $members->whereNotIn('id', $excludedUserIds);
        }

        return $this->success(
            $members->orderBy('display_name', 'asc')->get(),
            'Member names fetched successfully.'
        );
    }

    public function show(Request $request, string $id, PeerBlockService $peerBlockService)
    {
        $user = User::with($this->memberDetailRelations())
            ->withCount([
                'followers as followers_count',
                'following as following_count',
            ])
            ->find($id);

        if (! $user) {
            return $this->error('Member not found', 404);
        }


        if ($peerBlockService->isBlockedEitherWay((string) $request->user()->id, (string) $user->id)) {
            return $this->error('Peer not found.', 404);
        }

        return $this->success(new MemberDetailResource($user));
    }

    public function publicProfileBySlug(Request $request, string $slug, PeerBlockService $peerBlockService)
    {
        $user = User::with($this->memberDetailRelations())
            ->withCount([
                'followers as followers_count',
                'following as following_count',
            ])
            ->where('public_profile_slug', $slug)
            ->first();

        if (! $user) {
            return $this->error('Public profile not found', 404);
        }


        if ($peerBlockService->isBlockedEitherWay((string) $request->user()->id, (string) $user->id)) {
            return $this->error('Peer not found.', 404);
        }

        return $this->success(new MemberDetailResource($user));
    }

    public function followersCount(string $user): JsonResponse
    {
        $member = User::query()->find($user);

        if (! $member) {
            return $this->error('User not found.', 404);
        }

        $followersQuery = UserFollow::query()
            ->where('following_id', $member->id)
            ->with([
                'follower:id,display_name,first_name,last_name,company_name,designation,email,phone,city_id,city,country,life_impacted_count,profile_photo_file_id',
                'follower.city:id,name',
            ]);

        $followersCount = (clone $followersQuery)->count();

        $followers = $followersQuery
            ->latest('requested_at')
            ->get()
            ->map(fn (UserFollow $follow): array => $this->formatFollowerCountItem($follow))
            ->values();

        return $this->success([
            'user_id' => (string) $member->id,
            'followers_count' => $followersCount,
            'followers' => $followers,
        ], 'Follower count fetched successfully.');
    }

    private function formatFollowerCountItem(UserFollow $follow): array
    {
        $follower = $follow->follower;

        return [
            'follow_id' => (string) $follow->id,
            'status' => $follow->status,
            'requested_at' => optional($follow->requested_at)?->toISOString(),
            'accepted_at' => optional($follow->accepted_at)?->toISOString(),
            'user' => $follower ? $this->formatFollowerUser($follower) : null,
        ];
    }

    private function formatFollowerUser(User $follower): array
    {
        $profilePhotoId = $follower->profile_photo_file_id;

        return [
            'id' => (string) $follower->id,
            'display_name' => $follower->display_name,
            'first_name' => $follower->first_name,
            'last_name' => $follower->last_name,
            'company_name' => $follower->company_name,
            'designation' => $follower->designation,
            'email' => $follower->email,
            'phone' => $follower->phone,
            'city' => $this->resolveFollowerCityName($follower),
            'country' => $follower->country,
            'life_impacted_count' => (int) ($follower->life_impacted_count ?? 0),
            'profile_photo_id' => $profilePhotoId,
            'profile_photo_url' => $profilePhotoId
                ? url('/api/v1/files/' . $profilePhotoId)
                : null,
        ];
    }

    private function resolveFollowerCityName(User $follower): ?string
    {
        if ($follower->relationLoaded('city')) {
            $city = $follower->getRelation('city');

            if ($city) {
                return $city->name;
            }
        }

        $city = $follower->getAttribute('city');

        if (is_array($city)) {
            return $city['name'] ?? null;
        }

        return $city ?: null;
    }

    private function memberDetailRelations(): array
    {
        return [
            'city',
            'activeCircle.cityRef',
            'mainBusinessCategory',
            'businessCategory',
            'circleMemberships' => fn ($query) => $this->joinedCircleMembershipsQuery($query),
        ];
    }

    private function joinedCircleMembershipsQuery($query): void
    {
        $query
            ->where('status', (string) config('circle.member_joined_status', 'approved'))
            ->whereNull('deleted_at')
            ->whereNull('left_at')
            ->where(function ($nested): void {
                $nested->whereNull('paid_ends_at')->orWhere('paid_ends_at', '>=', now());

                if (Schema::hasColumn('circle_members', 'expires_at')) {
                    $nested->orWhere('expires_at', '>=', now());
                }
            })
            ->orderByDesc('joined_at')
            ->with('circle:id,name,slug');
    }

    public function sendConnectionRequest(Request $request, string $id, NotifyUserService $notifyUserService, PeerBlockService $peerBlockService)
    {
        $authUser = $request->user();

        if ($authUser->id === $id) {
            return $this->error('You cannot connect to yourself', 422);
        }

        $target = User::find($id);
        if (! $target) {
            return $this->error('Member not found', 404);
        }


        if ($peerBlockService->isBlockedEitherWay((string) $authUser->id, (string) $target->id)) {
            return $this->error('You cannot interact with this peer.', 422);
        }

        $existing = Connection::where(function ($q) use ($authUser, $target) {
                $q->where('requester_id', $authUser->id)
                    ->where('addressee_id', $target->id);
            })
            ->orWhere(function ($q) use ($authUser, $target) {
                $q->where('requester_id', $target->id)
                    ->where('addressee_id', $authUser->id);
            })
            ->first();

        if ($existing) {
            if ($existing->is_approved) {
                return $this->error('You are already connected with this member', 422);
            }

            return $this->error('A connection request already exists', 422);
        }

        $connection = Connection::create([
            'requester_id' => $authUser->id,
            'addressee_id' => $target->id,
            'is_approved' => false,
        ]);

        $connection->load(['requester', 'addressee']);

        $notifyUserService->notifyUser(
            $target,
            $authUser,
            'connection_request',
            [
                'request_id' => (string) $connection->id,
                'title' => 'New Connection Request',
                'body' => ($authUser->display_name ?? $authUser->name ?? 'A member') . ' sent you a connection request',
            ],
            $connection
        );

        // Postman example (send connection request):
        // POST /api/v1/members/{id}/connect
        // Verify SQL:
        // select * from notifications where user_id = '<receiver-user-uuid>' order by created_at desc limit 20;

        return $this->success(new ConnectionResource($connection), 'Connection request sent', 201);
    }

    public function acceptConnection(Request $request, string $id, NotifyUserService $notifyUserService)
    {
        $authUser = $request->user();

        $connection = Connection::where('requester_id', $id)
            ->where('addressee_id', $authUser->id)
            ->where('is_approved', false)
            ->first();

        if (! $connection) {
            return $this->error('Connection request not found', 404);
        }

        $connection->is_approved = true;
        $connection->approved_at = now();
        $connection->save();

        $connection->load(['requester', 'addressee']);

        $requesterUser = $connection->requester;

        if ($requesterUser) {
            $notifyUserService->notifyUser(
                $requesterUser,
                $authUser,
                'connection_accepted',
                [
                    'request_id' => (string) $connection->id,
                    'from_user_id' => (string) $authUser->id,
                    'to_user_id' => (string) $requesterUser->id,
                    'title' => 'Connection Accepted',
                    'body' => ($authUser->display_name ?? $authUser->name ?? 'A member') . ' accepted your connection request',
                ],
                $connection
            );
        }

        // Postman example (accept connection request):
        // POST /api/v1/members/{requesterUserId}/accept
        // Verify SQL:
        // select * from notifications where user_id = '<requester-user-uuid>' order by created_at desc limit 20;

        return $this->success(new ConnectionResource($connection), 'Connection request accepted');
    }

    public function deleteConnection(Request $request, string $id)
    {
        $authUser = $request->user();

        $connection = Connection::where(function ($q) use ($authUser, $id) {
                $q->where('requester_id', $authUser->id)
                    ->where('addressee_id', $id);
            })
            ->orWhere(function ($q) use ($authUser, $id) {
                $q->where('requester_id', $id)
                    ->where('addressee_id', $authUser->id);
            })
            ->first();

        if (! $connection) {
            return $this->error('Connection not found', 404);
        }

        $connection->delete();

        return $this->success(null, 'Connection removed');
    }

    public function myConnections(Request $request)
    {
        $authUser = $request->user();

        $connections = Connection::with([
            'requester',
            'requester.city',
            'addressee',
            'addressee.city',
        ])
            ->where('is_approved', true)
            ->where(function ($q) use ($authUser) {
                $q->where('requester_id', $authUser->id)
                    ->orWhere('addressee_id', $authUser->id);
            })
            ->orderBy('approved_at', 'desc')
            ->get();

        return $this->success(ConnectionResource::collection($connections));
    }

    public function myConnectionRequests(Request $request)
    {
        $authUser = $request->user();

        $connections = Connection::with([
            'requester',
            'requester.city',
            'addressee',
            'addressee.city',
        ])
            ->where('addressee_id', $authUser->id)
            ->where('is_approved', false)
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->success(ConnectionResource::collection($connections));
    }
}
