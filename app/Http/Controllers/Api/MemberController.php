<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\ConnectionResource;
use App\Http\Resources\MemberDetailResource;
use App\Http\Resources\UserResource;
use App\Models\Connection;
use App\Models\User;
use App\Services\Blocks\PeerBlockService;
use App\Services\Notifications\NotifyUserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class MemberController extends BaseApiController
{
    public function index(Request $request, PeerBlockService $peerBlockService)
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
            'last_login_at',
            'created_at',
            'updated_at',
            'profile_photo_file_id',
            'media',
            'city_id',
            'city',
            'business_type',
        ];

        if (Schema::hasColumn('users', 'profile_video_id')) {
            $selectColumns[] = 'profile_video_id';
        }

        $query = User::query()
            ->select($selectColumns)
            ->with([
                'city:id,name',
                'circleMemberships' => fn ($query) => $this->joinedCircleMembershipsQuery($query),
            ]);

        // Manual test: inactive members should be excluded from the members list API.
        $query->where(function ($statusQuery) {
            $statusQuery->whereNull('status')->orWhere('status', 'active');
        });


        $excludedUserIds = array_values(array_unique(array_filter(array_merge(
            $peerBlockService->blockedUserIdsFor((string) $request->user()->id),
            $peerBlockService->usersWhoBlockedMeIdsFor((string) $request->user()->id)
        ))));

        if (! empty($excludedUserIds)) {
            $query->whereNotIn('id', $excludedUserIds);
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

        $authBusinessType = $request->user()->business_type;

        $query->orderByRaw(
            'CASE WHEN business_type = ? THEN 0 ELSE 1 END',
            [$authBusinessType]
        )->orderByDesc('created_at');

        $data = [
            'items' => UserResource::collection($query->get()),
        ];

        return $this->success($data);
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
        $user = User::with($this->memberDetailRelations())->find($id);

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
