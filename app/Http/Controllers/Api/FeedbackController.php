<?php

namespace App\Http\Controllers\Api;

use App\Mail\FeedbackSubmittedMail;
use App\Models\FeedbackCategory;
use App\Models\FeedbackForm;
use App\Models\FeedbackMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

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
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'category_id' => ['required', 'uuid', 'exists:feedback_categories,id'],
            'question' => ['required', 'string'],
            'media' => ['nullable', 'array', 'max:5'],
            'media.*' => ['file', 'mimes:jpg,jpeg,png,webp,mp4,mov,avi,pdf,doc,docx', 'max:20480'],
        ]);

        $user = $request->user();
        $category = FeedbackCategory::query()->findOrFail($validated['category_id']);

        $feedbackForm = FeedbackForm::query()->create([
            'user_id' => $user?->id,
            'category_id' => $category->id,
            'category' => $category->name,
            'subject' => $validated['subject'],
            'question' => $validated['question'],
            'status' => 'submitted',
        ]);

        $mediaResponse = [];
        $uploadedMedia = $request->file('media', []);
        foreach ($uploadedMedia as $file) {
            $path = $file->store('feedback-media', 'public');
            $url = Storage::disk('public')->url($path);
            $mime = (string) $file->getClientMimeType();
            $type = str_starts_with($mime, 'image/') ? 'image' : (str_starts_with($mime, 'video/') ? 'video' : 'file');

            $media = FeedbackMedia::query()->create([
                'feedback_form_id' => $feedbackForm->id,
                'file_path' => $path,
                'file_url' => $url,
                'file_type' => $type,
                'mime_type' => $mime,
                'original_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
            ]);

            $mediaResponse[] = [
                'id' => $media->id,
                'url' => $media->file_url,
                'type' => $media->file_type,
            ];
        }

        $feedbackForm->load(['category', 'user']);

        $this->sendFeedbackEmails($feedbackForm);

        return $this->success([
            'id' => $feedbackForm->id,
            'subject' => $feedbackForm->subject,
            'category' => $feedbackForm->category,
            'question' => $feedbackForm->question,
            'status' => $feedbackForm->status,
            'media' => $mediaResponse,
            'created_at' => $feedbackForm->created_at,
        ], 'Thank you for your feedback. Our team will review it and get back to you soon.', 201);
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
