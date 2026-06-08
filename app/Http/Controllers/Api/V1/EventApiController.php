<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\Circle;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventQrScanLog;
use App\Models\EventRegistration;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class EventApiController extends BaseApiController
{
    public function allEvents(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'circle_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'string', Rule::in(['all', 'today', 'live', 'upcoming'])],
        ]);

        $circleId = $validated['circle_id'] ?? null;
        $status = $validated['status'] ?? 'all';
        $circle = null;

        if ($circleId) {
            $circle = Circle::query()->find($circleId);

            if (! $circle) {
                return response()->json([
                    'success' => false,
                    'message' => 'Circle not found.',
                    'data' => null,
                ], 404);
            }
        }

        $events = Event::query()
            ->with([
                'circle',
                'occurrences' => fn ($query) => $query->orderBy('start_at'),
            ])
            ->when($circleId, fn ($query) => $query->where('circle_id', $circleId));

        $this->applyActiveEventScope($events);

        $eventItems = $events->get()
            ->flatMap(fn (Event $event) => $this->expandEventItems($event))
            ->sortBy('_start_at')
            ->values();

        $grouped = $this->groupEventItems($eventItems);

        if ($status !== 'all') {
            $grouped = [
                'today_events' => $status === 'today' ? $grouped['today_events'] : [],
                'live_events' => $status === 'live' ? $grouped['live_events'] : [],
                'upcoming_events' => $status === 'upcoming' ? $grouped['upcoming_events'] : [],
            ];
        }

        $total = count($grouped['today_events']) + count($grouped['live_events']) + count($grouped['upcoming_events']);

        return $this->success([
            'circle' => $circle ? $this->circlePayload($circle) : null,
            'total' => $total,
            'today_events' => $grouped['today_events'],
            'live_events' => $grouped['live_events'],
            'upcoming_events' => $grouped['upcoming_events'],
        ], 'Circle events fetched successfully.');
    }

    private function applyActiveEventScope($query): void
    {
        if (Schema::hasColumn('events', 'is_active')) {
            $query->where('is_active', true);
        }

        if (Schema::hasColumn('events', 'status')) {
            $query->whereNotIn('status', ['cancelled', 'canceled', 'rejected', 'deleted', 'archived', 'inactive']);
        }
    }

    private function expandEventItems(Event $event): Collection
    {
        if ($event->occurrences->isNotEmpty()) {
            return $event->occurrences->map(fn (EventOccurrence $occurrence) => $this->eventItemPayload(
                $event,
                $occurrence,
                $occurrence->start_at,
                $occurrence->end_at
            ));
        }

        return collect([
            $this->eventItemPayload($event, null, $event->start_at, $event->end_at),
        ]);
    }

    private function groupEventItems(Collection $eventItems): array
    {
        $now = Carbon::now();
        $today = $now->toDateString();

        $liveEvents = [];
        $todayEvents = [];
        $upcomingEvents = [];

        foreach ($eventItems as $item) {
            $startAt = $item['_start_at'];
            $endAt = $item['_end_at'];

            if (! $startAt instanceof Carbon) {
                continue;
            }

            $isLive = $endAt instanceof Carbon && $startAt->lessThanOrEqualTo($now) && $endAt->greaterThanOrEqualTo($now);
            $payload = $item['payload'];

            if ($isLive) {
                $liveEvents[] = $payload;
                continue;
            }

            if ($startAt->toDateString() === $today) {
                $todayEvents[] = $payload;
                continue;
            }

            if ($startAt->greaterThan($now) && $startAt->toDateString() > $today) {
                $upcomingEvents[] = $payload;
            }
        }

        return [
            'today_events' => $todayEvents,
            'live_events' => $liveEvents,
            'upcoming_events' => $upcomingEvents,
        ];
    }

    private function eventItemPayload(Event $event, ?EventOccurrence $occurrence, ?Carbon $startAt, ?Carbon $endAt): array
    {
        $occurrenceId = $occurrence?->id;
        $circlePayload = $event->circle ? $this->circlePayload($event->circle) : null;

        return [
            '_start_at' => $startAt,
            '_end_at' => $endAt,
            'payload' => [
                'event_id' => $event->id,
                'occurrence_id' => $occurrenceId,
                'title' => $event->title,
                'description' => $event->description,
                'event_type' => $event->event_type,
                'event_category' => $event->event_category,
                'mode' => $event->mode,
                'start_at' => $startAt?->toISOString(),
                'end_at' => $endAt?->toISOString(),
                'formatted_start_at' => $startAt?->format('d M Y h:i A'),
                'recurrence' => $event->recurrence_type,
                'status' => $occurrence?->status ?? $event->status ?? 'scheduled',
                'registered_count' => $this->registeredCount($event->id, $occurrenceId),
                'checked_in_count' => $this->checkedInCount($event->id, $occurrenceId),
                'image_url' => $this->imageUrl($event),
                'location' => $event->location_text,
                'meeting_link' => $event->online_meeting_url,
                'circle' => $circlePayload,
            ],
        ];
    }

    private function registeredCount(string $eventId, ?string $occurrenceId): int
    {
        $query = EventRegistration::query()
            ->where('event_id', $eventId)
            ->whereNotIn('status', ['cancelled', 'canceled', 'rejected', 'payment_failed']);

        if ($occurrenceId) {
            $query->where('occurrence_id', $occurrenceId);
        }

        return $query->count();
    }

    private function checkedInCount(string $eventId, ?string $occurrenceId): int
    {
        if (! Schema::hasTable('event_qr_scan_logs')) {
            return EventRegistration::query()
                ->where('event_id', $eventId)
                ->when($occurrenceId, fn ($query) => $query->where('occurrence_id', $occurrenceId))
                ->where('checkin_status', 'checked_in')
                ->count();
        }

        $query = EventQrScanLog::query()
            ->where('event_id', $eventId);

        if (Schema::hasColumn('event_qr_scan_logs', 'scan_status')) {
            $query->whereIn('scan_status', ['success', 'already_checked_in']);
        }

        if ($occurrenceId && Schema::hasColumn('event_qr_scan_logs', 'occurrence_id')) {
            $query->where('occurrence_id', $occurrenceId);
        }

        return $query->count();
    }

    private function imageUrl(Event $event): ?string
    {
        $bannerUrl = $event->banner_url;

        if (! is_string($bannerUrl) || trim($bannerUrl) === '') {
            return null;
        }

        $bannerUrl = trim($bannerUrl);

        if (str_starts_with($bannerUrl, 'http://') || str_starts_with($bannerUrl, 'https://') || str_starts_with($bannerUrl, '/')) {
            return $bannerUrl;
        }

        return url('/api/v1/files/' . $bannerUrl);
    }

    private function circlePayload(Circle $circle): array
    {
        return [
            'id' => $circle->id,
            'name' => $circle->name,
            'slug' => $circle->slug,
        ];
    }
}
