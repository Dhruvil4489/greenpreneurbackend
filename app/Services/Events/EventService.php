<?php

namespace App\Services\Events;

use App\Models\CircleMember;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class EventService
{
    public function __construct(private readonly EventOccurrenceGeneratorService $occurrenceGenerator) {}

    public function create(array $data, User $actor): Event
    {
        return DB::transaction(function () use ($data, $actor): Event {
            $data = $this->normalize($data, $actor);
            $event = Event::query()->create($data);
            $this->occurrenceGenerator->generate($event);

            return $event->load(['circle', 'occurrences']);
        });
    }

    public function update(Event $event, array $data): Event
    {
        return DB::transaction(function () use ($event, $data): Event {
            $event->fill($this->normalize($data, null, false));
            $event->save();
            $this->occurrenceGenerator->regenerateFuture($event);

            return $event->load(['circle', 'occurrences']);
        });
    }

    public function listOccurrences(array $filters, ?User $user = null, int $perPage = 20): LengthAwarePaginator
    {
        $query = EventOccurrence::query()
            ->with(['event.circle', 'registrations' => fn ($q) => $user ? $q->where('user_id', $user->id) : $q->whereRaw('1 = 0')])
            ->withCount(['registrations as registered_count' => fn ($q) => $q->where('status', '!=', 'cancelled')])
            ->whereHas('event', function (Builder $eventQuery) use ($filters): void {
                $eventQuery->when($filters['event_type'] ?? null, fn ($q, $v) => $q->where('event_type', $v))
                    ->when($filters['circle_id'] ?? null, fn ($q, $v) => $q->where('circle_id', $v))
                    ->when($filters['mode'] ?? null, fn ($q, $v) => $q->where('mode', $v));
            });

        if (($filters['upcoming'] ?? 'true') !== 'false') {
            $query->where('start_at', '>=', now());
        }

        $query->when($filters['from_date'] ?? null, fn ($q, $v) => $q->where('start_at', '>=', $v))
            ->when($filters['to_date'] ?? null, fn ($q, $v) => $q->where('start_at', '<=', $v));

        return $query->orderBy('start_at')->paginate($perPage);
    }

    public function isEligible(Event $event, ?User $user): bool
    {
        if (! $user) {
            return $event->event_type === 'public_event';
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

    public function isAdmin(User $user): bool
    {
        return $user->roles()->whereIn('key', ['global_admin', 'industry_director', 'ded', 'circle_leader', 'founder', 'director', 'chair', 'vice_chair', 'secretary'])->exists();
    }

    public function attendanceReport(Event $event): array
    {
        $registrations = $event->registrations()->with(['user', 'occurrence'])->latest('registered_at')->get();
        $endedOccurrenceIds = $event->occurrences()->where('end_at', '<', now())->pluck('id');

        return [
            'total_registered' => $registrations->where('status', '!=', 'cancelled')->count(),
            'total_checked_in' => $registrations->where('checkin_status', 'checked_in')->count(),
            'total_pending' => $registrations->where('checkin_status', 'pending')->where('status', '!=', 'cancelled')->count(),
            'total_visitors' => $registrations->whereNull('user_id')->count(),
            'total_members' => $registrations->whereNotNull('user_id')->count(),
            'no_show' => $registrations->whereIn('occurrence_id', $endedOccurrenceIds)->where('checkin_status', 'pending')->where('status', '!=', 'cancelled')->count(),
            'items' => $registrations,
        ];
    }

    private function normalize(array $data, ?User $actor, bool $withDefaults = true): array
    {
        if ($actor && empty($data['created_by_user_id'])) {
            $data['created_by_user_id'] = $actor->id;
        }
        if ($actor && empty($data['organizer_user_id'])) {
            $data['organizer_user_id'] = $actor->id;
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
        }

        return $data;
    }
}
