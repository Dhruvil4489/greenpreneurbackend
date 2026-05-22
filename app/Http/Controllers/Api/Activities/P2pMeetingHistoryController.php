<?php

namespace App\Http\Controllers\Api\Activities;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Resources\TableRowResource;
use App\Models\FileModel;
use App\Models\P2pMeeting;
use App\Support\ActivityHistory\OtherUserNameResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class P2pMeetingHistoryController extends BaseApiController
{
    public function index(Request $request)
    {
        $authUserId = $request->user()->id;
        $filter = $request->query('filter', 'given');
        $debugMode = $request->boolean('debug');

        $query = P2pMeeting::query();

        $whereParts = [];

        $query->where(function ($q) use (&$whereParts) {
            $q->where('is_deleted', false)
                ->orWhereNull('is_deleted');

            $whereParts[] = '(is_deleted = false OR is_deleted IS NULL)';
        });

        $query->whereNull('deleted_at');
        $whereParts[] = 'deleted_at IS NULL';

        if ($filter === 'received') {
            $query->where('peer_user_id', $authUserId);
            $whereParts[] = 'peer_user_id = "' . $authUserId . '"';
        } else {
            $query->where('initiator_user_id', $authUserId);
            $whereParts[] = 'initiator_user_id = "' . $authUserId . '"';
            $filter = 'given';
        }

        $items = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $nameResolver = app(OtherUserNameResolver::class);

        $otherUserIds = $items->map(fn (P2pMeeting $meeting): ?string => $this->resolveOtherUserId($meeting, $authUserId));
        $nameMap = $nameResolver->mapNames($otherUserIds);

        $items = TableRowResource::collection(
            $items->map(function (P2pMeeting $meeting) use ($nameMap, $authUserId) {
                $attributes = $meeting->getAttributes();
                $otherUserId = $this->resolveOtherUserId($meeting, $authUserId);
                $attributes['other_user_name'] = $otherUserId ? ($nameMap[$otherUserId] ?? null) : null;
                $attributes['media'] = $this->expandMedia($meeting->media);

                return $attributes;
            })
        );

        $response = [
            'items' => $items,
        ];

        if ($debugMode) {
            $response['debug'] = [
                'auth_user_id' => $authUserId,
                'filter' => $filter,
                'where' => implode(' AND ', $whereParts),
            ];
        }

        return $this->success($response);
    }

    private function resolveOtherUserId(P2pMeeting $meeting, string $authUserId): ?string
    {
        if ($meeting->initiator_user_id === $authUserId) {
            return $meeting->peer_user_id;
        }

        return $meeting->initiator_user_id;
    }

    /**
     * @param  mixed  $rawMedia
     * @return array<int, array<string, mixed>>
     */
    private function expandMedia(mixed $rawMedia): array
    {
        if (! is_array($rawMedia) || $rawMedia === []) {
            return [];
        }

        $fileIds = collect($rawMedia)
            ->map(fn ($item): ?string => is_array($item) ? ($item['file_id'] ?? null) : null)
            ->filter()
            ->values()
            ->all();

        if ($fileIds === []) {
            return [];
        }

        $files = FileModel::query()
            ->whereIn('id', $fileIds)
            ->get(['id', 'mime_type', 'size_bytes'])
            ->keyBy('id');

        return collect($rawMedia)->map(function ($item) use ($files): ?array {
            if (! is_array($item)) {
                return null;
            }

            $fileId = $item['file_id'] ?? null;
            if (! is_string($fileId) || $fileId === '') {
                return null;
            }

            $file = $files->get($fileId);
            if (! $file) {
                return null;
            }

            $mimeType = (string) ($file->mime_type ?? '');
            $mediaType = $item['media_type'] ?? null;
            if (! is_string($mediaType) || $mediaType === '') {
                $normalizedMimeType = strtolower($mimeType);
                $mediaType = str_starts_with($normalizedMimeType, 'image/')
                    ? 'image'
                    : (str_starts_with($normalizedMimeType, 'video/') ? 'video' : 'file');
            }

            return [
                'file_id' => $fileId,
                'media_type' => $mediaType,
                'url' => url('/api/v1/files/' . $fileId),
                'mime_type' => $mimeType !== '' ? $mimeType : null,
                'original_name' => null,
                'size' => $file->size_bytes !== null ? (int) $file->size_bytes : null,
            ];
        })->filter()->values()->all();
    }
}
