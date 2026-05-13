<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreCollaborationPostRequest;
use App\Http\Resources\CollaborationPostResource;
use App\Models\CollaborationPost;
use App\Services\Collaboration\CollaborationPostService;
use App\Services\Collaboration\CollaborationTimelinePostService;
use App\Services\CollaborationNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CollaborationPostController extends Controller
{
    public function __construct(
        private readonly CollaborationPostService $collaborationPostService,
        private readonly CollaborationTimelinePostService $collaborationTimelinePostService,
        private readonly CollaborationNotificationService $collaborationNotificationService
    ) {
    }

    public function history(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:incomplete,completed'],
        ]);

        $relations = [
            'user:id,first_name,last_name,display_name,city,membership_status,profile_photo_file_id',
            'industry:id,name,parent_id',
            'collaborationType:id,name,slug',
        ];

        $baseQuery = CollaborationPost::query()
            ->with($relations)
            ->where('user_id', $request->user()->id)
            ->where('status', '!=', CollaborationPost::STATUS_DELETED);

        $requestedStatus = $validated['status'] ?? null;

        $incomplete = collect();
        if ($requestedStatus === null || $requestedStatus === CollaborationPost::COMPLETION_INCOMPLETE) {
            $incomplete = (clone $baseQuery)
                ->where(function ($query): void {
                    $query->whereNull('completion_status')
                        ->orWhere('completion_status', CollaborationPost::COMPLETION_INCOMPLETE);
                })
                ->orderByDesc('posted_at')
                ->orderByDesc('created_at')
                ->get();
        }

        $completed = collect();
        if ($requestedStatus === null || $requestedStatus === CollaborationPost::COMPLETION_COMPLETED) {
            $completed = (clone $baseQuery)
                ->where('completion_status', CollaborationPost::COMPLETION_COMPLETED)
                ->orderByDesc('completed_at')
                ->orderByDesc('posted_at')
                ->orderByDesc('created_at')
                ->get();
        }

        return response()->json([
            'status' => true,
            'message' => 'Collaboration history fetched successfully.',
            'data' => [
                'incomplete' => CollaborationPostResource::collection($incomplete),
                'completed' => CollaborationPostResource::collection($completed),
            ],
        ]);
    }

    public function complete(Request $request, string $id): JsonResponse
    {
        return $this->markCompleted($request, $id, 'Collaboration marked as completed successfully.');
    }

    public function accept(Request $request, string $id): JsonResponse
    {
        return $this->markCompleted($request, $id, 'Collaboration accepted successfully.');
    }

    private function markCompleted(Request $request, string $id, string $successMessage): JsonResponse
    {
        $post = CollaborationPost::query()->where('id', $id)->first();

        if (! $post) {
            return response()->json([
                'status' => false,
                'message' => 'Collaboration post not found.',
                'data' => null,
            ], 404);
        }

        if ((string) $post->user_id !== (string) $request->user()->id) {
            return response()->json([
                'status' => false,
                'message' => 'You are not allowed to complete this collaboration post.',
                'data' => null,
            ], 403);
        }

        $wasAlreadyCompleted = $post->completion_status === CollaborationPost::COMPLETION_COMPLETED;

        if (! $wasAlreadyCompleted) {
            $post->update([
                'completion_status' => CollaborationPost::COMPLETION_COMPLETED,
                'completed_at' => now(),
            ]);
        }

        $this->collaborationTimelinePostService->createCompletedPost($post);

        if (! $wasAlreadyCompleted) {
            $this->collaborationNotificationService->sendCompletedNotificationsAndEmails($post);
        }

        $post->load([
            'user:id,first_name,last_name,display_name,city,membership_status,profile_photo_file_id',
            'industry:id,name,parent_id',
            'collaborationType:id,name,slug',
        ]);

        return response()->json([
            'status' => true,
            'message' => $successMessage,
            'data' => new CollaborationPostResource($post),
        ]);
    }

    public function store(StoreCollaborationPostRequest $request): JsonResponse
    {
        Log::info('HIT collaborations.store', [
            'user_id' => optional($request->user())->id,
            'payload' => $request->all(),
        ]);

        try {
            $post = $this->collaborationPostService->createForUser($request->user(), $request->validated());
        } catch (ValidationException $exception) {
            $errors = $exception->errors();

            if (isset($errors['industry_id']) && in_array('Please select a leaf industry.', $errors['industry_id'], true)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Please select a leaf industry.',
                    'data' => null,
                    'errors' => $errors,
                ], 422);
            }

            if (isset($errors['collaborations'])) {
                $message = $errors['collaborations'][0] ?? 'You have reached the active collaboration post limit.';

                return response()->json([
                    'status' => false,
                    'message' => $message,
                    'data' => null,
                    'errors' => $errors,
                ], 422);
            }

            throw $exception;
        }

        $this->collaborationTimelinePostService->createCreatedPost($post);
        $this->collaborationNotificationService->sendCreatedNotificationsAndEmail($post);

        $post->load([
            'user:id,first_name,last_name,display_name,city,membership_status,profile_photo_file_id',
            'industry:id,name,parent_id',
            'collaborationType:id,name,slug',
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Collaboration post created successfully.',
            'data' => new CollaborationPostResource($post),
        ], 201);
    }
}
