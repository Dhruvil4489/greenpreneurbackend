<?php

namespace App\Http\Controllers\Api;

use App\Mail\FeedbackSubmittedMail;
use App\Models\FeedbackCategory;
use App\Models\FeedbackForm;
use App\Models\FeedbackMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class FeedbackController extends BaseApiController
{
    public function adminIndex(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 20);

        $query = FeedbackForm::query()
            ->with([
                'user:id,display_name,first_name,last_name,email,phone',
                'media',
            ])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->string('category_id')->toString());
        }

        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->string('from_date')->toString());
        }

        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->string('to_date')->toString());
        }

        if ($request->filled('search')) {
            $search = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $request->string('search')->toString()) . '%';

            $query->where(function ($q) use ($search): void {
                $q->where('subject', 'ILIKE', $search)
                    ->orWhere('question', 'ILIKE', $search)
                    ->orWhere('category', 'ILIKE', $search)
                    ->orWhereHas('user', function ($userQuery) use ($search): void {
                        $userQuery->where('display_name', 'ILIKE', $search)
                            ->orWhere('email', 'ILIKE', $search);
                    });
            });
        }

        $feedbacks = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Feedback list fetched successfully.',
            'data' => [
                'total' => $feedbacks->total(),
                'current_page' => $feedbacks->currentPage(),
                'per_page' => $feedbacks->perPage(),
                'last_page' => $feedbacks->lastPage(),
                'items' => $feedbacks->getCollection()->map(function (FeedbackForm $feedback): array {
                    $displayName = $feedback->user?->display_name
                        ?: trim(($feedback->user?->first_name ?? '') . ' ' . ($feedback->user?->last_name ?? ''));

                    return [
                        'id' => $feedback->id,
                        'user' => $feedback->user ? [
                            'id' => $feedback->user->id,
                            'name' => $displayName !== '' ? $displayName : null,
                            'email' => $feedback->user->email,
                            'phone' => $feedback->user->phone,
                        ] : null,
                        'subject' => $feedback->subject,
                        'category_id' => $feedback->category_id,
                        'category' => $feedback->category,
                        'question' => $feedback->question,
                        'status' => $feedback->status,
                        'media' => $feedback->media->map(function (FeedbackMedia $media): array {
                            return [
                                'id' => $media->id,
                                'url' => $media->file_url,
                                'type' => $media->file_type,
                                'mime_type' => $media->mime_type,
                                'original_name' => $media->original_name,
                            ];
                        })->values()->all(),
                        'created_at' => $feedback->created_at,
                    ];
                })->values()->all(),
            ],
        ]);
    }

    public function categories(): JsonResponse
    {
        $categories = FeedbackCategory::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name']);

        return $this->success($categories, 'Feedback categories fetched successfully.');
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'category_id' => ['required', 'uuid', 'exists:feedback_categories,id'],
            'question' => ['required', 'string'],
            'media' => ['nullable', 'array', 'max:5'],
            'media.*' => ['file', 'mimes:jpg,jpeg,png,webp,mp4,mov,avi,pdf,doc,docx', 'max:20480'],
        ]);

        DB::beginTransaction();

        try {
            $category = FeedbackCategory::query()->findOrFail($request->category_id);

            $feedback = FeedbackForm::query()->create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'user_id' => auth()->id(),
                'category_id' => $category->id,
                'category' => $category->name,
                'subject' => $request->subject,
                'question' => $request->question,
                'status' => 'submitted',
            ]);

            if (! $feedback || ! $feedback->id) {
                throw new \Exception('Feedback form was not created properly.');
            }

            $mediaItems = [];

            if ($request->hasFile('media')) {
                foreach ($request->file('media') as $file) {
                    $path = $file->store('feedback-media', 'public');
                    $mimeType = (string) $file->getMimeType();

                    if (str_starts_with($mimeType, 'image/')) {
                        $fileType = 'image';
                    } elseif (str_starts_with($mimeType, 'video/')) {
                        $fileType = 'video';
                    } else {
                        $fileType = 'file';
                    }

                    $media = FeedbackMedia::query()->create([
                        'feedback_form_id' => $feedback->id,
                        'file_path' => $path,
                        'file_url' => asset('storage/' . $path),
                        'file_type' => $fileType,
                        'mime_type' => $mimeType,
                        'original_name' => $file->getClientOriginalName(),
                        'file_size' => $file->getSize(),
                    ]);

                    $mediaItems[] = $media;
                }
            }

            DB::commit();

            $feedback->load(['user']);
            $user = auth()->user();
            $this->sendFeedbackEmails($feedback, $user);

            return response()->json([
                'success' => true,
                'message' => 'Thank you for your feedback. Our team will review it and get back to you soon.',
                'data' => [
                    'id' => $feedback->id,
                    'subject' => $feedback->subject,
                    'category' => $feedback->category,
                    'question' => $feedback->question,
                    'status' => $feedback->status,
                    'media' => $mediaItems,
                    'created_at' => $feedback->created_at,
                ],
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Feedback submit failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
            ], 500);
        }
    }

    private function sendFeedbackEmails(FeedbackForm $feedbackForm, $user): void
    {
        if ($user && ! empty($user->email)) {
            try {
                Mail::send('emails.support-feedback-thank-you', [
                    'user' => $user,
                    'feedback' => $feedbackForm,
                ], function ($message) use ($user): void {
                    $message->to($user->email)
                        ->subject('Thank you for contacting Peers Global Unity');
                });
            } catch (\Throwable $e) {
                Log::error('Feedback thank-you email failed', [
                    'feedback_id' => $feedbackForm->id ?? null,
                    'user_id' => auth()->id(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $supportEmail = config('mail.support_email') ?: env('SUPPORT_EMAIL') ?: config('mail.from.address');

        if ($supportEmail) {
            try {
                Mail::to($supportEmail)->send(new FeedbackSubmittedMail($feedbackForm));
            } catch (\Throwable $e) {
                Log::error('Failed to send feedback admin notification email.', [
                    'feedback_form_id' => $feedbackForm->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }
}
