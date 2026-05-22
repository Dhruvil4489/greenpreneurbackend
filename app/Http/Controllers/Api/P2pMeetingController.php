<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Activity\StoreP2pMeetingRequest;
use App\Models\P2pMeeting;
use App\Models\Post;
use App\Models\User;
use App\Events\ActivityCreated;
use App\Models\FileModel;
use App\Services\Blocks\PeerBlockService;
use App\Services\Coins\CoinsService;
use App\Services\Notifications\NotifyUserService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class P2pMeetingController extends BaseApiController
{
    /**
     * Create a feed post for a newly created P2P meeting.
     */
    protected function createPostForP2pMeeting(P2pMeeting $meeting): void
    {
        try {
            $peerUser = $meeting->peer_user_id ? User::find($meeting->peer_user_id) : null;
            $contentText = $this->buildActivityPostMessage('p2p_meeting', $peerUser);

            Post::create([
                'user_id'           => $meeting->initiator_user_id,
                'circle_id'         => null,
                'content_text'      => $contentText,
                'media'             => [],
                'tags'              => ['p2p_meeting'],
                'source_type'       => 'p2p_meeting',
                'source_id'         => $meeting->id,
                'source_event'      => 'p2p_meeting_created',
                'visibility'        => 'public',
                'moderation_status' => 'pending',
                'sponsored'         => false,
                'is_deleted'        => false,
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to create post for P2P meeting', [
                'p2p_meeting_id' => $meeting->id,
                'error'          => $e->getMessage(),
            ]);
        }
    }

    public function index(Request $request)
    {
        $authUser = $request->user();
        $filter = $request->input('filter', 'initiated');

        $query = P2pMeeting::query()
            ->where('is_deleted', false)
            ->whereNull('deleted_at');

        if ($filter === 'received') {
            $query->where('peer_user_id', $authUser->id);
        } elseif ($filter === 'all') {
            $query->where(function ($q) use ($authUser) {
                $q->where('initiator_user_id', $authUser->id)
                    ->orWhere('peer_user_id', $authUser->id);
            });
        } else {
            $query->where('initiator_user_id', $authUser->id);
        }

        $perPage = (int) $request->input('per_page', 20);
        $perPage = max(1, min($perPage, 100));

        $paginator = $query
            ->orderBy('meeting_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return $this->success([
            'items' => collect($paginator->items())
                ->map(fn (P2pMeeting $meeting): array => $this->buildP2pMeetingResponse($meeting))
                ->values()
                ->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreP2pMeetingRequest $request, NotifyUserService $notifyUserService, PeerBlockService $peerBlockService)
    {
        $authUser = $request->user();
        $targetUserId = (string) $request->input('peer_user_id');

        if ($peerBlockService->isBlockedEitherWay((string) $authUser->id, $targetUserId)) {
            return $this->error('You cannot interact with this peer.', 422);
        }

        try {
            $mediaEntries = $this->buildMediaEntries((array) $request->input('media_file_ids', []));

            $meeting = P2pMeeting::create([
                'initiator_user_id' => $authUser->id,
                'peer_user_id' => $request->input('peer_user_id'),
                'meeting_date' => $request->input('meeting_date'),
                'meeting_place' => $request->input('meeting_place'),
                'remarks' => $request->input('remarks'),
                'media' => $mediaEntries,
                'is_deleted' => false,
            ]);

            $coinsLedger = app(CoinsService::class)->rewardForActivity(
                $authUser,
                'p2p_meeting',
                null,
                'Activity: p2p_meeting',
                $authUser->id
            );

            if ($coinsLedger) {
                $meeting->setAttribute('coins', [
                    'earned' => $coinsLedger->amount,
                    'balance_after' => $coinsLedger->balance_after,
                ]);
            }

            $meeting->setAttribute('media', $this->expandP2pMedia($meeting->media));

            $this->createPostForP2pMeeting($meeting);

            event(new ActivityCreated(
                'P2P Meeting',
                $meeting,
                (string) $authUser->id,
                $meeting->peer_user_id ? (string) $meeting->peer_user_id : null
            ));

            $targetUser = User::find($meeting->peer_user_id);

            if ($targetUser) {
                $notifyUserService->notifyUser(
                    $targetUser,
                    $authUser,
                    'activity_p2p_meeting',
                    [
                        'activity_type' => 'p2p_meeting',
                        'activity_id' => (string) $meeting->id,
                        'title' => 'New P2P Meeting',
                        'body' => ($authUser->display_name ?? $authUser->name ?? 'A member') . ' scheduled a P2P meeting with you',
                    ],
                    $meeting
                );
            }

            // Postman example (p2p meeting create):
            // {
            //   "peer_user_id": "<receiver-user-uuid>",
            //   "meeting_date": "2026-01-20",
            //   "meeting_place": "Peers HQ",
            //   "remarks": "Let's discuss collaboration"
            // }
            // Verify SQL:
            // select * from notifications where user_id = '<receiver-user-uuid>' order by created_at desc limit 20;

            return $this->success($this->buildP2pMeetingResponse($meeting), 'P2P meeting saved successfully', 201);
        } catch (Throwable $e) {
            return $this->error('Something went wrong', 500);
        }
    }

    public function show(Request $request, string $id)
    {
        $authUser = $request->user();

        $meeting = P2pMeeting::where('id', $id)
            ->where('is_deleted', false)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($authUser) {
                $q->where('initiator_user_id', $authUser->id)
                    ->orWhere('peer_user_id', $authUser->id);
            })
            ->first();

        if (! $meeting) {
            return $this->error('P2P meeting not found', 404);
        }

        return $this->success($this->buildP2pMeetingResponse($meeting));
    }


    /**
     * @return array<string, mixed>
     */
    private function buildP2pMeetingResponse(P2pMeeting $meeting): array
    {
        $attributes = $meeting->toArray();
        $attributes['media'] = $this->expandP2pMedia($attributes['media'] ?? null);

        if ($meeting->getAttribute('coins') !== null) {
            $attributes['coins'] = $meeting->getAttribute('coins');
        }

        return $this->formatP2pMeetingTimestamps($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function formatP2pMeetingTimestamps(array $attributes): array
    {
        foreach (['created_at', 'updated_at'] as $field) {
            if (! empty($attributes[$field])) {
                $attributes[$field] = Carbon::parse((string) $attributes[$field])
                    ->timezone('Asia/Kolkata')
                    ->format('Y-m-d H:i:s');
            }
        }

        return $attributes;
    }

    /**
     * @param  array<int, string>  $fileIds
     * @return array<int, array{file_id: string, media_type: string}>
     */
    private function buildMediaEntries(array $fileIds): array
    {
        if ($fileIds === []) {
            return [];
        }

        $fileMap = FileModel::query()
            ->whereIn('id', $fileIds)
            ->get(['id', 'mime_type'])
            ->keyBy('id');

        $media = [];

        foreach ($fileIds as $fileId) {
            $file = $fileMap->get($fileId);
            if (! $file) {
                continue;
            }

            $mimeType = strtolower((string) ($file->mime_type ?? ''));
            $media[] = [
                'file_id' => (string) $file->id,
                'media_type' => str_starts_with($mimeType, 'image/')
                    ? 'image'
                    : (str_starts_with($mimeType, 'video/') ? 'video' : 'file'),
            ];
        }

        return $media;
    }

    /**
     * @param  mixed  $rawMedia
     * @return array<int, array<string, mixed>>
     */
    private function expandP2pMedia(mixed $rawMedia): array
    {
        $media = $this->normalizeMediaPayload($rawMedia);

        if ($media === []) {
            return [];
        }

        $fileIds = collect($media)
            ->map(fn ($item): ?string => is_array($item) ? ($item['file_id'] ?? null) : null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $files = FileModel::query()
            ->whereIn('id', $fileIds)
            ->get()
            ->keyBy('id');

        return collect($media)
            ->map(function ($item) use ($files): array {
                $fileId = is_array($item) ? ($item['file_id'] ?? null) : null;
                $mediaType = is_array($item) ? ($item['media_type'] ?? null) : null;
                $file = is_string($fileId) && $fileId !== '' ? $files->get($fileId) : null;

                return [
                    'file_id' => $fileId,
                    'media_type' => $mediaType,
                    'url' => is_string($fileId) && $fileId !== '' ? url('/api/v1/files/' . $fileId) : null,
                    'mime_type' => $file->mime_type ?? $file->mime ?? $file->type ?? null,
                    'original_name' => $file->original_name ?? $file->original_filename ?? $file->name ?? null,
                    'size' => $file->size ?? $file->size_bytes ?? null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeMediaPayload(mixed $rawMedia): array
    {
        if (is_string($rawMedia)) {
            $decoded = json_decode($rawMedia, true);
            return is_array($decoded) ? $decoded : [];
        }

        return is_array($rawMedia) ? $rawMedia : [];
    }
}
