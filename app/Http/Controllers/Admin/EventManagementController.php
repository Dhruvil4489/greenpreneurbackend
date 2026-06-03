<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Circle;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventRegistrationRequest;
use App\Models\EventQrScanLog;
use App\Models\FileModel;
use App\Services\Events\EventOccurrenceGeneratorService;
use App\Services\Events\EventService;
use App\Services\Events\EventZohoInvoiceSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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


    public function joiningRequests(Request $request): View
    {
        $status = $request->input('status', 'pending');
        $query = EventRegistrationRequest::query()
            ->with([
                'user.circleMemberships.circle',
                'event.circle',
                'occurrence',
                'registration',
                'approvedBy',
                'rejectedBy',
            ])
            ->when($status !== 'all' && $status !== '', fn ($q) => $q->where('status', $status))
            ->when($request->event_id, fn ($q, $v) => $q->where('event_id', $v))
            ->when($request->user_id, fn ($q, $v) => $q->where('user_id', $v))
            ->when($request->date_from, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($request->date_to, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when($request->search, function ($q, $term): void {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';
                $q->where(function ($inner) use ($like): void {
                    $inner->where('request_reason', 'ilike', $like)
                        ->orWhereHas('user', function ($userQuery) use ($like): void {
                            $userQuery->where('display_name', 'ilike', $like)
                                ->orWhere('first_name', 'ilike', $like)
                                ->orWhere('last_name', 'ilike', $like)
                                ->orWhere('email', 'ilike', $like)
                                ->orWhere('phone', 'ilike', $like)
                                ->orWhere('company_name', 'ilike', $like);
                        })
                        ->orWhereHas('event', fn ($eventQuery) => $eventQuery->where('title', 'ilike', $like));
                });
            });

        $summaryBase = EventRegistrationRequest::query();
        $summary = [
            'pending' => (clone $summaryBase)->where('status', 'pending')->count(),
            'approved' => (clone $summaryBase)->where('status', 'approved')->count(),
            'rejected' => (clone $summaryBase)->where('status', 'rejected')->count(),
            'total' => (clone $summaryBase)->count(),
        ];

        $requests = $query->latest('created_at')->paginate((int) $request->input('per_page', 20))->withQueryString();
        $events = Event::query()->orderBy('title')->get(['id', 'title']);

        return view('admin.events.joining-requests', compact('requests', 'summary', 'events', 'status'));
    }

    public function approveJoiningRequest(Request $request, string $id): RedirectResponse
    {
        $joiningRequest = EventRegistrationRequest::query()->findOrFail($id);
        $joiningRequest->forceFill([
            'status' => 'approved',
            'admin_note' => $request->input('admin_note', 'Approved for cross-circle event registration.'),
            'approved_by_user_id' => auth('admin')->id(),
            'approved_at' => now(),
        ])->save();

        return back()->with('success', 'Registration request approved successfully.');
    }

    public function rejectJoiningRequest(Request $request, string $id): RedirectResponse
    {
        $data = $request->validate(['admin_note' => ['required', 'string', 'max:2000']]);
        $joiningRequest = EventRegistrationRequest::query()->findOrFail($id);
        $joiningRequest->forceFill([
            'status' => 'rejected',
            'admin_note' => $data['admin_note'],
            'rejected_by_user_id' => auth('admin')->id(),
            'rejected_at' => now(),
        ])->save();

        return back()->with('success', 'Registration request rejected successfully.');
    }

    public function create(): View
    {
        return view('admin.events.create', ['circles' => Circle::query()->orderBy('name')->get(['id', 'name'])]);
    }

    public function edit(string $id): View
    {
        $event = Event::query()->findOrFail($id);

        return view('admin.events.create', [
            'event' => $event,
            'circles' => Circle::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->prepareEventData($request, $this->validated($request));
        $event = DB::transaction(function () use ($data): Event {
            $event = Event::query()->create($this->filterColumns($this->withDefaults($data)));
            $this->occurrences->generate($event);

            return $event;
        });

        return redirect()->route('admin.events.show', $event)->with('success', 'Event created successfully.');
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $event = Event::query()->findOrFail($id);
        $data = $this->prepareEventData($request, $this->validated($request), $event);

        DB::transaction(function () use ($event, $data): void {
            $event->fill($this->filterColumns($this->withDefaults($data)));
            $event->save();
            $this->occurrences->regenerateFuture($event);
        });

        return redirect()->route('admin.events.show', $event)->with('success', 'Event updated successfully.');
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
        $scanLogs = EventQrScanLog::query()
            ->with(['user', 'scanner'])
            ->where('event_id', $event->id)
            ->latest('scanned_at')
            ->limit(200)
            ->get();

        return view('admin.events.attendance', compact('event', 'report', 'scanLogs'));
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
            'banner' => ['nullable', 'image', 'max:5120'],
            'banner_url' => ['nullable', 'string', 'max:2000'],
            'agenda' => ['nullable', 'array'],
            'agenda.*.time' => ['nullable', 'string', 'max:100'],
            'agenda.*.title' => ['nullable', 'string', 'max:255'],
            'speakers' => ['nullable', 'array'],
            'speakers.*.name' => ['nullable', 'string', 'max:255'],
            'speakers.*.designation' => ['nullable', 'string', 'max:255'],
            'speakers.*.company' => ['nullable', 'string', 'max:255'],
            'speakers.*.initials' => ['nullable', 'string', 'max:20'],
            'speakers.*.photo_url' => ['nullable', 'string', 'max:2000'],
            'what_youll_gain' => ['nullable', 'array'],
            'what_youll_gain.*' => ['nullable', 'string', 'max:500'],
            'organizer_name' => ['nullable', 'string', 'max:255'],
            'organizer_phone' => ['nullable', 'string', 'max:50'],
            'organizer_email' => ['nullable', 'email', 'max:255'],
            'organizer_website' => ['nullable', 'string', 'max:255'],
        ]);
    }

    private function prepareEventData(Request $request, array $data, ?Event $event = null): array
    {
        $data['agenda'] = $this->cleanAgenda($data['agenda'] ?? []);
        $data['speakers'] = $this->cleanSpeakers($data['speakers'] ?? []);

        if ($request->hasFile('banner')) {
            $data['banner_url'] = $this->storeBanner($request);
        } elseif (array_key_exists('banner_url', $data)) {
            $data['banner_url'] = $data['banner_url'] ?: null;
        } elseif ($event) {
            $data['banner_url'] = $event->banner_url;
        }

        $metadata = $this->normalizeMetadata($event?->metadata ?? ($data['metadata'] ?? []));
        $metadata['what_youll_gain'] = $this->cleanGains($data['what_youll_gain'] ?? []);
        $metadata['organizer'] = [
            'name' => $data['organizer_name'] ?? null,
            'phone' => $data['organizer_phone'] ?? null,
            'email' => $data['organizer_email'] ?? null,
            'website' => $data['organizer_website'] ?? null,
        ];
        $data['metadata'] = $metadata;

        unset($data['banner'], $data['what_youll_gain'], $data['organizer_name'], $data['organizer_phone'], $data['organizer_email'], $data['organizer_website']);

        return $data;
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

    private function cleanGains(array $rows): array
    {
        return collect($rows)->map(fn ($row) => trim((string) $row))->filter(fn ($row) => $row !== '')->values()->all();
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

    private function storeBanner(Request $request): string
    {
        $path = $request->file('banner')->store('events/banners', 'public');

        $file = new FileModel();
        $file->s3_key = $path;
        $file->mime_type = Storage::disk('public')->mimeType($path);
        $file->size_bytes = Storage::disk('public')->size($path);
        [$width, $height] = @getimagesize(Storage::disk('public')->path($path)) ?: [null, null];
        $file->width = $width;
        $file->height = $height;
        $file->save();

        return url('/api/v1/files/'.$file->id);
    }

    private function filterColumns(array $data): array
    {
        return array_filter($data, fn ($value, $key) => Schema::hasColumn('events', $key), ARRAY_FILTER_USE_BOTH);
    }
}
