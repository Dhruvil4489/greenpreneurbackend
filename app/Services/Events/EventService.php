<?php

namespace App\Services\Events;

use App\Models\CircleMember;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventRegistration;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EventService
{
    private const EVENT_ADMIN_ROLES = [
        'global_admin', 'super-admin', 'super_admin', 'industry_director', 'ded', 'circle_leader',
        'founder', 'circle_founder', 'circle_director', 'director', 'chair', 'vice_chair', 'secretary',
        'committee_leader', 'leadership_team',
    ];

    private const CIRCLE_LEADER_ROLES = [
        'founder', 'circle_founder', 'circle_director', 'director', 'chair', 'vice_chair', 'secretary',
        'committee_leader', 'leadership_team',
    ];

    public function __construct(private readonly EventOccurrenceGeneratorService $occurrenceGenerator) {}

    public function create(array $data, User $actor): Event
    {
        return DB::transaction(function () use ($data, $actor): Event {
            $data = $this->filterEventColumns($this->normalize($data, $actor));
            $event = Event::query()->create($data);
            $this->occurrenceGenerator->generate($event);

            return $event->load(['circle', 'occurrences']);
        });
    }

    public function update(Event $event, array $data): Event
    {
        return DB::transaction(function () use ($event, $data): Event {
            $event->fill($this->filterEventColumns($this->normalize($data, null, false)));
            $event->save();
            $this->occurrenceGenerator->regenerateFuture($event);

            return $event->load(['circle', 'occurrences']);
        });
    }

    public function listOccurrences(array $filters, ?User $user = null, int $perPage = 20): LengthAwarePaginator
    {
        $eventType = $filters['event_type'] ?? $filters['type'] ?? null;
        $search = $filters['search'] ?? $filters['title'] ?? null;
        $status = $filters['status'] ?? null;
        $timezone = config('app.timezone') ?: 'UTC';


        $query = EventOccurrence::query()
            ->with(['event.circle', 'registrations' => fn ($q) => $user ? $q->where('user_id', $user->id) : $q->whereRaw('1 = 0')])
            ->withCount([
                'registrations as registered_count' => fn ($q) => $q
                    ->whereNull('deleted_at')
                    ->where(function ($countQuery): void {
                        $countQuery->where('status', 'registered')
                            ->orWhere('payment_status', 'paid')
                            ->orWhere(function ($inner): void {
                                $inner->where('payment_required', false)->where('status', '!=', 'cancelled');
                            });
                    }),
                'registrations as checked_in_count' => fn ($q) => $q
                    ->whereNull('deleted_at')
                    ->where('checkin_status', 'checked_in'),
            ])
            ->whereHas('event', function (Builder $eventQuery) use ($filters, $eventType, $search, $user): void {
                $eventQuery->when($eventType, fn ($q, $v) => $q->where('event_type', $v))
                    ->when($filters['circle_id'] ?? null, fn ($q, $v) => $q->where('circle_id', $v))
                    ->when($filters['mode'] ?? null, fn ($q, $v) => $q->where('mode', $v))
                    ->when($search, function ($q, $v): void {
                        $operator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
                        $q->where('title', $operator, '%'.$v.'%');
                    });

                $this->applyValidEventStatusScope($eventQuery);
                $this->applyEventVisibilityScope($eventQuery, $user);
            });

        $this->applyValidOccurrenceStatusScope($query);
        $this->applyOccurrenceStatusFilter($query, $status, $timezone);

        if (! $this->statusFilterControlsDateWindow($status) && ($filters['upcoming'] ?? 'true') !== 'false') {
            $query->where('start_at', '>=', Carbon::now($timezone)->startOfDay());
        }

        $query->when($filters['from_date'] ?? null, fn ($q, $v) => $q->where('start_at', '>=', Carbon::parse($v, $timezone)->startOfDay()))
            ->when($filters['to_date'] ?? null, fn ($q, $v) => $q->where('start_at', '<=', Carbon::parse($v, $timezone)->endOfDay()));

        return $query->orderBy('start_at')->paginate($perPage);
    }


    private function applyOccurrenceStatusFilter(Builder $query, ?string $status, string $timezone): void
    {
        if (! $status || $status === 'all') {
            return;
        }

        $now = Carbon::now($timezone);

        match ($status) {
            'today' => $query->whereBetween('start_at', [$now->copy()->startOfDay(), $now->copy()->endOfDay()]),
            'live' => $query->where('start_at', '<=', $now)->where(function (Builder $liveQuery) use ($now): void {
                $liveQuery->whereNull('end_at')->orWhere('end_at', '>=', $now);
            }),
            'upcoming' => $query->where('start_at', '>', $now),
            default => $this->applyExactOccurrenceOrEventStatusFilter($query, $status),
        };
    }

    private function applyExactOccurrenceOrEventStatusFilter(Builder $query, string $status): void
    {
        $query->where(function (Builder $statusQuery) use ($status): void {
            if (Schema::hasColumn('event_occurrences', 'status')) {
                $statusQuery->where('status', $status);
            }

            if (Schema::hasColumn('events', 'status')) {
                $method = Schema::hasColumn('event_occurrences', 'status') ? 'orWhereHas' : 'whereHas';
                $statusQuery->{$method}('event', fn (Builder $eventQuery) => $eventQuery->where('status', $status));
            }
        });
    }

    private function statusFilterControlsDateWindow(?string $status): bool
    {
        return in_array($status, ['today', 'live', 'upcoming'], true);
    }

    private function applyValidEventStatusScope(Builder $query): void
    {
        if (Schema::hasColumn('events', 'is_active')) {
            $query->where('is_active', true);
        }

        if (Schema::hasColumn('events', 'status')) {
            $validStatuses = ['scheduled', 'live', 'upcoming', 'published', 'active'];

            $query->where(function (Builder $statusQuery) use ($validStatuses): void {
                $statusQuery->whereNull('status')
                    ->orWhereIn('status', $validStatuses);
            });
        }
    }

    private function applyValidOccurrenceStatusScope(Builder $query): void
    {
        if (! Schema::hasColumn('event_occurrences', 'status')) {
            return;
        }

        $validStatuses = ['scheduled', 'live', 'upcoming', 'published', 'active'];

        $query->where(function (Builder $statusQuery) use ($validStatuses): void {
            $statusQuery->whereNull('status')
                ->orWhereIn('status', $validStatuses);
        });
    }

    private function applyEventVisibilityScope(Builder $query, ?User $user): void
    {
        if (! $user || $this->isAdmin($user)) {
            return;
        }

        if ((bool) config('events.list_all_valid_occurrences', true)) {
            return;
        }

        $hasEventType = Schema::hasColumn('events', 'event_type');
        $hasVisibility = Schema::hasColumn('events', 'visibility');
        $hasIsPublic = Schema::hasColumn('events', 'is_public');
        $hasCircleId = Schema::hasColumn('events', 'circle_id');

        if (! $hasEventType && ! $hasVisibility && ! $hasIsPublic) {
            return;
        }

        $memberCircleIds = $hasCircleId && Schema::hasTable('circle_members')
            ? CircleMember::query()
                ->where('user_id', $user->id)
                ->whereIn('status', ['approved', 'active'])
                ->whereNull('deleted_at')
                ->pluck('circle_id')
                ->all()
            : [];

        $query->where(function (Builder $visibilityQuery) use ($hasEventType, $hasVisibility, $hasIsPublic, $hasCircleId, $memberCircleIds): void {
            if ($hasEventType) {
                $visibilityQuery->whereIn('event_type', ['global_event', 'public_event']);
            }

            if ($hasVisibility) {
                $method = $hasEventType ? 'orWhere' : 'where';
                $visibilityQuery->{$method}('visibility', 'public');
            }

            if ($hasIsPublic) {
                $method = ($hasEventType || $hasVisibility) ? 'orWhere' : 'where';
                $visibilityQuery->{$method}('is_public', true);
            }

            if ($hasEventType && $hasCircleId && $memberCircleIds !== []) {
                $visibilityQuery->orWhere(function (Builder $circleQuery) use ($memberCircleIds): void {
                    $circleQuery->where('event_type', 'circle_meeting')
                        ->whereIn('circle_id', $memberCircleIds);
                });
            }
        });
    }

    public function isEligible(Event $event, ?User $user): bool
    {
        if (! $user) {
            return $this->visitorRegistrationEnabled($event);
        }

        if ($this->isAdmin($user)) {
            return true;
        }

        return match ($event->event_type) {
            'circle_meeting' => CircleMember::query()
                ->where('circle_id', $event->circle_id)
                ->where('user_id', $user->id)
                ->whereIn('status', ['approved', 'active'])
                ->whereNull('deleted_at')
                ->exists(),
            'global_event', 'public_event' => true,
            default => true,
        };
    }

    public function canRegister(Event $event, ?User $user): array
    {
        if ($user && ! $this->memberRegistrationEnabled($event) && ! $this->isAdmin($user)) {
            return ['can_register' => false, 'reason' => 'Member registration is not enabled for this event.'];
        }

        if (! $this->isEligible($event, $user)) {
            return ['can_register' => false, 'reason' => 'User is not eligible for this event.'];
        }

        return ['can_register' => true, 'reason' => null];
    }

    public function visitorRegistrationEnabled(Event $event): bool
    {
        return (bool) ($event->visitor_registration_enabled ?? false) || $event->event_type === 'public_event' || (bool) ($event->is_public ?? false);
    }

    public function memberRegistrationEnabled(Event $event): bool
    {
        return $event->member_registration_enabled === null ? true : (bool) $event->member_registration_enabled;
    }

    public function isAdmin(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        $allowedKeys = $this->allowedAdminRoleKeys();
        if ($allowedKeys === []) {
            return false;
        }

        return $user->roles()->whereIn('key', $allowedKeys)->exists();
    }

    private function allowedAdminRoleKeys(): array
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('admin_user_roles')) {
            return [];
        }

        $validRoleKeys = Role::query()
            ->pluck('key')
            ->map(fn ($key): string => (string) $key)
            ->all();

        return array_values(array_intersect(self::EVENT_ADMIN_ROLES, $validRoleKeys));
    }

    public function canViewAttendance(Event $event, ?User $user): bool
    {
        if (! $user) {
            return false;
        }
        if ($this->isAdmin($user)) {
            return true;
        }
        if ($event->created_by_user_id === $user->id || $event->organizer_user_id === $user->id) {
            return true;
        }
        if (! $event->circle_id || ! Schema::hasColumn('circle_members', 'role')) {
            return false;
        }

        return CircleMember::query()
            ->where('circle_id', $event->circle_id)
            ->where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->whereIn(DB::raw('role::text'), self::CIRCLE_LEADER_ROLES)
            ->exists();
    }

    public function attendanceReport(Event $event, array $filters = []): array
    {
        $query = EventRegistration::query()
            ->with(['user', 'occurrence', 'invitedByUser', 'businessCategoryMain', 'businessCategorySub'])
            ->where('event_id', $event->id)
            ->when($filters['occurrence_id'] ?? null, fn ($q, $v) => $q->where('occurrence_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['checkin_status'] ?? null, fn ($q, $v) => $q->where('checkin_status', $v))
            ->when(($filters['attendee_type'] ?? null) === 'member', fn ($q) => $q->whereNotNull('user_id'))
            ->when(($filters['attendee_type'] ?? null) === 'visitor', fn ($q) => $q->whereNull('user_id'))
            ->when($filters['search'] ?? null, function ($q, $search): void {
                $term = '%'.strtolower($search).'%';
                $q->where(function ($inner) use ($term): void {
                    $inner->whereRaw('LOWER(COALESCE(visitor_name, \'\')) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(COALESCE(visitor_email, \'\')) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(COALESCE(visitor_phone, \'\')) LIKE ?', [$term])
                        ->orWhereHas('user', function ($userQuery) use ($term): void {
                            $userQuery->whereRaw('LOWER(COALESCE(display_name, \'\')) LIKE ?', [$term])
                                ->orWhereRaw('LOWER(COALESCE(email, \'\')) LIKE ?', [$term])
                                ->orWhereRaw('LOWER(COALESCE(phone, \'\')) LIKE ?', [$term]);
                        });
                });
            })
            ->latest('registered_at');

        $registrations = $query->get();

        return [
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'event_type' => $event->event_type,
                'mode' => $event->mode,
                'circle_id' => $event->circle_id,
            ],
            'summary' => [
                'total_registered' => $registrations->filter(fn ($row) => $row->status === 'registered' || $row->payment_status === 'paid' || (! $row->payment_required && $row->status !== 'cancelled'))->count(),
                'total_checked_in' => $registrations->where('checkin_status', 'checked_in')->count(),
                'total_pending' => $registrations->where('checkin_status', 'pending')->where('status', '!=', 'cancelled')->count(),
                'total_cancelled' => $registrations->where('status', 'cancelled')->count(),
                'total_members' => $registrations->whereNotNull('user_id')->count(),
                'total_visitors' => $registrations->whereNull('user_id')->count(),
            ],
            'items' => $registrations->map(fn (EventRegistration $registration) => $this->attendanceItem($registration))->values(),
        ];
    }

    public function attendanceItem(EventRegistration $registration): array
    {
        $user = $registration->user;

        return [
            'registration_id' => $registration->id,
            'attendee_type' => $registration->user_id ? 'member' : 'visitor',
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->display_name ?? trim(($user->first_name ?? '').' '.($user->last_name ?? '')),
                'email' => $user->email,
                'phone' => $user->phone ?? null,
                'company_name' => $user->company_name ?? null,
                'city' => $user->city ?? $user->business_city ?? null,
            ] : null,
            'visitor' => $registration->user_id ? null : [
                'name' => $registration->visitor_name,
                'email' => $registration->visitor_email,
                'phone' => $registration->visitor_phone,
                'company' => $registration->visitor_company,
                'city' => $registration->visitor_city,
                'designation' => $registration->visitor_designation ?? data_get($registration->metadata, 'visitor_designation'),
                'business_category_id' => $registration->visitor_business_category_id ?? data_get($registration->metadata, 'visitor_business_category_id'),
                'business_category' => $registration->visitor_business_category ?? data_get($registration->metadata, 'visitor_business_category'),
                'business_category_main' => $registration->businessCategoryMainPayload(),
                'business_category_sub' => $registration->businessCategorySubPayload(),
                'business_website' => $registration->visitor_business_website ?? data_get($registration->metadata, 'visitor_business_website'),
                'business_brief' => $registration->visitor_business_brief ?? data_get($registration->metadata, 'visitor_business_brief'),
            ],
            'status' => $registration->status,
            'checkin_status' => $registration->checkin_status,
            'registered_at' => optional($registration->registered_at)->toISOString(),
            'checked_in_at' => optional($registration->checked_in_at)->toISOString(),
            'source' => $registration->source,
            'visitor_designation' => $registration->visitor_designation ?? data_get($registration->metadata, 'visitor_designation'),
            'visitor_business_category_id' => $registration->visitor_business_category_id ?? data_get($registration->metadata, 'visitor_business_category_id'),
            'visitor_business_category' => $registration->visitor_business_category ?? data_get($registration->metadata, 'visitor_business_category'),
            'visitor_business_category_main_id' => $registration->visitor_business_category_main_id ?? data_get($registration->metadata, 'visitor_business_category_main_id'),
            'visitor_business_category_sub_id' => $registration->visitor_business_category_sub_id ?? data_get($registration->metadata, 'visitor_business_category_sub_id') ?? $registration->visitor_business_category_id ?? data_get($registration->metadata, 'visitor_business_category_id'),
            'business_category_main' => $registration->businessCategoryMainPayload(),
            'business_category_sub' => $registration->businessCategorySubPayload(),
            'visitor_business_website' => $registration->visitor_business_website ?? data_get($registration->metadata, 'visitor_business_website'),
            'visitor_business_brief' => $registration->visitor_business_brief ?? data_get($registration->metadata, 'visitor_business_brief'),
            'invited_by_type' => $registration->invited_by_type ?? data_get($registration->metadata, 'invited_by_type'),
            'invited_by_user_id' => $registration->invited_by_user_id ?? data_get($registration->metadata, 'invited_by_user_id'),
            'invited_by_user' => $this->invitedByUserPayload($registration->invitedByUser),
            'payment_gateway' => ($registration->payment_required ?? false) ? (string) config('services.event_payment_gateway', 'zoho') : null,
            'payment_url' => $registration->payment_url ?? $registration->zoho_hosted_page_url ?? null,
            'payment_status' => $registration->payment_status ?? null,
            'razorpay_order_id' => $registration->razorpay_order_id ?? null,
            'razorpay_payment_id' => $registration->razorpay_payment_id ?? null,
            'payment_required' => (bool) ($registration->payment_required ?? false),
            'amount' => $registration->amount !== null ? (string) $registration->amount : null,
            'currency' => $registration->currency ?? null,
            'zoho_invoice_id' => $registration->zoho_invoice_id ?? null,
            'zoho_invoice_number' => $registration->zoho_invoice_number ?? null,
            'invoice_url' => $registration->zoho_invoice_url ?? null,
            'invoice_pdf_url' => $registration->zoho_invoice_pdf_url ?? null,
            'invoice_sync_status' => $registration->zoho_invoice_id ? 'synced' : ($registration->zoho_invoice_sync_error ? 'failed' : 'pending'),
            'zoho_invoice_sync_error' => $registration->zoho_invoice_sync_error ?? null,
            'qr_status' => empty($registration->qr_code_path) && empty($registration->qr_code_url) ? 'not_generated' : 'generated',
            'qr_code_url' => ($registration->payment_required ?? false) && ($registration->payment_status ?? null) !== 'paid' ? null : ($registration->qr_code_path ? app(EventQrService::class)->url($registration->qr_code_path) : $registration->qr_code_url),
        ];
    }


    private function invitedByUserPayload(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'display_name' => $user->display_name ?: trim(($user->first_name ?? '').' '.($user->last_name ?? '')),
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'company_name' => $user->company_name,
            'designation' => $user->designation,
            'profile_photo_url' => $user->profile_photo_url ?? null,
        ];
    }

    private function normalizeMetadata(mixed $metadata): array
    {
        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            return is_array($decoded) ? $decoded : [];
        }

        if (is_object($metadata)) {
            $metadata = (array) $metadata;
        }

        return is_array($metadata) ? $metadata : [];
    }

    private function normalizeRows(mixed $rows): array
    {
        if (is_string($rows)) {
            $decoded = json_decode($rows, true);
            return is_array($decoded) ? $decoded : [];
        }

        return is_array($rows) ? $rows : [];
    }

    private function cleanTextRows(array $rows): array
    {
        return collect($rows)->map(fn ($row) => trim((string) $row))->filter(fn ($row) => $row !== '')->values()->all();
    }

    private function cleanAgenda(array $rows): array
    {
        return collect($rows)->map(fn ($row) => [
            'time' => trim((string) ($row['time'] ?? '')),
            'title' => trim((string) ($row['title'] ?? '')),
        ])->filter(fn ($row) => $row['time'] !== '' || $row['title'] !== '')->values()->all();
    }

    private function cleanSpeakers(array $rows): array
    {
        return collect($rows)->map(fn ($row) => [
            'name' => trim((string) ($row['name'] ?? '')),
            'designation' => trim((string) ($row['designation'] ?? '')),
            'company' => trim((string) ($row['company'] ?? '')),
            'initials' => trim((string) ($row['initials'] ?? '')),
            'photo_url' => filled($row['photo_url'] ?? null) ? trim((string) $row['photo_url']) : null,
        ])->filter(fn ($row) => $row['name'] !== '' || $row['designation'] !== '' || $row['company'] !== '' || $row['initials'] !== '' || filled($row['photo_url']))->values()->all();
    }

    public function filterEventColumns(array $data): array
    {
        return array_filter($data, fn ($value, $key) => Schema::hasColumn('events', $key), ARRAY_FILTER_USE_BOTH);
    }

    private function normalize(array $data, ?User $actor, bool $withDefaults = true): array
    {
        if ($actor && empty($data['created_by_user_id'])) {
            $data['created_by_user_id'] = $actor->id;
        }
        if ($actor && empty($data['organizer_user_id'])) {
            $data['organizer_user_id'] = $actor->id;
        }
        $hasMetadataInput = array_key_exists('metadata', $data)
            || ! empty($data['zoho_form_url'])
            || array_key_exists('what_youll_gain', $data)
            || array_key_exists('organizer_name', $data)
            || array_key_exists('organizer_phone', $data)
            || array_key_exists('organizer_email', $data)
            || array_key_exists('organizer_website', $data);

        if ($hasMetadataInput) {
            $metadata = $this->normalizeMetadata($data['metadata'] ?? []);
            if (! empty($data['zoho_form_url'])) {
                $metadata['zoho_form_url'] = $data['zoho_form_url'];
            }
            if (array_key_exists('what_youll_gain', $data)) {
                $metadata['what_youll_gain'] = $this->cleanTextRows((array) $data['what_youll_gain']);
                unset($data['what_youll_gain']);
            }
            if (array_key_exists('organizer_name', $data) || array_key_exists('organizer_phone', $data) || array_key_exists('organizer_email', $data) || array_key_exists('organizer_website', $data)) {
                $metadata['organizer'] = [
                    'name' => $data['organizer_name'] ?? null,
                    'phone' => $data['organizer_phone'] ?? null,
                    'email' => $data['organizer_email'] ?? null,
                    'website' => $data['organizer_website'] ?? null,
                ];
                unset($data['organizer_name'], $data['organizer_phone'], $data['organizer_email'], $data['organizer_website']);
            }
            $data['metadata'] = $metadata;
        }
        if (array_key_exists('agenda', $data)) {
            $data['agenda'] = $this->cleanAgenda($this->normalizeRows($data['agenda']));
        }
        if (array_key_exists('speakers', $data)) {
            $data['speakers'] = $this->cleanSpeakers($this->normalizeRows($data['speakers']));
        }

        if ($withDefaults) {
            $data['event_type'] = $data['event_type'] ?? ($data['circle_id'] ? 'circle_meeting' : 'global_event');
            $data['mode'] = $data['mode'] ?? (($data['is_virtual'] ?? false) ? 'online' : 'offline');
            $data['visibility'] = $data['visibility'] ?? 'public';
            $data['recurrence_type'] = $data['recurrence_type'] ?? 'none';
            $data['recurrence_interval'] = $data['recurrence_interval'] ?? 1;
            $data['qr_checkin_enabled'] = $data['qr_checkin_enabled'] ?? true;
            $data['is_public'] = $data['is_public'] ?? (($data['event_type'] ?? null) === 'public_event');
            $data['is_paid'] = $data['is_paid'] ?? false;
            $data['visitor_registration_enabled'] = $data['visitor_registration_enabled'] ?? (($data['event_type'] ?? null) === 'public_event');
            $data['member_registration_enabled'] = $data['member_registration_enabled'] ?? true;
        }

        return $data;
    }
}
