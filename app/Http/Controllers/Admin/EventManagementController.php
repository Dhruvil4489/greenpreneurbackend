<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Circle;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Services\Events\EventOccurrenceGeneratorService;
use App\Services\Events\EventService;
use App\Services\Events\EventZohoInvoiceSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class EventManagementController extends Controller
{
    public function __construct(
        private readonly EventOccurrenceGeneratorService $occurrences,
        private readonly EventService $events,
        private readonly EventZohoInvoiceSyncService $zohoInvoiceSync,
    ) {}

    public function index(Request $request): View
    {
        $events = Event::query()
            ->with('circle')
            ->withCount(['registrations as registered_count' => fn ($q) => $q->where('status', '!=', 'cancelled')])
            ->withCount(['registrations as checked_in_count' => fn ($q) => $q->where('checkin_status', 'checked_in')])
            ->when($request->event_type, fn ($q, $v) => $q->where('event_type', $v))
            ->when($request->circle_id, fn ($q, $v) => $q->where('circle_id', $v))
            ->when($request->mode, fn ($q, $v) => $q->where('mode', $v))
            ->when($request->date_from, fn ($q, $v) => $q->where('start_at', '>=', $v))
            ->when($request->date_to, fn ($q, $v) => $q->where('start_at', '<=', $v))
            ->when($request->search, fn ($q, $v) => $q->where('title', 'ilike', '%'.$v.'%'))
            ->latest('start_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.events.index', ['events' => $events, 'circles' => Circle::query()->orderBy('name')->get(['id', 'name'])]);
    }

    public function create(): View
    {
        return view('admin.events.create', ['circles' => Circle::query()->orderBy('name')->get(['id', 'name'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $event = DB::transaction(function () use ($data): Event {
            $event = Event::query()->create($this->filterColumns($this->withDefaults($data)));
            $this->occurrences->generate($event);

            return $event;
        });

        return redirect()->route('admin.events.show', $event)->with('success', 'Event created successfully.');
    }

    public function show(string $id): View
    {
        $event = Event::query()->with(['circle', 'occurrences' => fn ($q) => $q->orderBy('start_at'), 'registrations.user', 'registrations.occurrence'])->findOrFail($id);

        return view('admin.events.show', compact('event'));
    }

    public function attendance(Request $request, string $id): View
    {
        $event = Event::query()->findOrFail($id);
        $report = $this->events->attendanceReport($event, $request->only(['occurrence_id', 'status', 'checkin_status', 'attendee_type', 'search']));

        return view('admin.events.attendance', compact('event', 'report'));
    }


    public function syncZohoInvoice(string $registrationId): RedirectResponse
    {
        $registration = EventRegistration::query()->with(['event', 'occurrence', 'user'])->findOrFail($registrationId);
        $this->zohoInvoiceSync->sync($registration);

        return back()->with('success', 'Zoho invoice sync queued/completed for registration.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'event_type' => ['required', 'string', 'in:circle_meeting,global_event,public_event,training'],
            'event_category' => ['nullable', 'string', 'max:100'],
            'circle_id' => ['nullable', 'uuid', 'exists:circles,id'],
            'mode' => ['required', 'string', 'in:offline,online,hybrid'],
            'location_text' => ['nullable', 'string'],
            'venue_name' => ['nullable', 'string', 'max:255'],
            'address_line' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'google_maps_url' => ['nullable', 'string', 'max:2000'],
            'online_meeting_url' => ['nullable', 'string', 'max:2000'],
            'start_at' => ['required', 'date'],
            'end_at' => ['nullable', 'date', 'after:start_at'],
            'recurrence_type' => ['required', 'string', 'in:none,weekly,monthly,yearly'],
            'recurrence_interval' => ['nullable', 'integer', 'min:1'],
            'recurrence_week_of_month' => ['nullable', 'integer', 'min:1', 'max:5'],
            'recurrence_day_of_week' => ['nullable', 'integer', 'min:1', 'max:7'],
            'recurrence_day_of_month' => ['nullable', 'integer', 'min:1', 'max:31'],
            'recurrence_month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'recurrence_ends_at' => ['nullable', 'date'],
            'registration_limit' => ['nullable', 'integer', 'min:1'],
            'is_paid' => ['nullable', 'boolean'],
            'ticket_price' => ['nullable', 'numeric', 'min:0'],
            'qr_checkin_enabled' => ['nullable', 'boolean'],
            'visitor_registration_enabled' => ['nullable', 'boolean'],
            'member_registration_enabled' => ['nullable', 'boolean'],
            'zoho_form_url' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function withDefaults(array $data): array
    {
        $locationMeta = array_filter([
            'venue_name' => $data['venue_name'] ?? null,
            'address_line' => $data['address_line'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'google_maps_url' => $data['google_maps_url'] ?? null,
            'zoho_form_url' => $data['zoho_form_url'] ?? null,
        ], fn ($value) => filled($value));

        $locationParts = array_filter([
            $data['venue_name'] ?? null,
            $data['address_line'] ?? null,
            $data['city'] ?? null,
            $data['state'] ?? null,
        ], fn ($value) => filled($value));

        if (blank($data['location_text'] ?? null) && $locationParts) {
            $data['location_text'] = implode(', ', $locationParts);
        }

        if ($locationMeta) {
            $data['metadata'] = array_merge((array) ($data['metadata'] ?? []), $locationMeta);
        }

        unset($data['venue_name'], $data['address_line'], $data['city'], $data['state'], $data['google_maps_url']);

        foreach (['is_paid', 'qr_checkin_enabled', 'visitor_registration_enabled', 'member_registration_enabled'] as $key) {
            $data[$key] = (bool) ($data[$key] ?? false);
        }
        $data['member_registration_enabled'] = (bool) ($data['member_registration_enabled'] ?? true);
        $data['visibility'] = 'public';
        $data['is_virtual'] = in_array($data['mode'] ?? 'offline', ['online', 'hybrid'], true);
        $data['is_public'] = ($data['event_type'] ?? null) === 'public_event';
        if (($data['event_type'] ?? null) === 'training') {
            $data['event_category'] = $data['event_category'] ?: 'training';
        }

        return $data;
    }

    private function filterColumns(array $data): array
    {
        return array_filter($data, fn ($value, $key) => Schema::hasColumn('events', $key), ARRAY_FILTER_USE_BOTH);
    }
}
