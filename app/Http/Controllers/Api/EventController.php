<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Event\EventCheckinRequest;
use App\Http\Requests\Event\EventRsvpRequest;
use App\Http\Requests\Event\RegisterEventOccurrenceRequest;
use App\Http\Requests\Event\ScanEventQrRequest;
use App\Http\Requests\Event\StoreEventRequest;
use App\Http\Requests\Event\VisitorEventRegistrationRequest;
use App\Http\Resources\Event\EventDetailResource;
use App\Http\Resources\Event\EventOccurrenceListResource;
use App\Http\Resources\Event\EventRegistrationResource;
use App\Http\Resources\EventResource;
use App\Http\Resources\EventRsvpResource;
use App\Models\CircleMember;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventRegistration;
use App\Models\EventRsvp;
use App\Services\Events\EventCheckinService;
use App\Services\Events\EventRegistrationService;
use App\Services\Events\EventService;
use Illuminate\Http\Request;

class EventController extends BaseApiController
{
    public function __construct(
        private readonly EventService $events,
        private readonly EventRegistrationService $registrations,
        private readonly EventCheckinService $checkins,
    ) {}

    public function index(Request $request)
    {
        $perPage = max(1, min((int) $request->input('per_page', 20), 100));
        $paginator = $this->events->listOccurrences($request->only(['event_type', 'circle_id', 'mode', 'from_date', 'to_date', 'upcoming']), $request->user(), $perPage);

        return $this->success([
            'total' => $paginator->total(),
            'items' => EventOccurrenceListResource::collection($paginator->getCollection()),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ], 'Events fetched successfully.');
    }

    public function show(Request $request, string $id)
    {
        $event = Event::query()
            ->with(['circle', 'occurrences' => fn ($q) => $q->with(['event.circle', 'registrations' => fn ($r) => $r->where('user_id', $request->user()?->id)])->withCount(['registrations as registered_count' => fn ($r) => $r->where('status', '!=', 'cancelled')])->orderBy('start_at')])
            ->find($id);

        if (! $event) {
            return $this->error('Event not found', 404);
        }

        return $this->success(new EventDetailResource($event), 'Event fetched successfully.');
    }

    public function register(RegisterEventOccurrenceRequest $request, string $eventId, string $occurrenceId)
    {
        $registration = $this->registrations->registerMember(
            Event::query()->findOrFail($eventId),
            EventOccurrence::query()->findOrFail($occurrenceId),
            $request->user(),
            $request->input('source', 'app')
        );

        return $this->success(new EventRegistrationResource($registration), 'Event registration successful.', 201);
    }

    public function visitorRegister(VisitorEventRegistrationRequest $request, string $eventId, string $occurrenceId)
    {
        $registration = $this->registrations->registerVisitor(
            Event::query()->findOrFail($eventId),
            EventOccurrence::query()->findOrFail($occurrenceId),
            $request->validated() + ['source' => $request->input('source', 'zoho_form')]
        );

        return $this->success(new EventRegistrationResource($registration), 'Visitor registration successful.', 201);
    }

    public function myRegistrations(Request $request)
    {
        $items = EventRegistration::query()
            ->with(['event.circle', 'occurrence', 'user'])
            ->where('user_id', $request->user()->id)
            ->latest('registered_at')
            ->paginate(max(1, min((int) $request->input('per_page', 20), 100)));

        return $this->success([
            'total' => $items->total(),
            'items' => EventRegistrationResource::collection($items->getCollection()),
        ], 'My registrations fetched successfully.');
    }

    public function qr(Request $request, string $registrationId)
    {
        $registration = EventRegistration::query()->where('user_id', $request->user()->id)->findOrFail($registrationId);

        return $this->success($this->registrations->qrDetails($registration), 'QR details fetched successfully.');
    }

    public function scan(ScanEventQrRequest $request)
    {
        $registration = $this->checkins->scan($request->input('qr_token'), $request->user(), (bool) $request->boolean('force'));

        return $this->success(new EventRegistrationResource($registration), 'Attendance marked successfully.');
    }

    public function attendance(Request $request, string $eventId)
    {
        $event = Event::query()->findOrFail($eventId);
        if (! $this->events->isAdmin($request->user())) {
            return $this->error('You are not authorized to view attendance.', 403);
        }

        $report = $this->events->attendanceReport($event);
        $report['items'] = EventRegistrationResource::collection($report['items']);

        return $this->success($report, 'Attendance fetched successfully.');
    }

    public function checkinQr(string $qrToken)
    {
        return $this->success(['qr_token' => $qrToken], 'QR token resolved successfully.');
    }

    public function store(StoreEventRequest $request)
    {
        $authUser = $request->user();
        $data = $request->validated();

        $circleId = $data['circle_id'];

        $membership = CircleMember::where('circle_id', $circleId)
            ->where('user_id', $authUser->id)
            ->where('status', 'approved')
            ->whereNull('deleted_at')
            ->first();

        if (! $membership) {
            return $this->error('You are not a member of this circle', 403);
        }

        $adminRoles = ['founder', 'director', 'chair', 'vice_chair', 'secretary'];
        if (! in_array($membership->role, $adminRoles, true)) {
            return $this->error('You are not allowed to create events for this circle', 403);
        }

        $event = new Event($data);
        $event->created_by_user_id = $authUser->id;
        $event->save();
        $event->load(['circle', 'createdByUser', 'rsvps.user']);

        return $this->success(new EventResource($event), 'Event created successfully', 201);
    }

    public function rsvp(EventRsvpRequest $request, string $id)
    {
        $event = Event::find($id);
        if (! $event) {
            return $this->error('Event not found', 404);
        }

        $rsvp = EventRsvp::updateOrCreate(
            ['event_id' => $event->id, 'user_id' => $request->user()->id],
            ['status' => $request->validated()['status']]
        );

        return $this->success(new EventRsvpResource($rsvp->load('user')), 'RSVP updated successfully');
    }

    public function checkin(EventCheckinRequest $request, string $id)
    {
        $event = Event::find($id);
        if (! $event) {
            return $this->error('Event not found', 404);
        }

        $targetUserId = $request->validated()['user_id'] ?? $request->user()->id;

        $rsvp = EventRsvp::where('event_id', $event->id)->where('user_id', $targetUserId)->first();
        if (! $rsvp) {
            return $this->error('RSVP not found', 404);
        }

        $rsvp->checked_in = true;
        $rsvp->checkin_at = now();
        $rsvp->save();

        return $this->success(new EventRsvpResource($rsvp->load('user')), 'Checked in successfully');
    }
}
