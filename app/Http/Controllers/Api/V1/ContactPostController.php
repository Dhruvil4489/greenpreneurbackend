<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ContactPost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
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
        $validated = $this->validatedPayload($request);

        if ($validated instanceof JsonResponse) {
            return $validated;
        }

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
        $validated = $this->validatedPayload($request, true);

        if ($validated instanceof JsonResponse) {
            return $validated;
        }

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

    private function validatedPayload(Request $request, bool $isUpdate = false): array|JsonResponse
    {
        $validator = Validator::make($request->all(), $this->rules($isUpdate));

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'status' => false,
                'message' => 'Validation failed',
                'data' => $validator->errors(),
                'errors' => $validator->errors(),
            ], 422);
        }

        return $validator->validated();
    }

    private function rules(bool $isUpdate = false): array
    {
        return [
            'full_name' => $isUpdate
                ? ['sometimes', 'required', 'string', 'max:255']
                : ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'nickname' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'emails' => ['nullable', 'array'],
            'emails.*' => ['nullable', 'email', 'max:255'],
            'phones' => ['nullable', 'array'],
            'phones.*' => ['nullable', 'string', 'max:30'],
            'addresses' => ['nullable', 'array'],
            'addresses.*.street' => ['nullable', 'string', 'max:255'],
            'addresses.*.city' => ['nullable', 'string', 'max:255'],
            'addresses.*.state' => ['nullable', 'string', 'max:255'],
            'addresses.*.postalCode' => ['nullable', 'string', 'max:50'],
            'addresses.*.country' => ['nullable', 'string', 'max:255'],
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
            'phone' => $contactPost->phone,
            'first_name' => $contactPost->first_name,
            'middle_name' => $contactPost->middle_name,
            'last_name' => $contactPost->last_name,
            'nickname' => $contactPost->nickname,
            'email' => $contactPost->email,
            'company' => $contactPost->company,
            'job_title' => $contactPost->job_title,
            'notes' => $contactPost->notes,
            'emails' => $contactPost->emails ?? [],
            'phones' => $contactPost->phones ?? [],
            'addresses' => $contactPost->addresses ?? [],
            'created_at' => optional($contactPost->created_at)->toJSON(),
            'updated_at' => optional($contactPost->updated_at)->toJSON(),
        ];
    }

    private function exceptionResponse(string $message, Throwable $exception): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 500);
    }
}
