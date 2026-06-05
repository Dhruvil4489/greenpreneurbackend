<?php

namespace App\Services\Events;

use App\Models\CircleCategory;
use App\Models\CircleCategoryLevel4;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventRegistration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EventRegistrationService
{
    public function __construct(
        private readonly EventService $events,
        private readonly EventQrService $qr,
        private readonly EventPaymentService $payments,
        private readonly EventRegistrationQrService $registrationQr,
    ) {}

    public function registerMember(Event $event, EventOccurrence $occurrence, User $user, string $source = 'app'): EventRegistration
    {
        if ($occurrence->event_id !== $event->id) {
            throw ValidationException::withMessages(['occurrence_id' => 'Occurrence does not belong to this event.']);
        }
        $canRegister = $this->events->canRegister($event, $user);
        if (! $canRegister['can_register']) {
            throw ValidationException::withMessages(['event_id' => $canRegister['reason']]);
        }

        return $this->createRegistration($event, $occurrence, ['user_id' => $user->id, 'source' => $source, 'registration_type' => 'member']);
    }

    public function registerMemberDirectNoPayment(Event $event, EventOccurrence $occurrence, User $user, string $source = 'app'): EventRegistration
    {
        if ($occurrence->event_id !== $event->id) {
            throw ValidationException::withMessages(['occurrence_id' => 'Occurrence does not belong to this event.']);
        }

        $existing = EventRegistration::query()
            ->where('occurrence_id', $occurrence->id)
            ->where('user_id', $user->id)
            ->where('status', '!=', 'cancelled')
            ->whereNull('deleted_at')
            ->first();

        if ($existing) {
            $existing = $this->registrationQr->ensureQrGenerated($existing);

            return $existing->fresh(['event.circle', 'occurrence', 'user', 'invitedByUser', 'businessCategoryMain', 'businessCategorySub']);
        }

        return $this->createRegistration(
            $event,
            $occurrence,
            ['user_id' => $user->id, 'source' => $source, 'registration_type' => 'member'],
            false
        );
    }


    public function registerApprovedCrossCircleMember(Event $event, EventOccurrence $occurrence, User $user, string $requestId, string $source = 'app'): EventRegistration
    {
        if ($occurrence->event_id !== $event->id) {
            throw ValidationException::withMessages(['occurrence_id' => 'Occurrence does not belong to this event.']);
        }

        $existing = EventRegistration::query()
            ->where('occurrence_id', $occurrence->id)
            ->where('user_id', $user->id)
            ->where('status', '!=', 'cancelled')
            ->whereNull('deleted_at')
            ->first();

        if ($existing) {
            $updates = $this->filterRegistrationColumns([
                'registration_type' => 'cross_circle_member',
                'registration_request_id' => $requestId,
            ]);
            if (! empty($updates)) {
                $existing->forceFill($updates)->save();
            }

            if ((bool) ($existing->payment_required ?? false) && ($existing->payment_status ?? null) === 'pending') {
                return $this->payments->attachCheckout($existing->fresh(['event.circle', 'occurrence', 'user', 'invitedByUser', 'businessCategoryMain', 'businessCategorySub']));
            }

            $existing = $this->registrationQr->ensureQrGenerated($existing);

            return $existing->fresh(['event.circle', 'occurrence', 'user', 'invitedByUser', 'businessCategoryMain', 'businessCategorySub']);
        }

        return $this->createRegistration(
            $event,
            $occurrence,
            [
                'user_id' => $user->id,
                'source' => $source,
                'registration_type' => 'cross_circle_member',
                'registration_request_id' => $requestId,
            ],
            true
        );
    }

    public function registerAppUserVisitor(Event $event, EventOccurrence $occurrence, User $user, string $source = 'app'): EventRegistration
    {
        if ($occurrence->event_id !== $event->id) {
            throw ValidationException::withMessages(['occurrence_id' => 'Occurrence does not belong to this event.']);
        }
        if (! $this->events->visitorRegistrationEnabled($event)) {
            throw ValidationException::withMessages(['event_id' => 'Visitor registration is not enabled for this event.']);
        }

        $existing = EventRegistration::query()
            ->where('occurrence_id', $occurrence->id)
            ->where('user_id', $user->id)
            ->where('status', '!=', 'cancelled')
            ->whereNull('deleted_at')
            ->latest('created_at')
            ->first();

        if ($existing) {
            if ((bool) ($existing->payment_required ?? false) && in_array((string) ($existing->payment_status ?? ''), ['pending', 'failed', 'expired'], true)) {
                return $this->payments->attachCheckout($existing->fresh(['event.circle', 'occurrence', 'user', 'invitedByUser', 'businessCategoryMain', 'businessCategorySub']));
            }

            $existing = $this->registrationQr->ensureQrGenerated($existing);

            return $existing->fresh(['event.circle', 'occurrence', 'user', 'invitedByUser', 'businessCategoryMain', 'businessCategorySub']);
        }

        return $this->createRegistration($event, $occurrence, [
            'user_id' => $user->id,
            'source' => $source,
            'registration_type' => 'app_user_visitor',
            'visitor_name' => $this->userDisplayName($user),
            'visitor_email' => $user->email,
            'visitor_phone' => $user->phone,
            'visitor_company' => $user->company_name,
            'visitor_city' => $user->city ?? $user->city_of_residence,
        ], true);
    }

    public function registerVisitor(Event $event, EventOccurrence $occurrence, array $data, string $source = 'visitor_app'): EventRegistration
    {
        if ($occurrence->event_id !== $event->id) {
            throw ValidationException::withMessages(['occurrence_id' => 'Occurrence does not belong to this event.']);
        }
        if (! $this->events->visitorRegistrationEnabled($event)) {
            throw ValidationException::withMessages(['event_id' => 'Visitor registration is not enabled for this event.']);
        }

        $source = $this->normalizeVisitorSource($data['source'] ?? $source);
        unset($data['source']);

        $data = $this->preparePublicVisitorData($data);

        return $this->createRegistration($event, $occurrence, $data + ['source' => $source, 'registration_type' => 'visitor']);
    }

    public function syncZohoVisitor(Event $event, EventOccurrence $occurrence, array $data): EventRegistration
    {
        if ($occurrence->event_id !== $event->id) {
            throw ValidationException::withMessages(['occurrence_id' => 'Occurrence does not belong to this event.']);
        }
        if (! $this->events->visitorRegistrationEnabled($event)) {
            throw ValidationException::withMessages(['event_id' => 'Visitor registration is not enabled for this event.']);
        }

        return DB::transaction(function () use ($event, $occurrence, $data): EventRegistration {
            $existing = $this->duplicateVisitorQuery($occurrence->id, $data, $event->id)->lockForUpdate()->first();
            if ($existing) {
                $updates = $this->filterRegistrationColumns(array_filter([
                    'visitor_name' => $data['visitor_name'] ?? $existing->visitor_name,
                    'visitor_email' => $data['visitor_email'] ?? $existing->visitor_email,
                    'visitor_phone' => $data['visitor_phone'] ?? $existing->visitor_phone,
                    'visitor_company' => $data['visitor_company'] ?? $existing->visitor_company,
                    'visitor_city' => $data['visitor_city'] ?? $existing->visitor_city,
                    'zoho_form_entry_id' => $data['zoho_form_entry_id'] ?? $existing->zoho_form_entry_id,
                    'zoho_payment_id' => $data['zoho_payment_id'] ?? $existing->zoho_payment_id,
                    'zoho_payment_status' => $data['zoho_payment_status'] ?? $existing->zoho_payment_status,
                    'source' => 'zoho_form',
                ], fn ($value) => $value !== null));
                $existing->forceFill($updates)->save();

                $existing = $this->registrationQr->ensureQrGenerated($existing);

                return $existing->fresh(['event.circle', 'occurrence', 'user', 'invitedByUser', 'businessCategoryMain', 'businessCategorySub']);
            }

            return $this->createRegistration($event, $occurrence, $data + ['source' => 'zoho_form', 'registration_type' => 'visitor'], false);
        });
    }

    private function preparePublicVisitorData(array $data): array
    {
        Log::info('public_event_registration_user_lookup_start', [
            'visitor_email' => $data['visitor_email'] ?? null,
            'visitor_phone' => $data['visitor_phone'] ?? null,
        ]);

        $user = $this->findUserByEmailOrPhone($data['visitor_email'] ?? null, $data['visitor_phone'] ?? null);
        if (! $user) {
            $user = $this->createVisitorUser($data);
            Log::info('public_event_registration_new_user_created', ['user_id' => (string) $user->id]);
        } else {
            Log::info('public_event_registration_existing_user_linked', ['user_id' => (string) $user->id]);
        }

        $data['user_id'] = $user->id;
        $data['visitor_name'] = $data['visitor_name'] ?? $this->userDisplayName($user);
        $data['visitor_email'] = $data['visitor_email'] ?? $user->email;
        $data['visitor_phone'] = $data['visitor_phone'] ?? $user->phone;
        $data['visitor_company'] = $data['visitor_company'] ?? $user->company_name;
        $data['visitor_city'] = $data['visitor_city'] ?? ($user->city ?? $user->city_of_residence);
        $data = $this->normalizeVisitorBusinessCategories($data);
        $data = $this->populateVisitorBusinessCategoryNames($data);
        $invitedByType = $data['invited_by_type'] ?? null;
        $data['invited_by_user_id'] = in_array($invitedByType, ['circle_member_peer', 'other'], true)
            ? ($data['invited_by_user_id'] ?? null)
            : null;
        if ($invitedByType && empty($data['how_known'])) {
            $data['how_known'] = $invitedByType;
        }

        $data['metadata'] = array_merge((array) ($data['metadata'] ?? []), array_filter([
            'designation' => $data['visitor_designation'] ?? ($data['designation'] ?? null),
            'business_category_id' => $data['business_category_id'] ?? null,
            'business_sub_category' => $data['business_sub_category'] ?? null,
            'referral_code' => $data['referral_code'] ?? null,
            'referred_by' => $data['referred_by'] ?? null,
            'notes' => $data['notes'] ?? null,
            'visitor_designation' => $data['visitor_designation'] ?? null,
            'visitor_business_category_id' => $data['visitor_business_category_id'] ?? null,
            'visitor_business_category' => $data['visitor_business_category'] ?? null,
            'visitor_business_category_main_id' => $data['visitor_business_category_main_id'] ?? null,
            'visitor_business_category_sub_id' => $data['visitor_business_category_sub_id'] ?? null,
            'visitor_business_category_main' => $data['visitor_business_category_main'] ?? null,
            'visitor_business_category_sub' => $data['visitor_business_category_sub'] ?? null,
            'visitor_business_website' => $data['visitor_business_website'] ?? null,
            'visitor_business_brief' => $data['visitor_business_brief'] ?? null,
            'invited_by_type' => $data['invited_by_type'] ?? null,
            'invited_by_user_id' => $data['invited_by_user_id'] ?? null,
            'linked_user_id' => (string) $user->id,
        ], fn ($value) => $value !== null && $value !== ''));

        foreach (['full_name', 'email', 'phone', 'city', 'company_name', 'designation', 'business_category_id', 'business_sub_category', 'referral_code', 'referred_by', 'notes'] as $key) {
            unset($data[$key]);
        }

        return $data;
    }

    private function normalizeVisitorBusinessCategories(array $data): array
    {
        foreach (['visitor_business_category_id', 'visitor_business_category_main_id', 'visitor_business_category_sub_id'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
                $data[$key] = (int) $data[$key];
            }
        }

        if (! array_key_exists('visitor_business_category_sub_id', $data) && array_key_exists('visitor_business_category_id', $data)) {
            $data['visitor_business_category_sub_id'] = $data['visitor_business_category_id'];
        }


        return $data;
    }

    private function populateVisitorBusinessCategoryNames(array $data): array
    {
        if (! empty($data['visitor_business_category_main_id'])) {
            $main = CircleCategory::query()->find($data['visitor_business_category_main_id']);
            if ($main) {
                $data['visitor_business_category_main'] = $main->name;
            }
        }

        if (! empty($data['visitor_business_category_sub_id'])) {
            $sub = CircleCategoryLevel4::query()->find($data['visitor_business_category_sub_id']);
            if ($sub) {
                $data['visitor_business_category_sub'] = $sub->name;
                $data['visitor_business_category'] = $data['visitor_business_category'] ?? $sub->name;
            }
        }

        return $data;
    }

    private function findUserByEmailOrPhone(?string $email, ?string $phone): ?User
    {
        return User::query()
            ->where(function ($query) use ($email, $phone): void {
                if ($email) {
                    $query->orWhereRaw('LOWER(email) = ?', [strtolower($email)]);
                }
                if ($phone) {
                    $query->orWhere('phone', $phone);
                }
            })
            ->first();
    }

    private function createVisitorUser(array $data): User
    {
        $name = trim((string) ($data['visitor_name'] ?? 'Event Visitor')) ?: 'Event Visitor';
        [$firstName, $lastName] = array_pad(preg_split('/\s+/', $name, 2), 2, null);
        $attributes = [
            'first_name' => $firstName ?: $name,
            'last_name' => $lastName,
            'display_name' => $name,
            'email' => $data['visitor_email'] ?? null,
            'phone' => $data['visitor_phone'] ?? null,
            'company_name' => $data['visitor_company'] ?? ($data['company_name'] ?? null),
            'designation' => $data['visitor_designation'] ?? ($data['designation'] ?? null),
            'city' => $data['visitor_city'] ?? ($data['city'] ?? null),
            'city_of_residence' => $data['visitor_city'] ?? ($data['city'] ?? null),
            'business_category_id' => $data['business_category_id'] ?? null,
            'business_sub_category' => $data['business_sub_category'] ?? null,
            'membership_status' => 'visitor',
            'password_hash' => Hash::make(Str::random(32)),
            'password' => Hash::make(Str::random(32)),
        ];

        return User::query()->create(array_filter($attributes, fn ($value, $key) => $value !== null && Schema::hasColumn('users', $key), ARRAY_FILTER_USE_BOTH));
    }

    private function userDisplayName(User $user): string
    {
        return trim((string) ($user->display_name ?: trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: $user->email ?: $user->phone ?: 'Event Attendee'));
    }

    public function qrDetails(EventRegistration $registration): array
    {
        $hasGeneratedQr = ! empty($registration->qr_code_path) || ! empty($registration->qr_code_url) || ! empty($registration->qr_code_svg);

        return [
            'registration_id' => $registration->id,
            'event_id' => $registration->event_id,
            'occurrence_id' => $registration->occurrence_id,
            'qr_token' => $hasGeneratedQr ? $registration->qr_token : null,
            'qr_payload' => $hasGeneratedQr ? $this->qr->payload($registration->qr_token) : null,
            'qr_code_url' => $hasGeneratedQr ? ($registration->qr_code_path ? $this->qr->url($registration->qr_code_path) : $registration->qr_code_url) : null,
            'qr_code_svg' => $hasGeneratedQr ? $registration->qr_code_svg : null,
            'status' => $registration->status,
            'checkin_status' => $registration->checkin_status,
            'payment_status' => $registration->payment_status ?? null,
        ];
    }

    private function createRegistration(Event $event, EventOccurrence $occurrence, array $data, bool $applyPayment = true): EventRegistration
    {
        if ($applyPayment && $this->payments->paymentRequired($event) && $this->payments->amount($event) <= 0) {
            throw ValidationException::withMessages(['ticket_price' => 'Paid event ticket price must be greater than zero.']);
        }

        $registration = DB::transaction(function () use ($event, $occurrence, $data, $applyPayment): EventRegistration {
            $lockedOccurrence = EventOccurrence::query()
                ->where('id', $occurrence->id)
                ->lockForUpdate()
                ->firstOrFail();

            $registeredCount = EventRegistration::query()
                ->where('event_id', $event->id)
                ->where('occurrence_id', $lockedOccurrence->id)
                ->where('status', '!=', 'cancelled')
                ->whereNull('deleted_at')
                ->count();

            $registrationTypeForDuplicate = $data['registration_type'] ?? (isset($data['user_id']) ? 'member' : 'visitor');
            $query = in_array($registrationTypeForDuplicate, ['visitor', 'app_user_visitor'], true)
                ? $this->duplicateVisitorQuery($lockedOccurrence->id, $data, $event->id)
                : (isset($data['user_id'])
                    ? EventRegistration::query()->where('occurrence_id', $lockedOccurrence->id)->where('user_id', $data['user_id'])->where('status', '!=', 'cancelled')->whereNull('deleted_at')
                    : $this->duplicateVisitorQuery($lockedOccurrence->id, $data, $event->id));

            $existing = $query->lockForUpdate()->first();
            if ($existing) {
                Log::info('public_event_registration_existing_registration_found', [
                    'registration_id' => (string) $existing->id,
                    'event_id' => (string) $event->id,
                    'occurrence_id' => (string) $lockedOccurrence->id,
                    'user_id' => $data['user_id'] ?? $existing->user_id,
                ]);
                $updates = $this->filterRegistrationColumns([
                    'user_id' => $existing->user_id ?: ($data['user_id'] ?? null),
                    'visitor_name' => $data['visitor_name'] ?? $existing->visitor_name,
                    'visitor_email' => $data['visitor_email'] ?? $existing->visitor_email,
                    'visitor_phone' => $data['visitor_phone'] ?? $existing->visitor_phone,
                    'visitor_company' => $data['visitor_company'] ?? $existing->visitor_company,
                    'visitor_city' => $data['visitor_city'] ?? $existing->visitor_city,
                    'visitor_designation' => $data['visitor_designation'] ?? $existing->visitor_designation,
                    'visitor_business_category_id' => $data['visitor_business_category_id'] ?? $existing->visitor_business_category_id,
                    'visitor_business_category' => $data['visitor_business_category'] ?? $existing->visitor_business_category,
                    'visitor_business_category_main_id' => $data['visitor_business_category_main_id'] ?? $existing->visitor_business_category_main_id,
                    'visitor_business_category_sub_id' => $data['visitor_business_category_sub_id'] ?? $existing->visitor_business_category_sub_id,
                    'visitor_business_category_main' => $data['visitor_business_category_main'] ?? ($existing->visitor_business_category_main ?? null),
                    'visitor_business_category_sub' => $data['visitor_business_category_sub'] ?? ($existing->visitor_business_category_sub ?? null),
                    'visitor_business_website' => $data['visitor_business_website'] ?? $existing->visitor_business_website,
                    'visitor_business_brief' => $data['visitor_business_brief'] ?? $existing->visitor_business_brief,
                    'invited_by_type' => $data['invited_by_type'] ?? $existing->invited_by_type,
                    'invited_by_user_id' => array_key_exists('invited_by_user_id', $data) ? $data['invited_by_user_id'] : $existing->invited_by_user_id,
                    'how_known' => $data['how_known'] ?? $existing->how_known,
                    'metadata' => array_merge((array) ($existing->metadata ?? []), (array) ($data['metadata'] ?? [])),
                ]);
                if (! empty($updates)) {
                    $existing->forceFill($updates)->save();
                }

                $existing = $this->registrationQr->ensureQrGenerated($existing);

                return $existing->fresh(['event.circle', 'occurrence', 'user', 'invitedByUser', 'businessCategoryMain', 'businessCategorySub']);
            }

            $this->assertCapacity($event, $lockedOccurrence, $registeredCount);

            $registrationType = $data['registration_type'] ?? (isset($data['user_id']) ? 'member' : 'visitor');
            unset($data['registration_type']);

            $paymentRequired = $applyPayment && $this->payments->paymentRequired($event);
            $registration = EventRegistration::query()->create($this->filterRegistrationColumns(array_merge($data, [
                'event_id' => $event->id,
                'occurrence_id' => $lockedOccurrence->id,
                'qr_token' => $this->uniqueToken(),
                'status' => $paymentRequired ? 'pending_payment' : 'registered',
                'checkin_status' => 'pending',
                'registered_at' => now(),
                'payment_required' => $paymentRequired,
                'payment_status' => $paymentRequired ? 'pending' : 'not_required',
                'amount' => $paymentRequired ? $this->payments->amount($event) : 0,
                'currency' => $this->payments->currency($event),
                'registration_type' => $registrationType,
            ])));

            if (! $paymentRequired) {
                $registration = $this->registrationQr->ensureQrGenerated($registration);
                $registration = $registration->fresh(['event.circle', 'occurrence', 'user', 'invitedByUser', 'businessCategoryMain', 'businessCategorySub']);
                $this->notifySafely($registration);
            }

            $lockedOccurrence->forceFill(['registered_count' => $registeredCount + 1])->save();

            return $registration->fresh(['event.circle', 'occurrence', 'user', 'invitedByUser', 'businessCategoryMain', 'businessCategorySub']);
        });

        if ((bool) ($registration->payment_required ?? false) && in_array((string) ($registration->payment_status ?? ''), ['pending', 'failed', 'expired'], true)) {
            try {
                return $this->payments->attachCheckout($registration);
            } catch (\Throwable $exception) {
                Log::error('event_registration_payment_checkout_failed', [
                    'registration_id' => (string) $registration->id,
                    'error' => $exception->getMessage(),
                ]);

                $registration->forceFill($this->filterRegistrationColumns([
                    'payment_status' => 'pending',
                    'status' => 'pending_payment',
                    'zoho_invoice_sync_error' => $exception->getMessage(),
                ]))->save();

                return $registration->fresh(['event.circle', 'occurrence', 'user', 'invitedByUser', 'businessCategoryMain', 'businessCategorySub']);
            }
        }

        return $registration;
    }

    private function duplicateVisitorQuery(string $occurrenceId, array $data, ?string $eventId = null)
    {
        $query = EventRegistration::query();

        if ($eventId) {
            $query->where('event_id', $eventId);
        }

        return $query
            ->where('occurrence_id', $occurrenceId)
            ->where('status', '!=', 'cancelled')
            ->whereNull('deleted_at')
            ->where(function ($q) use ($data): void {
                $matched = false;
                foreach (['zoho_form_entry_id', 'visitor_phone', 'visitor_email'] as $field) {
                    if (! empty($data[$field])) {
                        if ($field === 'visitor_email') {
                            $q->orWhereRaw('LOWER(visitor_email) = ?', [strtolower((string) $data[$field])]);
                        } else {
                            $q->orWhere($field, $data[$field]);
                        }
                        $matched = true;
                    }
                }
                if (! $matched) {
                    $q->whereRaw('1 = 0');
                }
            });
    }

    private function assertCapacity(Event $event, EventOccurrence $occurrence, int $registeredCount): void
    {
        $registrationLimit = $occurrence->registration_limit ?? $event->registration_limit;

        if (! $registrationLimit) {
            return;
        }

        if ($registeredCount >= $registrationLimit) {
            throw ValidationException::withMessages(['registration_limit' => 'Registration limit has been reached.']);
        }
    }

    private function uniqueToken(): string
    {
        do {
            $token = $this->qr->generateToken();
        } while (EventRegistration::query()->where('qr_token', $token)->exists());

        return $token;
    }

    private function normalizeVisitorSource(string $source): string
    {
        return match ($source) {
            'zoho_form' => 'zoho_form',
            'admin' => 'admin',
            'app' => 'app',
            'api' => 'api',
            'web_form' => 'web_form',
            'web', 'public', 'visitor_web' => 'visitor_web',
            default => 'visitor_app',
        };
    }

    private function filterRegistrationColumns(array $data): array
    {
        return array_filter($data, fn ($value, $key) => Schema::hasColumn('event_registrations', $key), ARRAY_FILTER_USE_BOTH);
    }

    private function notifySafely(EventRegistration $registration): void
    {
        try {
            Log::info('Event registration notification queued placeholder.', ['event_registration_id' => $registration->id]);
        } catch (\Throwable $exception) {
            Log::error('Event registration notification failed.', ['event_registration_id' => $registration->id, 'error' => $exception->getMessage()]);
        }
    }
}
