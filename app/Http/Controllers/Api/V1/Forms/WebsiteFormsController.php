<?php

namespace App\Http\Controllers\Api\V1\Forms;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Forms\SubmitBecomeSpeakerRequest;
use App\Http\Requests\Forms\SubmitEntrepreneurCertificationRequest;
use App\Http\Requests\Forms\SubmitLeadershipCertificationRequest;
use App\Http\Requests\Forms\SubmitPartnerWithUsRequest;
use App\Http\Requests\Forms\SubmitSmeBusinessStoryRequest;
use App\Mail\WebsiteFormConfirmationMail;
use App\Models\BecomeSpeakerSubmission;
use App\Models\EntrepreneurCertificationSubmission;
use App\Models\FileModel;
use App\Models\LeadershipCertificationSubmission;
use App\Models\PartnerWithUsSubmission;
use App\Models\SmeBusinessStorySubmission;
use App\Services\EmailLogs\EmailLogService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WebsiteFormsController extends BaseApiController
{
    public function indexBecomeSpeaker(Request $request)
    {
        $query = BecomeSpeakerSubmission::query();
        $this->applyCommonFilters($query, $request, ['first_name', 'last_name', 'email', 'city', 'company_name']);

        $items = $query->latest()->paginate($this->resolvePerPage($request));

        return response()->json([
            'status' => true,
            'message' => 'Submissions fetched successfully.',
            'data' => collect($items->items())->map(fn (BecomeSpeakerSubmission $item) => $this->mapSpeakerSubmission($item))->values(),
            'meta' => $this->paginationMeta($items),
        ]);
    }

    public function showBecomeSpeaker(string $id)
    {
        $item = BecomeSpeakerSubmission::find($id);

        if (! $item) {
            return $this->submissionNotFound();
        }

        return response()->json([
            'status' => true,
            'message' => 'Submission fetched successfully.',
            'data' => $this->mapSpeakerSubmission($item),
        ]);
    }

    public function indexSmeBusinessStory(Request $request)
    {
        $query = SmeBusinessStorySubmission::query();
        $this->applyCommonFilters($query, $request, ['full_name', 'email', 'business_name']);

        $items = $query->latest()->paginate($this->resolvePerPage($request));

        return response()->json([
            'status' => true,
            'message' => 'Submissions fetched successfully.',
            'data' => $items->items(),
            'meta' => $this->paginationMeta($items),
        ]);
    }

    public function showSmeBusinessStory(string $id)
    {
        $item = SmeBusinessStorySubmission::find($id);

        if (! $item) {
            return $this->submissionNotFound();
        }

        return response()->json([
            'status' => true,
            'message' => 'Submission fetched successfully.',
            'data' => $item,
        ]);
    }

    public function indexLeadershipCertification(Request $request)
    {
        $query = LeadershipCertificationSubmission::query();
        $this->applyCommonFilters($query, $request, ['full_name', 'email', 'business_name']);

        $items = $query->latest()->paginate($this->resolvePerPage($request));

        return response()->json([
            'status' => true,
            'message' => 'Submissions fetched successfully.',
            'data' => $items->items(),
            'meta' => $this->paginationMeta($items),
        ]);
    }

    public function showLeadershipCertification(string $id)
    {
        $item = LeadershipCertificationSubmission::find($id);

        if (! $item) {
            return $this->submissionNotFound();
        }

        return response()->json([
            'status' => true,
            'message' => 'Submission fetched successfully.',
            'data' => $item,
        ]);
    }

    public function indexEntrepreneurCertification(Request $request)
    {
        $query = EntrepreneurCertificationSubmission::query();
        $this->applyCommonFilters($query, $request, ['full_name', 'email', 'business_name']);

        $items = $query->latest()->paginate($this->resolvePerPage($request));

        return response()->json([
            'status' => true,
            'message' => 'Submissions fetched successfully.',
            'data' => $items->items(),
            'meta' => $this->paginationMeta($items),
        ]);
    }

    public function showEntrepreneurCertification(string $id)
    {
        $item = EntrepreneurCertificationSubmission::find($id);

        if (! $item) {
            return $this->submissionNotFound();
        }

        return response()->json([
            'status' => true,
            'message' => 'Submission fetched successfully.',
            'data' => $item,
        ]);
    }

    public function indexPartnerWithUs(Request $request)
    {
        $query = PartnerWithUsSubmission::query();
        $this->applyCommonFilters($query, $request, ['full_name', 'email_id', 'brand_or_company_name', 'city', 'industry']);

        $items = $query->latest()->paginate($this->resolvePerPage($request));

        return response()->json([
            'status' => true,
            'message' => 'Submissions fetched successfully.',
            'data' => $items->items(),
            'meta' => $this->paginationMeta($items),
        ]);
    }

    public function showPartnerWithUs(string $id)
    {
        $item = PartnerWithUsSubmission::find($id);

        if (! $item) {
            return $this->submissionNotFound();
        }

        return response()->json([
            'status' => true,
            'message' => 'Submission fetched successfully.',
            'data' => $item,
        ]);
    }

    public function submitBecomeSpeaker(SubmitBecomeSpeakerRequest $request)
    {
        $data = $request->validated();

        Log::info('Become a speaker submission started', [
            'headers' => $request->headers->all(),
            'all' => $request->all(),
            'all_files' => array_keys($request->allFiles()),
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'ip' => $request->ip(),
        ]);

        try {
            $storedImage = null;

            if ($request->hasFile('image') && $request->file('image') instanceof UploadedFile) {
                $storedImage = $this->storeWebsiteFormImage($request->file('image'));
            }

            $submission = BecomeSpeakerSubmission::create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'city' => $data['city'],
                'linkedin_profile_url' => $data['linkedin_profile_url'],
                'company_name' => $data['company_name'],
                'brief_bio' => $data['brief_bio'],
                'topics_to_speak_on' => $data['topics_to_speak_on'],
                'image_file_id' => $storedImage?->id,
                'status' => 'new',
            ]);

            $this->sendConfirmationEmail(
                email: $submission->email,
                recipientName: trim($submission->first_name . ' ' . $submission->last_name) ?: $submission->first_name,
                subject: 'Your Speaker Application Has Been Received',
                formTitle: 'Become a Speaker',
                confirmationMessage: 'Your speaker application has been received successfully.',
                relatedType: BecomeSpeakerSubmission::class,
                submissionId: (string) $submission->id,
            );

            Log::info('Become a speaker submission stored successfully', [
                'submission_id' => $submission->id,
                'image_file_id' => $storedImage?->id,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Become a Speaker form submitted successfully.',
                'data' => [
                    'id' => $submission->id,
                    'first_name' => $submission->first_name,
                    'last_name' => $submission->last_name,
                    'email' => $submission->email,
                    'phone' => $submission->phone,
                    'city' => $submission->city,
                    'linkedin_profile_url' => $submission->linkedin_profile_url,
                    'company_name' => $submission->company_name,
                    'brief_bio' => $submission->brief_bio,
                    'topics_to_speak_on' => $submission->topics_to_speak_on,
                    'image_file_id' => $submission->image_file_id,
                    'image_url' => $storedImage ? url('/api/v1/files/' . $storedImage->id) : null,
                    'created_at' => optional($submission->created_at)?->toISOString(),
                ],
            ], 201);
        } catch (\Throwable $exception) {
            Log::error('Become a speaker submission failed', [
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'ip' => $request->ip(),
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Unable to submit form right now. Please try again later.',
                'data' => null,
            ], 500);
        }
    }

    public function submitSmeBusinessStory(SubmitSmeBusinessStoryRequest $request)
    {
        $data = $request->validated();

        Log::info('SME business story submission started', [
            'email' => $data['email'] ?? null,
            'contact_number' => $data['contact_number'] ?? null,
            'ip' => $request->ip(),
        ]);

        try {
            $submission = SmeBusinessStorySubmission::create([
                'full_name' => $data['full_name'],
                'email' => $data['email'],
                'contact_number' => $data['contact_number'],
                'business_name' => $data['business_name'],
                'company_introduction' => $data['company_introduction'],
                'co_founders_and_partners_details' => $data['co_founders_and_partners_details'] ?? null,
                'status' => 'new',
            ]);

            $this->sendConfirmationEmail(
                email: $submission->email,
                recipientName: $submission->full_name,
                subject: 'Your SME Business Story Has Been Received',
                formTitle: 'Share your SME Business Story',
                confirmationMessage: 'Your SME business story has been received successfully.',
                relatedType: SmeBusinessStorySubmission::class,
                submissionId: (string) $submission->id,
            );

            Log::info('SME business story submission stored successfully', [
                'submission_id' => $submission->id,
                'email' => $submission->email,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Form submitted successfully.',
                'data' => [
                    'id' => $submission->id,
                    'full_name' => $submission->full_name,
                    'email' => $submission->email,
                    'contact_number' => $submission->contact_number,
                    'business_name' => $submission->business_name,
                    'company_introduction' => $submission->company_introduction,
                    'co_founders_and_partners_details' => $submission->co_founders_and_partners_details,
                    'created_at' => optional($submission->created_at)?->toISOString(),
                ],
            ], 201);
        } catch (\Throwable $exception) {
            Log::error('SME business story submission failed', [
                'email' => $data['email'] ?? null,
                'contact_number' => $data['contact_number'] ?? null,
                'ip' => $request->ip(),
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Unable to submit form right now. Please try again later.',
                'data' => null,
            ], 500);
        }
    }

    public function submitLeadershipCertification(SubmitLeadershipCertificationRequest $request)
    {
        $data = $request->validated();
        $scoreDetails = $this->calculateLeadershipCertificationScore($data);

        Log::info('Leadership certification submission started', [
            'email' => $data['email'] ?? null,
            'contact_no' => $data['contact_no'] ?? null,
            'ip' => $request->ip(),
        ]);

        try {
            $submission = LeadershipCertificationSubmission::create(array_merge(
                $data,
                $scoreDetails,
                ['status' => 'new']
            ));

            $this->sendConfirmationEmail(
                email: $submission->email,
                recipientName: $submission->full_name,
                subject: 'Your Leadership Certification Request Has Been Received',
                formTitle: 'Leadership Certification',
                confirmationMessage: 'Your leadership certification request has been received successfully.',
                relatedType: LeadershipCertificationSubmission::class,
                submissionId: (string) $submission->id,
            );

            Log::info('Leadership certification submission stored successfully', [
                'submission_id' => $submission->id,
                'email' => $submission->email,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Leadership certification submitted successfully.',
                'data' => [
                    'id' => $submission->id,
                    'full_name' => $submission->full_name,
                    'business_name' => $submission->business_name,
                    'email' => $submission->email,
                    'contact_no' => $submission->contact_no,
                    'total_score' => $submission->total_score,
                    'percentage' => $submission->percentage,
                    'certification_level' => $submission->certification_level,
                    'status' => $submission->status,
                    'created_at' => optional($submission->created_at)?->toISOString(),
                    'updated_at' => optional($submission->updated_at)?->toISOString(),
                ],
            ], 201);
        } catch (\Throwable $exception) {
            Log::error('Leadership certification submission failed', [
                'email' => $data['email'] ?? null,
                'contact_no' => $data['contact_no'] ?? null,
                'ip' => $request->ip(),
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Unable to submit form right now. Please try again later.',
                'data' => null,
            ], 500);
        }
    }

    public function submitEntrepreneurCertification(SubmitEntrepreneurCertificationRequest $request)
    {
        $data = $request->validated();
        $scoreDetails = $this->calculateEntrepreneurCertificationScore($data);

        Log::info('Entrepreneur certification submission started', [
            'email' => $data['email'] ?? null,
            'contact_no' => $data['contact_no'] ?? null,
            'ip' => $request->ip(),
        ]);

        try {
            $submission = EntrepreneurCertificationSubmission::create(array_merge(
                $data,
                $scoreDetails,
                ['status' => 'new']
            ));

            $this->sendConfirmationEmail(
                email: $submission->email,
                recipientName: $submission->full_name,
                subject: 'Your Entrepreneur Certification Request Has Been Received',
                formTitle: 'Entrepreneur Certification',
                confirmationMessage: 'Your entrepreneur certification request has been received successfully.',
                relatedType: EntrepreneurCertificationSubmission::class,
                submissionId: (string) $submission->id,
            );

            Log::info('Entrepreneur certification submission stored successfully', [
                'submission_id' => $submission->id,
                'email' => $submission->email,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Entrepreneur certification submitted successfully.',
                'data' => [
                    'id' => $submission->id,
                    'full_name' => $submission->full_name,
                    'business_name' => $submission->business_name,
                    'email' => $submission->email,
                    'contact_no' => $submission->contact_no,
                    'total_score' => $submission->total_score,
                    'percentage' => $submission->percentage,
                    'certification_tier' => $submission->certification_tier,
                    'status' => $submission->status,
                    'created_at' => optional($submission->created_at)?->toISOString(),
                    'updated_at' => optional($submission->updated_at)?->toISOString(),
                ],
            ], 201);
        } catch (\Throwable $exception) {
            Log::error('Entrepreneur certification submission failed', [
                'email' => $data['email'] ?? null,
                'contact_no' => $data['contact_no'] ?? null,
                'ip' => $request->ip(),
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Unable to submit form right now. Please try again later.',
                'data' => null,
            ], 500);
        }
    }

    public function submitPartnerWithUs(SubmitPartnerWithUsRequest $request)
    {
        $data = $request->validated();

        Log::info('Partner with us submission started', [
            'email_id' => $data['email_id'] ?? null,
            'mobile_number' => $data['mobile_number'] ?? null,
            'ip' => $request->ip(),
        ]);

        try {
            $submission = PartnerWithUsSubmission::create([
                'full_name' => $data['full_name'],
                'mobile_number' => $data['mobile_number'],
                'email_id' => $data['email_id'],
                'city' => $data['city'],
                'brand_or_company_name' => $data['brand_or_company_name'],
                'website_or_social_media_link' => $data['website_or_social_media_link'] ?? null,
                'industry' => $data['industry'],
                'about_your_business' => $data['about_your_business'],
                'partnership_goal' => $data['partnership_goal'],
                'why_partner_with_peers_global' => $data['why_partner_with_peers_global'],
                'status' => 'new',
            ]);

            $this->sendConfirmationEmail(
                email: $submission->email_id,
                recipientName: $submission->full_name,
                subject: 'Your Partnership Request Has Been Received',
                formTitle: 'Partner with Us',
                confirmationMessage: 'Your partnership request has been received successfully.',
                relatedType: PartnerWithUsSubmission::class,
                submissionId: (string) $submission->id,
            );

            Log::info('Partner with us submission stored successfully', [
                'submission_id' => $submission->id,
                'email_id' => $submission->email_id,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Partner with us form submitted successfully.',
                'data' => [
                    'id' => $submission->id,
                    'full_name' => $submission->full_name,
                    'mobile_number' => $submission->mobile_number,
                    'email_id' => $submission->email_id,
                    'city' => $submission->city,
                    'brand_or_company_name' => $submission->brand_or_company_name,
                    'website_or_social_media_link' => $submission->website_or_social_media_link,
                    'industry' => $submission->industry,
                    'about_your_business' => $submission->about_your_business,
                    'partnership_goal' => $submission->partnership_goal,
                    'why_partner_with_peers_global' => $submission->why_partner_with_peers_global,
                    'created_at' => optional($submission->created_at)?->toISOString(),
                ],
            ], 201);
        } catch (\Throwable $exception) {
            Log::error('Partner with us submission failed', [
                'email_id' => $data['email_id'] ?? null,
                'mobile_number' => $data['mobile_number'] ?? null,
                'ip' => $request->ip(),
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Unable to submit form right now. Please try again later.',
                'data' => null,
            ], 500);
        }
    }

    private function storeWebsiteFormImage(UploadedFile $file): FileModel
    {
        $disk = config('filesystems.default', 'public');
        $folder = 'uploads/' . now()->format('Y/m/d');
        $extension = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        $filename = (string) Str::uuid() . '.' . $extension;
        $path = $file->storeAs($folder, $filename, $disk);

        return FileModel::create([
            'uploader_user_id' => null,
            's3_key' => $path,
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
            'width' => null,
            'height' => null,
            'duration' => null,
        ]);
    }

    private function applyCommonFilters($query, Request $request, array $searchColumns): void
    {
        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($subQuery) use ($search, $searchColumns) {
                foreach ($searchColumns as $index => $column) {
                    if ($index === 0) {
                        $subQuery->where($column, 'ilike', '%' . $search . '%');
                    } else {
                        $subQuery->orWhere($column, 'ilike', '%' . $search . '%');
                    }
                }
            });
        }

        if ($status = trim((string) $request->query('status', ''))) {
            $query->where('status', $status);
        }

        if ($fromDate = $request->query('from_date')) {
            $query->whereDate('created_at', '>=', $fromDate);
        }

        if ($toDate = $request->query('to_date')) {
            $query->whereDate('created_at', '<=', $toDate);
        }
    }

    private function mapSpeakerSubmission(BecomeSpeakerSubmission $item): array
    {
        return [
            'id' => $item->id,
            'first_name' => $item->first_name,
            'last_name' => $item->last_name,
            'email' => $item->email,
            'phone' => $item->phone,
            'city' => $item->city,
            'linkedin_profile_url' => $item->linkedin_profile_url,
            'company_name' => $item->company_name,
            'brief_bio' => $item->brief_bio,
            'topics_to_speak_on' => $item->topics_to_speak_on,
            'status' => $item->status,
            'notes' => $item->notes,
            'image_file_id' => $item->image_file_id,
            'image_url' => $item->image_file_id ? url('/api/v1/files/' . $item->image_file_id) : null,
            'created_at' => optional($item->created_at)?->toISOString(),
            'updated_at' => optional($item->updated_at)?->toISOString(),
        ];
    }


    private function calculateLeadershipCertificationScore(array $data): array
    {
        $totalScore = 0;

        foreach (LeadershipCertificationSubmission::CORRECT_ANSWERS as $field => $correctAnswer) {
            if (($data[$field] ?? null) === $correctAnswer) {
                $totalScore += 4;
            }
        }

        return [
            'total_score' => $totalScore,
            'percentage' => $totalScore,
            'certification_level' => $this->resolveLeadershipCertificationLevel($totalScore),
        ];
    }

    private function resolveLeadershipCertificationLevel(int $totalScore): string
    {
        return match (true) {
            $totalScore >= 81 => 'Established Leader',
            $totalScore >= 61 => 'Growing Leader',
            $totalScore >= 40 => 'Aspiring Leader',
            default => 'Needs Improvement',
        };
    }


    private function calculateEntrepreneurCertificationScore(array $data): array
    {
        $totalScore = 0;

        foreach (EntrepreneurCertificationSubmission::CORRECT_ANSWERS as $field => $correctAnswer) {
            if (($data[$field] ?? null) === $correctAnswer) {
                $totalScore += 4;
            }
        }

        return [
            'total_score' => $totalScore,
            'percentage' => $totalScore,
            'certification_tier' => $this->resolveEntrepreneurCertificationTier($totalScore),
        ];
    }

    private function resolveEntrepreneurCertificationTier(int $totalScore): string
    {
        return match (true) {
            $totalScore >= 81 => 'Established Entrepreneur',
            $totalScore >= 61 => 'Growing Entrepreneur',
            $totalScore >= 40 => 'Aspiring Entrepreneur',
            default => 'Needs Improvement',
        };
    }

    private function resolvePerPage(Request $request): int
    {
        return min(max((int) $request->query('per_page', 15), 1), 100);
    }

    private function paginationMeta($items): array
    {
        return [
            'current_page' => $items->currentPage(),
            'last_page' => $items->lastPage(),
            'per_page' => $items->perPage(),
            'total' => $items->total(),
        ];
    }

    private function submissionNotFound()
    {
        return response()->json([
            'status' => false,
            'message' => 'Submission not found.',
            'data' => null,
        ], 404);
    }

    private function sendConfirmationEmail(
        string $email,
        string $recipientName,
        string $subject,
        string $formTitle,
        string $confirmationMessage,
        string $relatedType,
        string $submissionId,
    ): void {
        $mailable = new WebsiteFormConfirmationMail($subject, $recipientName, $formTitle, $confirmationMessage);

        try {
            Mail::to($email)->send($mailable);
            app(EmailLogService::class)->logMailableSent($mailable, [
                'to_email' => $email,
                'to_name' => $recipientName,
                'template_key' => 'website_form_confirmation',
                'source_module' => 'WebsiteForms',
                'related_type' => $relatedType,
                'related_id' => $submissionId,
                'payload' => ['form_title' => $formTitle],
            ]);
        } catch (\Throwable $exception) {
            Log::error('Website form confirmation email failed', [
                'email' => $email,
                'related_type' => $relatedType,
                'submission_id' => $submissionId,
                'error' => $exception->getMessage(),
            ]);

            app(EmailLogService::class)->logMailableFailed($mailable, [
                'to_email' => $email,
                'to_name' => $recipientName,
                'template_key' => 'website_form_confirmation',
                'source_module' => 'WebsiteForms',
                'related_type' => $relatedType,
                'related_id' => $submissionId,
                'payload' => ['form_title' => $formTitle],
            ], $exception);
        }
    }
}
