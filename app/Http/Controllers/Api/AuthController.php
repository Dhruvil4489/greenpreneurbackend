<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Mail\LoginOtpMail;
use App\Mail\PasswordResetOtpMail;
use App\Mail\WelcomePeerMail;
use App\Exceptions\MediaProcessingException;
use App\Models\EmailLog;
use App\Models\FileModel;

use App\Models\CircleCategoryLevel3;
use App\Models\CircleCategoryLevel4;
use App\Models\CircleCategoryLevel2;
use App\Models\CircleMember;
use App\Models\JoinedCircleCategory;

use App\Models\OtpCode;
use App\Models\ReferralData;
use App\Models\User;
use App\Models\UserLoginHistory;
use App\Services\EmailLogs\EmailLogService;
use App\Services\OnlineStatusService;
use App\Services\Referrals\ReferralService;
use App\Services\Media\FileUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AuthController extends BaseApiController
{
    public function register(RegisterRequest $request, ReferralService $referralService, FileUploadService $fileUploadService)
    {
        $data = $request->validated();
        $data = $this->resolveRegisterCategoryPath($data);

        if ($businessCategoryError = $this->validateActiveRegisterBusinessCategory($data)) {
            return $businessCategoryError;
        }

        $incomingReferralCode = $data['referral_code']
            ?? $request->input('referral_code')
            ?? $request->input('referralCode');

        $normalizedReferralCode = filled($incomingReferralCode)
            ? strtoupper(trim((string) $incomingReferralCode))
            : null;

        Log::info('auth.register.before_user_creation', [
            'email' => (string) ($data['email'] ?? ''),
            'first_name' => (string) ($data['first_name'] ?? ''),
            'last_name' => (string) ($data['last_name'] ?? ''),
            'phone' => (string) ($data['phone'] ?? ''),
            'display_name' => trim((string) (($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''))),
            'has_referral_code' => filled($normalizedReferralCode),
            'referral_code' => (string) ($normalizedReferralCode ?? ''),
        ]);

        $referralPreview = $referralService->validateReferralCodeOrFail($normalizedReferralCode);
        $data['resolved_referred_by_user_id'] = $this->resolveRegisterReferrerUserId($data, $referralPreview);

        $profilePhotoFile = $this->storeRegisterProfilePhoto($request, $fileUploadService);
        if ($profilePhotoFile) {
            $data['profile_photo_file_id'] = (string) $profilePhotoFile->id;
        }

        try {
            $transactionUser = $this->createRegisteredUser($data);
        } catch (\Throwable $e) {
            if ($profilePhotoFile) {
                $fileUploadService->delete($profilePhotoFile);
            }

            throw $e;
        }

        if (! $transactionUser->exists || blank($transactionUser->id)) {
            throw new \RuntimeException('Registration failed: user model was not persisted.');
        }

        $persistedUser = User::query()
            ->useWritePdo()
            ->find((string) $transactionUser->id);

        if (! $persistedUser) {
            Log::error('auth.register.user_not_found_on_write_connection', [
                'user_id' => (string) $transactionUser->id,
                'email' => (string) ($data['email'] ?? ''),
            ]);

            throw new \RuntimeException('Registration failed: persisted user row was not found in users table.');
        }

        Log::info('auth.register.database_existence_check', [
            'user_id' => (string) $persistedUser->id,
            'email' => (string) $persistedUser->email,
            'exists' => true,
        ]);

        if ($profilePhotoFile) {
            $profilePhotoFile->uploader_user_id = (string) $persistedUser->id;
            $profilePhotoFile->save();

            Log::info('auth.register.profile_photo_saved', [
                'user_id' => (string) $persistedUser->id,
                'file_id' => (string) $profilePhotoFile->id,
            ]);
        }

        $circleMember = $this->attachOptionalCircleMembership($persistedUser, $data);
        $this->persistOptionalJoinedCategories($persistedUser, $data, $circleMember);

        $referralMeta = null;

        if (filled($normalizedReferralCode)) {
            Log::info('auth.register.before_referral_apply', [
                'user_id' => (string) $persistedUser->id,
                'referral_code' => (string) $normalizedReferralCode,
            ]);

            $referralMeta = $referralService->applyReferralOnRegistration($persistedUser, (string) $normalizedReferralCode);

            Log::info('auth.register.after_referral_apply', [
                'user_id' => (string) $persistedUser->id,
                'referral_code' => (string) $normalizedReferralCode,
                'referrer_user_id' => (string) ($referralMeta['referrer_user_id'] ?? ''),
                'reward_status' => (string) ($referralMeta['reward_status'] ?? ''),
            ]);

            $this->ensureReferralDataPersisted($persistedUser, $normalizedReferralCode, $referralMeta);
            $this->persistRegisterReferrer($persistedUser, (string) ($referralMeta['referrer_user_id'] ?? ''));
        } elseif (! blank($data['resolved_referred_by_user_id'] ?? null)) {
            $this->persistRegisterReferrer($persistedUser, (string) $data['resolved_referred_by_user_id']);
        }

        $persistedUser->refresh();

        Log::info('auth.register.before_token_creation', [
            'user_id' => (string) $persistedUser->id,
            'email' => (string) $persistedUser->email,
        ]);

        $this->sendRegistrationRequestReceivedEmail($persistedUser);

        $token = $persistedUser->createToken('auth_token')->plainTextToken;

        Log::info('auth.register.before_response', [
            'user_id' => (string) $persistedUser->id,
            'email' => (string) $persistedUser->email,
            'has_referral_meta' => $referralMeta !== null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Registration successful.',
            'data'    => [
                'token' => $token,
                'user'  => $this->buildRegisterUserPayload($persistedUser),
                'referral' => $referralMeta,
                'categories' => $this->buildJoinedCategoriesPayload($persistedUser),
            ],
        ], 201);
    }

    private function attachOptionalCircleMembership(User $user, array $data): ?CircleMember
    {
        $circleId = (string) ($data['circle_id'] ?? '');
        if ($circleId === '') {
            return null;
        }

        $attributes = [
            'role' => 'member',
            'status' => (string) config('circle.member_joined_status', 'approved'),
            'left_at' => null,
        ];

        if (Schema::hasColumn('circle_members', 'joined_at')) {
            $attributes['joined_at'] = now();
        }

        $member = CircleMember::query()->withTrashed()->firstOrNew([
            'user_id' => (string) $user->id,
            'circle_id' => $circleId,
        ]);

        if ($member->trashed()) {
            $member->deleted_at = null;
        }

        $member->fill($attributes);
        $member->save();

        if (Schema::hasColumn('users', 'active_circle_id')) {
            $user->active_circle_id = $circleId;
            $user->save();
        }

        return $member;
    }

    private function persistOptionalJoinedCategories(User $user, array $data, ?CircleMember $circleMember): void
    {
        if (! $circleMember) {
            return;
        }

        $circleId = (string) ($circleMember->circle_id ?? '');
        if ($circleId === '') {
            return;
        }

        $level1CategoryId = (int) ($data['level_1_category_id'] ?? 0);
        $level2CategoryId = (int) ($data['level_2_category_id'] ?? 0);
        $level3CategoryId = (int) ($data['level_3_category_id'] ?? 0);
        $level4CategoryId = (int) ($data['level_4_category_id'] ?? 0);

        if ($level1CategoryId <= 0 && $level2CategoryId <= 0 && $level3CategoryId <= 0 && $level4CategoryId <= 0) {
            return;
        }

        $this->assertCircleCategoryMapping($circleId, $level1CategoryId);

        if (Schema::hasColumn('circle_members', 'level_1_category_id')) {
            $circleMember->level_1_category_id = $level1CategoryId > 0 ? $level1CategoryId : null;
        }
        if (Schema::hasColumn('circle_members', 'level_2_category_id')) {
            $circleMember->level_2_category_id = $level2CategoryId > 0 ? $level2CategoryId : null;
        }
        if (Schema::hasColumn('circle_members', 'level_3_category_id')) {
            $circleMember->level_3_category_id = $level3CategoryId > 0 ? $level3CategoryId : null;
        }
        if (Schema::hasColumn('circle_members', 'level_4_category_id')) {
            $circleMember->level_4_category_id = $level4CategoryId > 0 ? $level4CategoryId : null;
        }
        $circleMember->save();

        if (! Schema::hasTable('joined_circle_categories')) {
            return;
        }

        try {
            JoinedCircleCategory::query()->updateOrCreate(
                [
                    'user_id' => (string) $user->id,
                    'circle_id' => $circleId,
                ],
                [
                    'circle_member_id' => $circleMember->id,
                    'level1_category_id' => $level1CategoryId > 0 ? $level1CategoryId : null,
                    'level2_category_id' => $level2CategoryId > 0 ? $level2CategoryId : null,
                    'level3_category_id' => $level3CategoryId > 0 ? $level3CategoryId : null,
                    'level4_category_id' => $level4CategoryId > 0 ? $level4CategoryId : null,
                ]
            );
        } catch (\Throwable $exception) {
            Log::warning('auth.register.joined_circle_categories_persist_failed', [
                'user_id' => (string) $user->id,
                'level1_category_id' => $level1CategoryId > 0 ? $level1CategoryId : null,
                'level2_category_id' => $level2CategoryId > 0 ? $level2CategoryId : null,
                'level3_category_id' => $level3CategoryId > 0 ? $level3CategoryId : null,
                'level4_category_id' => $level4CategoryId > 0 ? $level4CategoryId : null,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function resolveRegisterCategoryPath(array $data): array
    {
        $level1CategoryId = (int) ($data['level_1_category_id'] ?? 0);
        $level2CategoryId = (int) ($data['level_2_category_id'] ?? 0);
        $level3CategoryId = (int) ($data['level_3_category_id'] ?? 0);
        $level4CategoryId = (int) ($data['level_4_category_id'] ?? 0);

        if ($level4CategoryId > 0) {
            $level4 = CircleCategoryLevel4::query()
                ->select(['id', 'level3_id', 'level2_id', 'circle_category_id'])
                ->find($level4CategoryId);

            if ($level4) {
                if ($level3CategoryId <= 0 && (int) $level4->level3_id > 0) {
                    $level3CategoryId = (int) $level4->level3_id;
                }
                if ($level2CategoryId <= 0 && (int) $level4->level2_id > 0) {
                    $level2CategoryId = (int) $level4->level2_id;
                }
                if ($level1CategoryId <= 0 && (int) $level4->circle_category_id > 0) {
                    $level1CategoryId = (int) $level4->circle_category_id;
                }
            }
        }

        if ($level3CategoryId > 0) {
            $level3 = CircleCategoryLevel3::query()
                ->select(['id', 'level2_id', 'circle_category_id'])
                ->find($level3CategoryId);
            if ($level3) {
                if ($level2CategoryId <= 0 && (int) $level3->level2_id > 0) {
                    $level2CategoryId = (int) $level3->level2_id;
                }
                if ($level1CategoryId <= 0 && (int) $level3->circle_category_id > 0) {
                    $level1CategoryId = (int) $level3->circle_category_id;
                }
            }
        }

        if ($level2CategoryId > 0) {
            $level2 = CircleCategoryLevel2::query()
                ->select(['id', 'circle_category_id'])
                ->find($level2CategoryId);
            if ($level2 && $level1CategoryId <= 0 && (int) $level2->circle_category_id > 0) {
                $level1CategoryId = (int) $level2->circle_category_id;
            }
        }

        $data['level_1_category_id'] = $level1CategoryId > 0 ? $level1CategoryId : null;
        $data['level_2_category_id'] = $level2CategoryId > 0 ? $level2CategoryId : null;
        $data['level_3_category_id'] = $level3CategoryId > 0 ? $level3CategoryId : null;
        $data['level_4_category_id'] = $level4CategoryId > 0 ? $level4CategoryId : null;

        $this->assertValidCategoryHierarchy($data);

        return $data;
    }

    private function assertValidCategoryHierarchy(array $data): void
    {
        $level1CategoryId = (int) ($data['level_1_category_id'] ?? 0);
        $level2CategoryId = (int) ($data['level_2_category_id'] ?? 0);
        $level3CategoryId = (int) ($data['level_3_category_id'] ?? 0);
        $level4CategoryId = (int) ($data['level_4_category_id'] ?? 0);

        if ($level2CategoryId > 0) {
            $level2 = CircleCategoryLevel2::query()->find($level2CategoryId);
            if (! $level2 || ($level1CategoryId > 0 && (int) $level2->circle_category_id !== $level1CategoryId)) {
                throw ValidationException::withMessages([
                    'level_2_category_id' => 'Selected Level 2 category does not belong to the selected Level 1 category.',
                ]);
            }
        }

        if ($level3CategoryId > 0) {
            $level3 = CircleCategoryLevel3::query()->find($level3CategoryId);
            if (! $level3 || ($level2CategoryId > 0 && (int) $level3->level2_id !== $level2CategoryId)) {
                throw ValidationException::withMessages([
                    'level_3_category_id' => 'Selected Level 3 category does not belong to the selected Level 2 category.',
                ]);
            }
        }

        if ($level4CategoryId > 0) {
            $level4 = CircleCategoryLevel4::query()->find($level4CategoryId);
            if (! $level4 || ($level3CategoryId > 0 && (int) $level4->level3_id !== $level3CategoryId)) {
                throw ValidationException::withMessages([
                    'level_4_category_id' => 'Selected Level 4 category does not belong to the selected Level 3 category.',
                ]);
            }
        }
    }

    private function assertCircleCategoryMapping(string $circleId, int $level1CategoryId): void
    {
        if ($circleId === '' || $level1CategoryId <= 0 || ! Schema::hasTable('circle_category_mappings')) {
            return;
        }

        $isMapped = DB::table('circle_category_mappings')
            ->where('circle_id', $circleId)
            ->where('category_id', $level1CategoryId)
            ->exists();

        if (! $isMapped) {
            throw ValidationException::withMessages([
                'level_1_category_id' => 'Selected category does not belong to the selected circle.',
            ]);
        }
    }

    private function buildJoinedCategoriesPayload(User $user): array
    {
        $joinedStatus = (string) config('circle.member_joined_status', 'approved');

        $memberships = CircleMember::query()
            ->where('user_id', (string) $user->id)
            ->where('status', $joinedStatus)
            ->whereNull('deleted_at')
            ->whereNull('left_at')
            ->with([
                'circle:id,name',
                'level1Category:id,name',
                'level2Category:id,name',
                'level3Category:id,name',
                'level4Category:id,name',
            ])
            ->orderByDesc('joined_at')
            ->get();

        if ($memberships->isNotEmpty()) {
            return $memberships
                ->map(function (CircleMember $membership): array {
                    return [
                        'circle_id' => $membership->circle_id,
                        'circle_name' => $membership->circle?->name,
                        'level1_category' => $membership->level1Category
                            ? ['id' => $membership->level1Category->id, 'name' => $membership->level1Category->name]
                            : null,
                        'level2_category' => $membership->level2Category
                            ? ['id' => $membership->level2Category->id, 'name' => $membership->level2Category->name]
                            : null,
                        'level3_category' => $membership->level3Category
                            ? ['id' => $membership->level3Category->id, 'name' => $membership->level3Category->name]
                            : null,
                        'level4_category' => $membership->level4Category
                            ? ['id' => $membership->level4Category->id, 'name' => $membership->level4Category->name]
                            : null,
                    ];
                })
                ->values()
                ->all();
        }

        if (! Schema::hasTable('joined_circle_categories')) {
            return [];
        }

        return JoinedCircleCategory::query()
            ->where('user_id', (string) $user->id)
            ->with([
                'circle:id,name',
                'level1Category:id,name',
                'level2Category:id,name',
                'level3Category:id,name',
                'level4Category:id,name',
            ])
            ->orderByDesc('updated_at')
            ->get()
            ->map(function (JoinedCircleCategory $row): array {
                return [
                    'circle_id' => $row->circle_id,
                    'circle_name' => $row->circle?->name,
                    'level1_category' => $row->level1Category
                        ? ['id' => $row->level1Category->id, 'name' => $row->level1Category->name]
                        : null,
                    'level2_category' => $row->level2Category
                        ? ['id' => $row->level2Category->id, 'name' => $row->level2Category->name]
                        : null,
                    'level3_category' => $row->level3Category
                        ? ['id' => $row->level3Category->id, 'name' => $row->level3Category->name]
                        : null,
                    'level4_category' => $row->level4Category
                        ? ['id' => $row->level4Category->id, 'name' => $row->level4Category->name]
                        : null,
                ];
            })
            ->values()
            ->all();
    }

    private function ensureReferralDataPersisted(User $user, string $referralCode, array $referralMeta): void
    {
        $alreadyExists = ReferralData::query()
            ->where('referred_user_id', (string) $user->id)
            ->exists();

        if ($alreadyExists) {
            Log::info('auth.register.referraldata_duplicate_skip', [
                'referred_user_id' => (string) $user->id,
                'referral_code' => $referralCode,
            ]);

            return;
        }

        $insertPayload = [
            'referrer_user_id' => (string) ($referralMeta['referrer_user_id'] ?? ''),
            'referred_user_id' => (string) $user->id,
            'referral_code' => $referralCode,
            'referrer_email' => (string) ($referralMeta['referrer_email'] ?? ''),
            'coins' => (int) ($referralMeta['coins'] ?? (int) config('coins.activity_rewards.referral_signup', 100)),
            'reward_status' => (string) ($referralMeta['reward_status'] ?? 'granted'),
            'used_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        Log::info('auth.register.referraldata_insert_start', [
            'payload' => $insertPayload,
        ]);

        $data = ReferralData::query()->create($insertPayload);

        if (! $data->exists || ! $data->id) {
            Log::error('auth.register.referraldata_insert_failed', [
                'referred_user_id' => (string) $user->id,
                'referral_code' => $referralCode,
            ]);

            throw new \RuntimeException('Referral registration failed: unable to persist referraldata row.');
        }

        Log::info('auth.register.referraldata_insert_success', [
            'referral_data_id' => (int) $data->id,
            'referred_user_id' => (string) $user->id,
            'referral_code' => $referralCode,
        ]);
    }


    private function validateActiveRegisterBusinessCategory(array $data): ?JsonResponse
    {
        $businessCategoryId = $data['business_category_id'] ?? null;

        if (blank($businessCategoryId)) {
            return null;
        }

        $level4Table = $this->level4CategoriesTable();

        $query = DB::table($level4Table)
            ->where('id', (int) $businessCategoryId);

        if (Schema::hasColumn($level4Table, 'is_active')) {
            $query->where('is_active', true);
        }

        if ($query->first()) {
            return null;
        }

        $message = 'The selected business category must be a valid active Level 4 category.';

        return response()->json([
            'status' => false,
            'message' => $message,
            'errors' => [
                'business_category_id' => [$message],
            ],
            'data' => null,
            'meta' => null,
        ], 422);
    }

    private function level4CategoriesTable(): string
    {
        foreach (['level4_categories', 'circle_category_level4'] as $table) {
            if (Schema::hasTable($table)) {
                return $table;
            }
        }

        return 'level4_categories';
    }


    private function resolveRegisterReferrerUserId(array $data, ?array $referralPreview): ?string
    {
        $referrerUserId = (string) ($referralPreview['referrer_user_id'] ?? '');
        if ($referrerUserId !== '') {
            return $referrerUserId;
        }

        $explicitUserId = (string) ($data['referred_by_user_id'] ?? '');

        return $explicitUserId !== '' ? $explicitUserId : null;
    }

    private function persistRegisterReferrer(User $user, ?string $referrerUserId): void
    {
        $referrerUserId = trim((string) $referrerUserId);

        if ($referrerUserId === '' || $referrerUserId === (string) $user->id) {
            return;
        }

        $dirty = false;

        if (Schema::hasColumn('users', 'introduced_by') && blank($user->introduced_by)) {
            $user->introduced_by = $referrerUserId;
            $dirty = true;
        }

        if (Schema::hasColumn('users', 'referred_by_user_id') && blank($user->referred_by_user_id)) {
            $user->referred_by_user_id = $referrerUserId;
            $dirty = true;
        }

        if ($dirty) {
            $user->save();
        }
    }

    private function buildRegisterUserPayload(User $user): array
    {
        $user->loadMissing(['mainBusinessCategory:id,name', 'businessCategory:id,name', 'introducedBy:id,first_name,last_name,display_name']);

        if (Schema::hasColumn('users', 'referred_by_user_id') && method_exists($user, 'referredByUser')) {
            $user->loadMissing(['referredByUser:id,first_name,last_name,display_name']);
        }

        $payload = $user->toArray();
        $mainBusinessCategory = $user->mainBusinessCategory;
        $businessCategory = $user->businessCategory;
        $referrer = $user->referredByUser ?? $user->introducedBy;

        $payload['main_business_category'] = $mainBusinessCategory
            ? [
                'id' => (int) $mainBusinessCategory->id,
                'name' => (string) $mainBusinessCategory->name,
            ]
            : null;
        $payload['business_category'] = $businessCategory
            ? [
                'id' => (int) $businessCategory->id,
                'name' => (string) $businessCategory->name,
            ]
            : null;
        $profilePhotoId = $user->profile_photo_file_id ?? $user->profile_photo_id ?? null;
        $storedProfilePhotoPath = $user->getRawOriginal('profile_photo_url');
        $payload['profile_photo_id'] = $profilePhotoId;
        $payload['profile_photo_url'] = $profilePhotoId
            ? url('/api/v1/files/' . $profilePhotoId)
            : ($storedProfilePhotoPath ? $this->resolvePublicDiskUrl($storedProfilePhotoPath) : $user->profile_photo_url);
        $payload['city_of_residence'] = $user->getAttribute('city_of_residence');
        $payload['referred_by'] = $referrer
            ? [
                'id' => (string) $referrer->id,
                'display_name' => trim((string) (($referrer->display_name ?: '') ?: (($referrer->first_name ?? '') . ' ' . ($referrer->last_name ?? '')))),
            ]
            : null;

        return $payload;
    }


    private function sendRegistrationRequestReceivedEmail(User $user): void
    {
        $mailable = new \App\Mail\RegistrationRequestReceivedMail($user);

        try {
            Mail::to($user->email)->send($mailable);

            app(EmailLogService::class)->logMailableSent($mailable, [
                'user_id' => (string) $user->id,
                'to_email' => (string) $user->email,
                'to_name' => (string) ($user->display_name ?: trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))),
                'template_key' => 'registration_request_received',
                'source_module' => 'Auth',
                'related_type' => User::class,
                'related_id' => (string) $user->id,
                'payload' => [
                    'purpose' => 'registration_review',
                ],
            ]);
        } catch (\Throwable $exception) {
            Log::error('Registration request received mail failed', [
                'user_id' => $user->id ?? null,
                'email' => $user->email ?? null,
                'error' => $exception->getMessage(),
            ]);

            try {
                app(EmailLogService::class)->logMailableFailed($mailable, [
                    'user_id' => (string) $user->id,
                    'to_email' => (string) $user->email,
                    'to_name' => (string) ($user->display_name ?: trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))),
                    'template_key' => 'registration_request_received',
                    'source_module' => 'Auth',
                    'related_type' => User::class,
                    'related_id' => (string) $user->id,
                    'payload' => [
                        'purpose' => 'registration_review',
                    ],
                ], $exception);
            } catch (\Throwable) {
                // Registration must not fail due to log persistence errors.
            }
        }
    }

    private function sendWelcomePeerEmail(User $user): void
    {
        if (EmailLog::query()
            ->where('to_email', (string) $user->email)
            ->where('user_id', (string) $user->id)
            ->where('template_key', 'welcome_peer')
            ->exists()) {
            return;
        }

        try {
            Mail::to($user->email)->send(new WelcomePeerMail($user));

            EmailLog::query()->create([
                'to_email' => (string) $user->email,
                'template_key' => 'welcome_peer',
                'payload' => [
                    'flow' => 'registration',
                    'mailable_class' => WelcomePeerMail::class,
                ],
                'status' => 'sent',
                'sent_at' => now(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Welcome peer mail failed', [
                'user_id' => $user->id ?? null,
                'email' => $user->email ?? null,
                'error' => $e->getMessage(),
            ]);

            try {
                EmailLog::query()->create([
                    'to_email' => (string) ($user->email ?? ''),
                    'template_key' => 'welcome_peer',
                    'payload' => [
                        'flow' => 'registration',
                        'mailable_class' => WelcomePeerMail::class,
                        'error' => $e->getMessage(),
                    ],
                    'status' => 'failed',
                    'sent_at' => now(),
                    'created_at' => now(),
                ]);
            } catch (\Throwable) {
                // Registration must not fail due to mail/log persistence errors.
            }
        }
    }

    private function createRegisteredUser(array $data): User
    {
        // Build a display name from first + last name unless the client sent one explicitly.
        $displayName = trim((string) ($data['display_name'] ?? ''));
        if ($displayName === '') {
            $displayName = trim($data['first_name'] . ' ' . ($data['last_name'] ?? ''));
        }

        $user = new User();
        $user->id = (string) Str::uuid();
        $user->first_name = $data['first_name'];
        $user->last_name = $data['last_name'] ?? null;
        $user->display_name = $displayName;
        $user->email = $data['email'];
        $user->phone = $data['phone'] ?? null;
        $user->company_name = $data['company_name'] ?? null;
        $user->designation = $data['designation'] ?? null;
        $user->city_id = $data['city_id'] ?? null;

        $this->fillIfUserColumnExists($user, 'profile_photo_file_id', $data['profile_photo_file_id'] ?? null);
        $this->fillIfUserColumnExists($user, 'profile_photo_id', $data['profile_photo_file_id'] ?? null);
        $this->fillIfUserColumnExists($user, 'city', $data['city'] ?? null);
        $this->fillIfUserColumnExists($user, 'state', $data['state'] ?? null);
        $this->fillIfUserColumnExists($user, 'district', $data['district'] ?? null);
        $this->fillIfUserColumnExists($user, 'country', $data['country'] ?? null);
        $this->fillIfUserColumnExists($user, 'business_website', $data['business_website'] ?? null);
        $this->fillIfUserColumnExists($user, 'business_description', $data['business_description'] ?? null);
        $this->fillIfUserColumnExists($user, 'business_address', $data['company_address'] ?? null);
        $this->fillIfUserColumnExists($user, 'secondary_mobile', $data['whatsapp_number'] ?? null);
        $this->fillIfUserColumnExists($user, 'linkedin_profile', $data['linkedin_url'] ?? null);
        $this->fillIfUserColumnExists($user, 'instagram_handle', $data['instagram_url'] ?? null);
        $this->fillIfUserColumnExists($user, 'facebook_profile', $data['facebook_url'] ?? null);
        
        $this->fillIfUserColumnExists($user, 'website', $data['website'] ?? null);
        $this->fillIfUserColumnExists($user, 'sustainability_contribution', $data['sustainability_contribution'] ?? null);
        $this->fillIfUserColumnExists($user, 'sustainability_areas', $data['sustainability_areas'] ?? []);
        $this->fillIfUserColumnExists($user, 'greenpreneur_goals', $data['greenpreneur_goals'] ?? []);
        $this->fillIfUserColumnExists($user, 'interests', $data['interests'] ?? []);
        $this->fillIfUserColumnExists($user, 'community_directory_listing', $data['community_directory_listing'] ?? 'No');

        if (Schema::hasColumn('users', 'city_of_residence')) {
            $user->city_of_residence = $data['city_of_residence'] ?? $data['city'] ?? null;
        }

        if (Schema::hasColumn('users', 'main_business_category_id')) {
            $user->main_business_category_id = blank($data['main_business_category_id'] ?? null)
                ? null
                : (int) $data['main_business_category_id'];
        }

        if (Schema::hasColumn('users', 'business_category_id')) {
            $user->business_category_id = blank($data['business_category_id'] ?? null)
                ? null
                : (int) $data['business_category_id'];
        }

        if (! blank($data['resolved_referred_by_user_id'] ?? null)) {
            if (Schema::hasColumn('users', 'introduced_by')) {
                $user->introduced_by = (string) $data['resolved_referred_by_user_id'];
            }

            if (Schema::hasColumn('users', 'referred_by_user_id')) {
                $user->referred_by_user_id = (string) $data['resolved_referred_by_user_id'];
            }
        }

        $trialEndsAt = now()->addDays(3);

        $user->status = 'inactive';
        $user->registration_source = 'App';
        $user->membership_status = User::STATUS_FREE_TRIAL;
        $user->membership_starts_at = now();
        $user->membership_ends_at = $trialEndsAt;
        $user->membership_expiry = $trialEndsAt;
        $user->coins_balance = $user->coins_balance ?? 0;

        // Store the hashed password in password_hash.
        // For OTP-only registrations (no password provided), persist a random hash to satisfy NOT NULL schema.
        if (! empty($data['password'])) {
            $user->password_hash = Hash::make($data['password']);
        } else {
            $user->password_hash = Hash::make(Str::random(40));
        }

        // Ensure any legacy password attribute isn't used
        if (isset($user->password)) {
            $user->password = null;
        }

        Log::info('auth.register.before_user_save', [
            'email' => (string) $user->email,
            'first_name' => (string) ($user->first_name ?? ''),
            'last_name' => (string) ($user->last_name ?? ''),
            'phone' => (string) ($user->phone ?? ''),
        ]);

        $user->public_profile_slug = app(\App\Services\Users\PublicProfileSlugService::class)->generateUniqueForUser($user);
        $user->save();

        Log::info('auth.register.after_user_saved', [
            'user_id' => (string) $user->id,
            'email' => (string) $user->email,
        ]);

        $user->refresh();

        Log::info('auth.register.after_user_refresh', [
            'user_id' => (string) $user->id,
            'email' => (string) $user->email,
        ]);

        $persisted = DB::table('users')->where('id', (string) $user->id)->exists();

        if (! $persisted) {
            throw new \RuntimeException('Registration failed: user was not persisted in users table.');
        }

        return $user;
    }

    private function resolvePublicDiskUrl(string $pathOrUrl): string
    {
        if (filter_var($pathOrUrl, FILTER_VALIDATE_URL)) {
            return $pathOrUrl;
        }

        return Storage::disk('public')->url($pathOrUrl);
    }

    private function storeRegisterProfilePhoto(RegisterRequest $request, FileUploadService $fileUploadService): ?FileModel
    {
        if (! $request->hasFile('profile_photo')) {
            return null;
        }

        Log::info('auth.register.profile_photo_received');

        try {
            return $fileUploadService->store($request->file('profile_photo'));
        } catch (MediaProcessingException $e) {
            throw ValidationException::withMessages([
                'profile_photo' => $e->getMessage(),
            ]);
        }
    }

    private function fillIfUserColumnExists(User $user, string $column, mixed $value): void
    {
        if (Schema::hasColumn('users', $column)) {
            $user->{$column} = $value;
        }
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Find user by email
        $user = User::where('email', $credentials['email'])->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials.',
                'data'    => null,
            ], 401);
        }

        // IMPORTANT: use password_hash column
        if (! Hash::check($credentials['password'], $user->password_hash)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials.',
                'data'    => null,
            ], 401);
        }

        $user->expireFreeTrialIfNeeded();
        $user->refresh();

        if (($user->status ?? 'active') !== 'active') {
            $message = 'Your account is inactive. Please contact support.';
            if ($user->status === 'inactive') {
                $message = 'Your registration request is under review. You will receive an email once it is approved.';
            } elseif ($user->status === 'rejected') {
                $message = 'Your registration request has been rejected. Please contact support for further details.';
            }
            return response()->json([
                'success' => false,
                'message' => $message,
                'data'    => null,
            ], 403);
        }

        // Create Sanctum token
        $token = $user->createToken('auth_token')->plainTextToken;

        // If you already have a UserResource, you can use it here instead of returning $user directly
        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'data'    => [
                'token' => $token,
                'user'  => $user,
            ],
        ]);
    }

    public function requestOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'You are not a registered user.',
                'data' => null,
            ], 404);
        }

        if (($user->status ?? 'active') !== 'active') {
            $message = 'Your account is inactive. Please contact support.';
            if ($user->status === 'inactive') {
                $message = 'Your registration request is under review. You will receive an email once it is approved.';
            } elseif ($user->status === 'rejected') {
                $message = 'Your registration request has been rejected. Please contact support for further details.';
            }
            return response()->json([
                'success' => false,
                'message' => $message,
                'data' => null,
            ], 403);
        }

        $otp = (string) random_int(1000, 9999);

        OtpCode::create([
            'user_id'    => $user->id,
            'email'      => $user->email,
            'purpose'    => 'login_otp',
            'code'       => Hash::make($otp),
            'expires_at' => now()->addMinutes(5),
            'used_at'    => null,
        ]);

        $mailable = new LoginOtpMail($otp, $user);

        try {
            Mail::to($user->email)->send($mailable);

            app(EmailLogService::class)->logMailableSent($mailable, [
                'user_id' => (string) $user->id,
                'to_email' => (string) $user->email,
                'to_name' => (string) ($user->display_name ?: trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))),
                'template_key' => 'login_otp',
                'source_module' => 'Auth',
                'related_type' => User::class,
                'related_id' => (string) $user->id,
                'payload' => [
                    'purpose' => 'login_otp',
                ],
            ]);
        } catch (\Throwable $exception) {
            app(EmailLogService::class)->logMailableFailed($mailable, [
                'user_id' => (string) $user->id,
                'to_email' => (string) $user->email,
                'to_name' => (string) ($user->display_name ?: trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))),
                'template_key' => 'login_otp',
                'source_module' => 'Auth',
                'related_type' => User::class,
                'related_id' => (string) $user->id,
                'payload' => [
                    'purpose' => 'login_otp',
                ],
            ], $exception);

            throw $exception;
        }

        return response()->json([
            'success' => true,
            'message' => 'OTP sent successfully.',
            'data'    => null,
        ]);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'otp'   => ['required', 'digits:4'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'You are not a registered user.',
                'data'    => null,
            ], 404);
        }

        $otpRecord = OtpCode::where('user_id', $user->id)
            ->where('purpose', 'login_otp')
            ->whereNull('used_at')
            ->orderByDesc('created_at')
            ->first();

        if (! $otpRecord) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid OTP.',
                'data'    => null,
            ], 422);
        }

        if (now()->greaterThan($otpRecord->expires_at)) {
            return response()->json([
                'success' => false,
                'message' => 'OTP has expired.',
                'data'    => null,
            ], 422);
        }

        if (! Hash::check($data['otp'], $otpRecord->code)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid OTP.',
                'data'    => null,
            ], 422);
        }

        $otpRecord->used_at = now();
        $otpRecord->save();

        $user->expireFreeTrialIfNeeded();
        $user->refresh();

        if ($user->membership_status === 'suspended') {
            return $this->error('Account is suspended', 403);
        }

        if (($user->status ?? 'active') !== 'active') {
            $message = 'Your account is inactive. Please contact support.';
            if ($user->status === 'inactive') {
                $message = 'Your registration request is under review. You will receive an email once it is approved.';
            } elseif ($user->status === 'rejected') {
                $message = 'Your registration request has been rejected. Please contact support for further details.';
            }
            return response()->json([
                'success' => false,
                'message' => $message,
                'data' => null,
            ], 403);
        }

        $user->last_login_at = now();
        $user->save();
        $user->refresh();

        $token = $user->createToken('api')->plainTextToken;

        UserLoginHistory::create([
            'user_id' => $user->id,
            'logged_in_at' => now(),
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
        ]);

        return $this->success([
            'user' => new UserResource($user->load([
                'city',
                'activeCircle:id,name,slug,city_id',
                'activeCircle.cityRef:id,name',
                'circleMemberships' => fn ($query) => $query
                    ->where('status', (string) config('circle.member_joined_status', 'approved'))
                    ->whereNull('deleted_at')
                    ->whereNull('left_at')
                    ->where(function ($nested): void {
                        $nested->whereNull('paid_ends_at')->orWhere('paid_ends_at', '>=', now());
                    })
                    ->orderByDesc('joined_at')
                    ->with('circle:id,name,slug'),
            ])),
            'token' => $token,
        ], 'Login successful');
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'You are not a registered user.',
                'data' => null,
            ], 404);
        }

        $otp = (string) random_int(1000, 9999);

        OtpCode::create([
            'user_id'    => $user->id,
            'email'      => $user->email,
            'purpose'    => 'password_reset',
            'code'       => Hash::make($otp),
            'expires_at' => now()->addMinutes(5),
            'used_at'    => null,
        ]);

        $mailable = new PasswordResetOtpMail($otp, $user);

        try {
            Mail::to($user->email)->send($mailable);

            app(EmailLogService::class)->logMailableSent($mailable, [
                'user_id' => (string) $user->id,
                'to_email' => (string) $user->email,
                'to_name' => (string) ($user->display_name ?: trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))),
                'template_key' => 'password_reset_otp',
                'source_module' => 'Auth',
                'related_type' => User::class,
                'related_id' => (string) $user->id,
                'payload' => [
                    'purpose' => 'password_reset',
                ],
            ]);
        } catch (\Throwable $exception) {
            app(EmailLogService::class)->logMailableFailed($mailable, [
                'user_id' => (string) $user->id,
                'to_email' => (string) $user->email,
                'to_name' => (string) ($user->display_name ?: trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))),
                'template_key' => 'password_reset_otp',
                'source_module' => 'Auth',
                'related_type' => User::class,
                'related_id' => (string) $user->id,
                'payload' => [
                    'purpose' => 'password_reset',
                ],
            ], $exception);

            throw $exception;
        }

        return response()->json([
            'success' => true,
            'message' => 'If your email is registered, a password reset OTP has been sent.',
            'data'    => null,
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'                 => ['required', 'email'],
            'otp'                   => ['required', 'digits:4'],
            'password'              => ['required', 'min:8', 'confirmed'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'You are not a registered user.',
                'data'    => null,
            ], 404);
        }

        $otpRecord = OtpCode::where('user_id', $user->id)
            ->where('purpose', 'password_reset')
            ->whereNull('used_at')
            ->orderByDesc('created_at')
            ->first();

        if (! $otpRecord) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid OTP.',
                'data'    => null,
            ], 422);
        }

        if (now()->greaterThan($otpRecord->expires_at)) {
            return response()->json([
                'success' => false,
                'message' => 'OTP has expired.',
                'data'    => null,
            ], 422);
        }

        if (! Hash::check($data['otp'], $otpRecord->code)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid OTP.',
                'data'    => null,
            ], 422);
        }

        $otpRecord->used_at = now();
        $otpRecord->save();

        $user->password_hash = Hash::make($data['password']);
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Password reset successfully.',
            'data'    => null,
        ]);
    }

    public function logout(): JsonResponse
    {
        $user = Auth::guard('sanctum')->user();

        if ($user && $user->currentAccessToken()) {
            app(OnlineStatusService::class)->markOffline($user, true, 'Last seen just now');
            $user->currentAccessToken()->delete();
        }

        return $this->success(null, 'Logged out successfully');
    }

    public function me(): JsonResponse
    {
        $user = Auth::guard('sanctum')->user();
        $user->expireFreeTrialIfNeeded();
        $user->refresh();

        return $this->success(new UserResource($user->loadMissing([
            'city',
            'activeCircle:id,name,slug,city_id',
            'activeCircle.cityRef:id,name',
            'circleMemberships' => fn ($query) => $query
                ->where('status', (string) config('circle.member_joined_status', 'approved'))
                ->whereNull('deleted_at')
                ->whereNull('left_at')
                ->where(function ($nested): void {
                    $nested->whereNull('paid_ends_at')->orWhere('paid_ends_at', '>=', now());
                })
                ->orderByDesc('joined_at')
                ->with('circle:id,name,slug'),
        ])));
    }
}
