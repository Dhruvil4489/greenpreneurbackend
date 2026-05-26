<?php

namespace App\Http\Controllers\Api\Activities;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Resources\TableRowResource;
use App\Models\FileModel;
use App\Models\P2pMeeting;
use App\Support\ActivityHistory\OtherUserNameResolver;
use Carbon\Carbon;
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
$attributes = $meeting->toArray();
                $otherUserId = $this->resolveOtherUserId($meeting, $authUserId);
                $attributes['other_user_name'] = $otherUserId ? ($nameMap[$otherUserId] ?? null) : null;
                $attributes['media'] = $this->expandP2pMedia($meeting->media);

                return $this->formatP2pMeetingTimestamps($attributes);
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
    private function expandP2pMedia(mixed $rawMedia): array
    {
        $media = $this->normalizeMediaPayload($rawMedia);

        if ($media === []) {
            return [];
        }

        $fileIds = collect($media)
            ->map(fn ($item): ?string => is_array($item) ? ($item['file_id'] ?? null) : null)
            ->filter()
            ->values()
            ->all();

        if ($fileIds === []) {
            return [];
        }

        $files = FileModel::query()->whereIn('id', $fileIds)->get()->keyBy('id');

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
