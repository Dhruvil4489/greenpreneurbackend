<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\ActivityCreative;
use App\Models\FileModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ActivityCreativeController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'activity_type' => ['nullable', 'string', 'max:255'],
            'activity_id' => ['nullable', 'uuid'],
            'user_id' => ['nullable', 'uuid'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'max:50'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $query = ActivityCreative::query()->with(['user:id,first_name,last_name,display_name', 'post:id']);

        if ($request->filled('user_id') && $user && $user->is_admin) {
            $query->where('user_id', $request->string('user_id'));
        } else {
            $query->where('user_id', $user->id);
        }

        foreach (['activity_type', 'activity_id', 'status'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->input('from_date'));
        }
        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->input('to_date'));
        }

        $items = $query->latest('created_at')->paginate((int) $request->input('per_page', 15));

        return $this->success($items, 'Activity creatives fetched successfully.');
    }


    public function myCreatives(Request $request): JsonResponse
    {
        $request->validate([
            'activity_type' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:50'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $perPage = (int) $request->input('per_page', 20);

        $query = ActivityCreative::query()
            ->where('user_id', $user->id)
            ->whereNull('deleted_at');

        if ($request->filled('activity_type')) {
            $query->where('activity_type', $request->input('activity_type'));
        }

        $query->where('status', $request->input('status', 'active'));

        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->input('from_date'));
        }

        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->input('to_date'));
        }

        $paginator = $query->latest('created_at')->paginate($perPage);

        $items = collect($paginator->items())->map(function (ActivityCreative $creative) {
            return [
                'id' => $creative->id,
                'post_id' => $creative->post_id,
                'activity_type' => $creative->activity_type,
                'activity_id' => $creative->activity_id,
                'title' => $creative->title,
                'description' => $creative->description,
                'creative_file_id' => $creative->creative_file_id,
                'creative_url' => $creative->creative_url,
                'status' => $creative->status,
                'meta' => $creative->meta ?? [],
                'created_at' => optional($creative->created_at)->toISOString(),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'My activity creatives fetched successfully.',
            'data' => [
                'items' => $items,
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
        ]);
    }

    /**
     * Store a creative image and metadata.
     * Both activity_id and post_id are optional.
     * Duplicate prevention applies only when activity_id is present.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'activity_type' => ['required', 'string', 'max:255'],
            'activity_id' => ['nullable', 'uuid'],
            'post_id' => ['nullable', 'uuid'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'creative_image' => ['required', 'image', 'max:51200'],
            'meta' => ['nullable', 'array'],
        ]);

        $user = $request->user();
        $metaInput = $request->input('meta');
        if (is_string($metaInput) && $metaInput !== '') {
            $decodedMetaInput = json_decode($metaInput, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $metaInput = $decodedMetaInput;
            }
        }
        $meta = $this->parseMeta($metaInput);

        $creative = DB::transaction(function () use ($validated, $request, $user, $meta) {
            $existing = null;
            if (! empty($validated['activity_id'])) {
                $existing = ActivityCreative::query()
                    ->where('user_id', $user->id)
                    ->where('activity_type', $validated['activity_type'])
                    ->where('activity_id', $validated['activity_id'])
                    ->first();
            }

            $fileModel = $this->storeFile($request->file('creative_image'), $user->id);
            $fileUrl = url('/api/v1/files/' . $fileModel->id);

            $postId = $validated['post_id'] ?? ($existing?->post_id);


            $payload = [
                'user_id' => $user->id,
                'post_id' => $postId,
                'activity_type' => $validated['activity_type'],
                'activity_id' => $validated['activity_id'] ?? null,
                'title' => $validated['title'] ?? null,
                'description' => $validated['description'] ?? null,
                'creative_file_id' => $fileModel->id,
                'creative_url' => $fileUrl,
                'status' => 'active',
                'meta' => $meta,
                'created_by' => $user->id,
            ];

            if ($existing) {
                $existing->fill($payload)->save();

                return $existing->refresh();
            }

            return ActivityCreative::create($payload);
        });

        return $this->success($creative, 'Activity creative stored successfully.');
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $creative = ActivityCreative::query()->findOrFail($id);
        if ($creative->user_id !== $request->user()->id && ! $request->user()->is_admin) {
            return $this->error('Unauthorized.', 403);
        }

        return $this->success($creative, 'Activity creative fetched successfully.');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $creative = ActivityCreative::query()->findOrFail($id);
        if ($creative->user_id !== $request->user()->id && ! $request->user()->is_admin) {
            return $this->error('Unauthorized.', 403);
        }

        $creative->status = 'inactive';
        $creative->save();
        $creative->delete();

        return $this->success([], 'Activity creative deleted successfully.');
    }

    private function parseMeta(mixed $meta): array
    {
        if (is_array($meta)) {
            return $meta;
        }
        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function storeFile(UploadedFile $file, ?string $userId): FileModel
    {
        $disk = config('filesystems.default', 'public');
        $folder = 'uploads/' . now()->format('Y/m/d');
        $extension = $file->getClientOriginalExtension() ?: 'jpg';
        $path = $file->storeAs($folder, Str::uuid() . '.' . $extension, $disk);

        return FileModel::create([
            'uploader_user_id' => $userId,
            's3_key' => $path,
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
        ]);
    }
}
