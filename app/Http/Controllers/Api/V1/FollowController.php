<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\FollowRequestResource;
use App\Http\Resources\FollowResource;
use App\Models\User;
use App\Models\UserFollow;
use App\Notifications\FollowAcceptedNotification;
use App\Notifications\FollowRequestedNotification;
use App\Notifications\UnfollowedNotification;
use App\Services\PushNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FollowController extends Controller
{
    public function __construct(private readonly PushNotificationService $pushNotificationService)
    {
    }

    public function requestFollow(Request $request, User $user): JsonResponse
    {
        if (! $this->isUuid($user->id)) {
            return $this->errorResponse('Invalid user id.', ['user' => ['The user field must be a valid UUID.']], 422);
        }

        $authUser = $request->user();

        if ((string) $authUser->id === (string) $user->id) {
            return $this->errorResponse('You cannot follow yourself.', ['user' => ['You cannot follow yourself.']], 422);
        }

        if (isset($user->status) && $user->status !== 'active') {
            return $this->errorResponse('Target user is not active.', ['user' => ['Target user is not active.']], 422);
        }

        $follow = UserFollow::query()
            ->where('follower_id', $authUser->id)
            ->where('following_id', $user->id)
            ->first();

        if ($follow && $follow->status === 'accepted') {
            return $this->successResponse('Already following this user.', new FollowResource($follow->load(['follower', 'following'])));
        }

        if ($follow && $follow->status === 'pending') {
            return $this->successResponse('Follow request already sent.', new FollowResource($follow->load(['follower', 'following'])));
        }

        if ($follow && $follow->status === 'rejected') {
            $follow->fill([
                'status' => 'pending',
                'requested_at' => now(),
                'accepted_at' => null,
                'rejected_at' => null,
                'blocked_at' => null,
            ])->save();

            $this->notifyFollowRequested($authUser, $user, $follow->load(['follower', 'following']));

            return $this->successResponse('Follow request sent successfully.', new FollowResource($follow));
        }

        $follow = UserFollow::create([
            'follower_id' => $authUser->id,
            'following_id' => $user->id,
            'status' => 'pending',
            'requested_at' => now(),
            'accepted_at' => null,
            'rejected_at' => null,
            'blocked_at' => null,
        ])->load(['follower', 'following']);

        $this->notifyFollowRequested($authUser, $user, $follow);

        return $this->successResponse('Follow request sent successfully.', new FollowResource($follow), 201);
    }

    public function accept(Request $request, UserFollow $follow): JsonResponse
    {
        $authUser = $request->user();

        if ((string) $follow->following_id !== (string) $authUser->id) {
            return $this->errorResponse('You are not allowed to accept this follow request.', null, 403);
        }

        if ($follow->status !== 'pending') {
            return $this->errorResponse('Only pending follow requests can be accepted.', null, 422);
        }

        $follow->fill([
            'status' => 'accepted',
            'accepted_at' => now(),
            'rejected_at' => null,
        ])->save();

        $follow->load(['follower', 'following']);

        $notification = new FollowAcceptedNotification($authUser, $follow);
        $payload = $notification->toArray($follow->follower);

        $this->pushNotificationService->storeAndSend(
            $follow->follower,
            $payload['title'],
            $payload['body'],
            $payload,
            [
                'notification_type' => $payload['notification_type'],
                'follow_id' => $follow->id,
                'from_user_id' => $authUser->id,
            ]
        );

        return $this->successResponse('Follow request accepted.', new FollowResource($follow));
    }

    public function reject(Request $request, UserFollow $follow): JsonResponse
    {
        $authUser = $request->user();

        if ((string) $follow->following_id !== (string) $authUser->id) {
            return $this->errorResponse('You are not allowed to reject this follow request.', null, 403);
        }

        if ($follow->status !== 'pending') {
            return $this->errorResponse('Only pending follow requests can be rejected.', null, 422);
        }

        $follow->fill([
            'status' => 'rejected',
            'rejected_at' => now(),
            'accepted_at' => null,
        ])->save();

        return $this->successResponse('Follow request rejected.', new FollowResource($follow->load(['follower', 'following'])));
    }

    public function unfollow(Request $request, User $user): JsonResponse
    {
        if (! $this->isUuid($user->id)) {
            return $this->errorResponse('Invalid user id.', ['user' => ['The user field must be a valid UUID.']], 422);
        }

        $authUser = $request->user();

        $follow = UserFollow::query()
            ->where('follower_id', $authUser->id)
            ->where('following_id', $user->id)
            ->where('status', 'accepted')
            ->first();

        if (! $follow) {
            return $this->successResponse('Not following', [
                'unfollowed' => false,
            ]);
        }

        $follow->delete();

        $notification = new UnfollowedNotification($authUser);
        $payload = $notification->toArray($user);

        $this->pushNotificationService->storeAndSend(
            $user,
            $payload['title'],
            $payload['body'],
            $payload,
            [
                'notification_type' => $payload['notification_type'],
                'from_user_id' => $authUser->id,
            ]
        );

        return $this->successResponse('Unfollowed successfully.', [
            'unfollowed' => true,
        ]);
    }

    public function cancel(Request $request, UserFollow $follow): JsonResponse
    {
        $authUser = $request->user();

        if ((string) $follow->follower_id !== (string) $authUser->id) {
            return $this->errorResponse('You are not allowed to cancel this follow request.', null, 403);
        }

        if ($follow->status !== 'pending') {
            return $this->errorResponse('Only pending requests can be canceled.', null, 422);
        }

        $follow->delete();

        return $this->successResponse('Follow request canceled successfully.', [
            'canceled' => true,
        ]);
    }

    public function incomingRequests(Request $request): JsonResponse
    {
        $requests = UserFollow::query()
            ->with('follower')
            ->where('following_id', $request->user()->id)
            ->where('status', 'pending')
            ->orderByDesc('requested_at')
            ->get();

        return $this->successResponse('Incoming follow requests fetched successfully.', FollowRequestResource::collection($requests));
    }

    public function myFollowing(Request $request): JsonResponse
    {
        $following = UserFollow::query()
            ->with(['follower', 'following'])
            ->where('follower_id', $request->user()->id)
            ->whereNull('rejected_at')
            ->whereNull('blocked_at')
            ->whereIn('status', ['pending', 'accepted'])
            ->orderByDesc('requested_at')
            ->simplePaginate(20);

        return $this->successResponse('Following list fetched successfully.', [
            'items' => FollowResource::collection($following->getCollection()),
            'pagination' => [
                'current_page' => $following->currentPage(),
                'per_page' => $following->perPage(),
                'next_page_url' => $following->nextPageUrl(),
                'prev_page_url' => $following->previousPageUrl(),
            ],
        ]);
    }

    public function myFollowers(Request $request): JsonResponse
    {
        $followers = UserFollow::query()
            ->with(['follower', 'following'])
            ->where('following_id', $request->user()->id)
            ->where('status', 'accepted')
            ->orderByDesc('accepted_at')
            ->simplePaginate(20);

        return $this->successResponse('Followers list fetched successfully.', [
            'items' => FollowResource::collection($followers->getCollection()),
            'pagination' => [
                'current_page' => $followers->currentPage(),
                'per_page' => $followers->perPage(),
                'next_page_url' => $followers->nextPageUrl(),
                'prev_page_url' => $followers->previousPageUrl(),
            ],
        ]);
    }

    public function status(Request $request, User $user): JsonResponse
    {
        if (! $this->isUuid($user->id)) {
            return $this->errorResponse('Invalid user id.', ['user' => ['The user field must be a valid UUID.']], 422);
        }

        $authUser = $request->user();

        if ((string) $authUser->id === (string) $user->id) {
            return $this->successResponse('Follow status fetched successfully.', [
                'state' => 'none',
                'follow_id' => null,
            ]);
        }

        $follow = UserFollow::query()
            ->where('follower_id', $authUser->id)
            ->where('following_id', $user->id)
            ->first();

        $state = 'none';
        $followId = null;

        if ($follow && in_array($follow->status, ['pending', 'accepted'], true)) {
            $state = $follow->status;
            $followId = $follow->id;
        }

        return $this->successResponse('Follow status fetched successfully.', [
            'state' => $state,
            'follow_id' => $followId,
        ]);
    }

    private function notifyFollowRequested(User $fromUser, User $targetUser, UserFollow $follow): void
    {
        $notification = new FollowRequestedNotification($fromUser, $follow);
        $payload = $notification->toArray($targetUser);

        $this->pushNotificationService->storeAndSend(
            $targetUser,
            $payload['title'],
            $payload['body'],
            $payload,
            [
                'notification_type' => $payload['notification_type'],
                'follow_id' => $follow->id,
                'from_user_id' => $fromUser->id,
            ]
        );
    }

    private function successResponse(string $message, mixed $data = null, int $statusCode = 200): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => $message,
            'data' => $data,
            'errors' => null,
        ], $statusCode);
    }

    private function errorResponse(string $message, mixed $errors = null, int $statusCode = 400): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => $message,
            'data' => null,
            'errors' => $errors,
        ], $statusCode);
    }

    private function isUuid(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-fA-F-]{36}$/', $value);
    }
}

/*
POSTMAN TEST STEPS
1) user1 -> send request
   POST /api/v1/users/{user2_uuid}/follow
2) user2 -> list incoming requests
   GET /api/v1/me/follow-requests
3) user2 -> accept request
   POST /api/v1/follows/{follow_uuid}/accept
4) user1 -> list following
   GET /api/v1/me/following
5) user2 -> list followers
   GET /api/v1/me/followers
6) user1 -> unfollow
   DELETE /api/v1/users/{user2_uuid}/unfollow
7) follow-status check
   GET /api/v1/users/{user2_uuid}/follow-status
*/
