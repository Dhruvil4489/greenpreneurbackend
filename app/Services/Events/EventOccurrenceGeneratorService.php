<?php

namespace App\Services\Events;

use App\Models\Event;
use App\Models\EventOccurrence;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EventOccurrenceGeneratorService
{
    public function generate(Event $event): Collection
    {
        return DB::transaction(function () use ($event): Collection {
            $event->refresh();
            $starts = $this->buildStarts($event);
            $durationSeconds = max(0, CarbonImmutable::parse($event->end_at ?? $event->start_at)->diffInSeconds(CarbonImmutable::parse($event->start_at), true));
            $created = collect();
            $sequence = (int) $event->occurrences()->withTrashed()->max('sequence');

            foreach ($starts as $occurrenceStart) {
                $occurrenceEnd = $durationSeconds > 0 ? $occurrenceStart->addSeconds($durationSeconds) : null;
                $occurrenceDate = $occurrenceStart->toDateString();
                $exists = $event->occurrences()
                    ->withTrashed()
                    ->where('occurrence_date', $occurrenceDate)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $sequence++;
                $payload = [
                    'event_id' => $event->id,
                    'sequence' => $sequence,
                    'occurrence_date' => $occurrenceDate,
                    'start_at' => $occurrenceStart,
                    'end_at' => $occurrenceEnd,
                    'status' => 'scheduled',
                ];
                if (Schema::hasColumn('event_occurrences', 'registration_limit')) {
                    $payload['registration_limit'] = $event->registration_limit;
                }
                if (Schema::hasColumn('event_occurrences', 'registered_count')) {
                    $payload['registered_count'] = 0;
                }
                if (Schema::hasColumn('event_occurrences', 'checked_in_count')) {
                    $payload['checked_in_count'] = 0;
                }
                $created->push(EventOccurrence::query()->create($payload));
            }

            return $created;
        });
    }

    public function regenerateFuture(Event $event): Collection
    {
        return DB::transaction(function () use ($event): Collection {
            $event->occurrences()
                ->where('start_at', '>=', now())
                ->whereDoesntHave('registrations')
                ->delete();

            return $this->generate($event);
        });
    }

    private function buildStarts(Event $event): array
    {
        $type = $event->recurrence_type ?: 'none';
        $start = CarbonImmutable::parse($event->start_at);
        $until = min(
            $event->recurrence_ends_at ? CarbonImmutable::parse($event->recurrence_ends_at) : $start->addMonthsNoOverflow(12),
            $start->addMonthsNoOverflow(12)
        );
        $interval = max(1, (int) ($event->recurrence_interval ?: 1));

        return match ($type) {
            'weekly' => $this->weekly($start, $until, $interval, $event->recurrence_day_of_week),
            'monthly' => $this->monthly($start, $until, $interval, $event->recurrence_day_of_month, $event->recurrence_week_of_month, $event->recurrence_day_of_week),
            'yearly' => $this->yearly($start, $until, $interval, $event->recurrence_month, $event->recurrence_day_of_month, $event->recurrence_week_of_month, $event->recurrence_day_of_week),
            default => [$start],
        };
    }

    private function weekly(CarbonImmutable $start, CarbonImmutable $until, int $interval, ?int $dayOfWeek): array
    {
        $targetDow = $this->normalizeDayOfWeek($dayOfWeek ?? $start->dayOfWeek);
        $cursor = $start->startOfWeek(CarbonInterface::SUNDAY)->addDays($targetDow)->setTimeFrom($start);
        if ($cursor->lt($start)) {
            $cursor = $cursor->addWeek();
        }

        $dates = [];
        while ($cursor->lte($until)) {
            $dates[] = $cursor;
            $cursor = $cursor->addWeeks($interval);
        }

        return $dates;
    }

    private function monthly(CarbonImmutable $start, CarbonImmutable $until, int $interval, ?int $dayOfMonth, ?int $weekOfMonth, ?int $dayOfWeek): array
    {
        $dates = [];
        $cursor = $start->startOfMonth();

        while ($cursor->lte($until)) {
            $candidate = $this->monthlyCandidate($cursor, $start, $dayOfMonth, $weekOfMonth, $dayOfWeek);
            if ($candidate && $candidate->gte($start) && $candidate->lte($until)) {
                $dates[] = $candidate;
            }
            $cursor = $cursor->addMonthsNoOverflow($interval);
        }

        return $dates;
    }

    private function yearly(CarbonImmutable $start, CarbonImmutable $until, int $interval, ?int $month, ?int $dayOfMonth, ?int $weekOfMonth, ?int $dayOfWeek): array
    {
        $dates = [];
        $targetMonth = $month ?: $start->month;
        $cursor = $start->startOfYear()->month($targetMonth)->startOfMonth();

        while ($cursor->lte($until)) {
            $candidate = $this->monthlyCandidate($cursor, $start, $dayOfMonth, $weekOfMonth, $dayOfWeek);
            if ($candidate && $candidate->gte($start) && $candidate->lte($until)) {
                $dates[] = $candidate;
            }
            $cursor = $cursor->addYears($interval);
        }

        return $dates;
    }


    private function normalizeDayOfWeek(int $dayOfWeek): int
    {
        return $dayOfWeek === 7 ? 0 : $dayOfWeek;
    }

    private function monthlyCandidate(CarbonImmutable $month, CarbonImmutable $timeSource, ?int $dayOfMonth, ?int $weekOfMonth, ?int $dayOfWeek): ?CarbonImmutable
    {
        if ($weekOfMonth && $dayOfWeek !== null) {
            $targetDayOfWeek = $this->normalizeDayOfWeek($dayOfWeek);
            $candidate = $month->startOfMonth();
            while ($candidate->dayOfWeek !== $targetDayOfWeek) {
                $candidate = $candidate->addDay();
            }
            $candidate = $candidate->addWeeks($weekOfMonth - 1);

            return $candidate->month === $month->month ? $candidate->setTimeFrom($timeSource) : null;
        }

        $day = min($dayOfMonth ?: $timeSource->day, $month->daysInMonth);

        return $month->day($day)->setTimeFrom($timeSource);
    }
}
