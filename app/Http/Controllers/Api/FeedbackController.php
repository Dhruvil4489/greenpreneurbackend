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

            $feedback->load(['category', 'user']);
            $this->sendFeedbackEmails($feedback);

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

    private function sendFeedbackEmails(FeedbackForm $feedbackForm): void
    {
        if ($feedbackForm->user?->email) {
            try {
                Mail::to($feedbackForm->user->email)->send(new FeedbackSubmittedMail($feedbackForm));
            } catch (\Throwable $e) {
                Log::error('Failed to send feedback thank-you email.', [
                    'feedback_form_id' => $feedbackForm->id,
                    'message' => $e->getMessage(),
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
