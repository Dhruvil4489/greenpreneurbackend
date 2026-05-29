<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ContactPost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ContactPostController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

        $query = ContactPost::query()->latest('created_at');

        $user = $this->authenticatedUser($request);

        if ($user) {
            $query->where('user_id', $user->id);
        }

        $contactPosts = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Contact posts fetched successfully.',
            'data' => [
                'items' => collect($contactPosts->items())
                    ->map(fn (ContactPost $contactPost): array => $this->formatContactPost($contactPost))
                    ->values(),
                'pagination' => [
                    'current_page' => $contactPosts->currentPage(),
                    'per_page' => $contactPosts->perPage(),
                    'total' => $contactPosts->total(),
                    'last_page' => $contactPosts->lastPage(),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());

        try {
            $validated['user_id'] = optional($this->authenticatedUser($request))->id;

            $contactPost = ContactPost::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'Contact post created successfully.',
                'data' => $this->formatContactPost($contactPost),
            ], 201);
        } catch (Throwable $exception) {
            Log::error('Contact post creation failed.', [
                'user_id' => optional($this->authenticatedUser($request))->id,
                'exception' => $exception,
            ]);

            return $this->exceptionResponse('Something went wrong while creating contact post.', $exception);
        }
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $contactPost = $this->findContactPost($request, $id);

        if (! $contactPost) {
            return response()->json([
                'success' => false,
                'message' => 'Contact post not found.',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Contact post fetched successfully.',
            'data' => $this->formatContactPost($contactPost),
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate($this->rules(true));

        try {
            $contactPost = $this->findContactPost($request, $id);

            if (! $contactPost) {
                return response()->json([
                    'success' => false,
                    'message' => 'Contact post not found.',
                    'data' => null,
                ], 404);
            }

            $contactPost->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Contact post updated successfully.',
                'data' => $this->formatContactPost($contactPost->fresh()),
            ]);
        } catch (Throwable $exception) {
            Log::error('Contact post update failed.', [
                'contact_post_id' => $id,
                'user_id' => optional($this->authenticatedUser($request))->id,
                'exception' => $exception,
            ]);

            return $this->exceptionResponse('Something went wrong while updating contact post.', $exception);
        }
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $contactPost = $this->findContactPost($request, $id);

            if (! $contactPost) {
                return response()->json([
                    'success' => false,
                    'message' => 'Contact post not found.',
                    'data' => null,
                ], 404);
            }

            $contactPost->delete();

            return response()->json([
                'success' => true,
                'message' => 'Contact post deleted successfully.',
                'data' => null,
            ]);
        } catch (Throwable $exception) {
            Log::error('Contact post deletion failed.', [
                'contact_post_id' => $id,
                'user_id' => optional($this->authenticatedUser($request))->id,
                'exception' => $exception,
            ]);

            return $this->exceptionResponse('Something went wrong while deleting contact post.', $exception);
        }
    }

    private function rules(bool $isUpdate = false): array
    {
        return [
            'full_name' => $isUpdate
                ? ['sometimes', 'required', 'string', 'max:255']
                : ['required', 'string', 'max:255'],
            'phonetic_name' => ['nullable', 'string', 'max:255'],
            'mobile_number' => ['nullable', 'string', 'max:30'],
            'alternate_mobile_number' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'im' => ['nullable', 'string'],
            'contact_date' => ['nullable', 'date'],
            'related_persons' => ['nullable', 'array'],
            'related_persons.*.name' => ['nullable', 'string', 'max:255'],
            'related_persons.*.relation' => ['nullable', 'string', 'max:255'],
            'related_persons.*.phone' => ['nullable', 'string', 'max:30'],
            'nickname' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'source_accounts' => ['nullable', 'array'],
            'source_accounts.*.account_type' => ['nullable', 'string', 'max:100'],
            'source_accounts.*.account_name' => ['nullable', 'string', 'max:255'],
            'source_accounts.*.contact_count' => ['nullable', 'integer', 'min:0'],
            'follow_system' => ['nullable', 'boolean'],
        ];
    }

    private function findContactPost(Request $request, string $id): ?ContactPost
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        return $this->queryForUser($request)
            ->where('id', $id)
            ->first();
    }

    private function authenticatedUser(Request $request)
    {
        return $request->user('sanctum') ?: $request->user();
    }

    private function queryForUser(Request $request)
    {
        $query = ContactPost::query();

        $user = $this->authenticatedUser($request);

        if ($user) {
            $query->where('user_id', $user->id);
        }

        return $query;
    }

    private function formatContactPost(?ContactPost $contactPost): ?array
    {
        if (! $contactPost) {
            return null;
        }

        return [
            'id' => $contactPost->id,
            'user_id' => $contactPost->user_id,
            'full_name' => $contactPost->full_name,
            'phonetic_name' => $contactPost->phonetic_name,
            'mobile_number' => $contactPost->mobile_number,
            'alternate_mobile_number' => $contactPost->alternate_mobile_number,
            'email' => $contactPost->email,
            'company' => $contactPost->company,
            'job_title' => $contactPost->job_title,
            'address' => $contactPost->address,
            'im' => $contactPost->im,
            'contact_date' => optional($contactPost->contact_date)->toDateString(),
            'related_persons' => $contactPost->related_persons,
            'nickname' => $contactPost->nickname,
            'website' => $contactPost->website,
            'notes' => $contactPost->notes,
            'source_accounts' => $contactPost->source_accounts,
            'follow_system' => $contactPost->follow_system,
            'created_at' => optional($contactPost->created_at)->toJSON(),
            'updated_at' => optional($contactPost->updated_at)->toJSON(),
        ];
    }

    private function exceptionResponse(string $message, Throwable $exception): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if (App::environment('local')) {
            $payload['error'] = $exception->getMessage();
        }

        return response()->json($payload, 500);
    }
}
