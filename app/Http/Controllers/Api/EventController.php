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
use App\Services\Events\EventPaymentService;
use App\Services\Events\EventRegistrationService;
use App\Services\Events\EventService;
use App\Services\Events\EventQrService;
use App\Services\Events\EventRazorpayPaymentFinalizer;
use App\Services\Events\EventRazorpayPaymentService;
use App\Services\Events\EventZohoInvoiceSyncService;
use Illuminate\Http\Request;

class EventController extends BaseApiController
{
    public function __construct(
        private readonly EventService $events,
        private readonly EventRegistrationService $registrations,
        private readonly EventCheckinService $checkins,
        private readonly EventPaymentService $payments,
        private readonly EventRazorpayPaymentService $razorpayPayments,
        private readonly EventRazorpayPaymentFinalizer $paymentFinalizer,
        private readonly EventZohoInvoiceSyncService $zohoInvoiceSync,
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

        $requiresPayment = (bool) ($registration->payment_required ?? false);

        return $this->success(
            $this->payments->responsePayload($registration),
            $requiresPayment ? 'Payment required. Please complete Razorpay checkout.' : 'Event registration successful.',
            201
        );
    }

    public function visitorRegister(VisitorEventRegistrationRequest $request, string $eventId, string $occurrenceId)
    {
        $event = Event::query()->findOrFail($eventId);
        if (! $this->events->visitorRegistrationEnabled($event)) {
            return $this->error('Visitor registration is not enabled for this event.', 403);
        }

        $registration = $this->registrations->registerVisitor(
            $event,
            EventOccurrence::query()->findOrFail($occurrenceId),
            $request->validated() + ['source' => $request->input('source', 'visitor_app')]
        );

        $requiresPayment = (bool) ($registration->payment_required ?? false);

        return $this->success(
            $this->payments->responsePayload($registration),
            $requiresPayment ? 'Payment required. Please complete Razorpay checkout.' : 'Visitor registered successfully.',
            201
        );
    }


    public function paymentStatus(string $registrationId)
    {
        $registration = EventRegistration::query()->findOrFail($registrationId);

        return $this->success([
            'registration_id' => $registration->id,
            'payment_gateway' => ($registration->payment_required ?? false) ? 'razorpay' : null,
            'payment_status' => $registration->payment_status ?? ((bool) ($registration->payment_required ?? false) ? 'pending' : 'not_required'),
            'status' => $registration->status,
            'qr_code_url' => ($registration->payment_required ?? false) && ($registration->payment_status ?? null) !== 'paid'
                ? null
                : ($registration->qr_code_url ?: app(EventQrService::class)->url($registration->qr_code_path)),
            'invoice' => $this->invoicePayload($registration),
        ], 'Payment status fetched successfully.');
    }

    public function verifyRazorpay(Request $request, string $registrationId)
    {
        $data = $request->validate([
            'razorpay_order_id' => ['required', 'string'],
            'razorpay_payment_id' => ['required', 'string'],
            'razorpay_signature' => ['required', 'string'],
        ]);

        $registration = EventRegistration::query()->with(['event.circle', 'occurrence', 'user'])->findOrFail($registrationId);
        if ((string) ($registration->razorpay_order_id ?? '') !== (string) $data['razorpay_order_id']) {
            return $this->error('Payment order does not match this registration.', 422);
        }

        if (! $this->razorpayPayments->verifySignature($data['razorpay_order_id'], $data['razorpay_payment_id'], $data['razorpay_signature'])) {
            return $this->error('Invalid payment signature.', 422);
        }

        $registration = $this->paymentFinalizer->markPaid($registration, [
            'razorpay_payment_id' => $data['razorpay_payment_id'],
            'razorpay_signature' => $data['razorpay_signature'],
            'razorpay_payment_status' => 'captured',
        ]);

        return $this->success(new EventRegistrationResource($registration), 'Payment verified successfully.');
    }

    public function invoice(Request $request, string $registrationId)
    {
        $registration = EventRegistration::query()->with(['event', 'user'])->findOrFail($registrationId);

        if ($registration->user_id && $request->user() && $registration->user_id !== $request->user()->id && ! $this->events->canViewAttendance($registration->event, $request->user())) {
            return $this->error('You are not authorized to view this invoice.', 403);
        }
        if ($registration->user_id && ! $request->user()) {
            return $this->error('Authentication is required to view this invoice.', 401);
        }

        return $this->success($this->invoicePayload($registration), 'Invoice fetched successfully.');
    }

    public function publicOccurrence(string $eventId, string $occurrenceId)
    {
        $occurrence = EventOccurrence::query()
            ->with(['event.circle'])
            ->where('event_id', $eventId)
            ->findOrFail($occurrenceId);
        $event = $occurrence->event;

        if (! ($event->is_public || $event->visibility === 'public' || $this->events->visitorRegistrationEnabled($event))) {
            return $this->error('Event is not available for public registration.', 403);
        }

        return $this->success([
            'event_id' => $event->id,
            'occurrence_id' => $occurrence->id,
            'title' => $event->title,
            'description' => $event->description,
            'start_at' => optional($occurrence->start_at)->toISOString(),
            'end_at' => optional($occurrence->end_at)->toISOString(),
            'location_text' => $event->location_text,
            'mode' => $event->mode,
            'online_meeting_url' => $event->online_meeting_url,
            'is_paid' => (bool) $event->is_paid,
            'ticket_price' => (string) ($event->ticket_price ?? '0.00'),
            'currency' => $this->payments->currency($event),
            'visitor_registration_enabled' => $this->events->visitorRegistrationEnabled($event),
        ], 'Public event fetched successfully.');
    }

    public function publicRegister(VisitorEventRegistrationRequest $request, string $eventId, string $occurrenceId)
    {
        $event = Event::query()->findOrFail($eventId);
        if (! $this->events->visitorRegistrationEnabled($event)) {
            return $this->error('Visitor registration is not enabled for this event.', 403);
        }

        $registration = $this->registrations->registerVisitor(
            $event,
            EventOccurrence::query()->findOrFail($occurrenceId),
            $request->validated() + ['source' => 'visitor_web']
        );
        $requiresPayment = (bool) ($registration->payment_required ?? false);

        return $this->success(
            $this->payments->responsePayload($registration),
            $requiresPayment ? 'Payment required. Please complete Razorpay checkout.' : 'Visitor registered successfully.',
            201
        );
    }

    public function myRegistrations(Request $request)
    {
        $items = EventRegistration::query()
            ->with(['event.circle', 'occurrence', 'user'])
            ->where('user_id', $request->user()->id)
            ->latest('registered_at')
            ->paginate(max(1, min((int) $request->input('per_page', 20), 100)));

        $qr = app(EventQrService::class);

        return $this->success([
            'total' => $items->total(),
            'items' => $items->getCollection()->map(fn (EventRegistration $registration) => [
                'registration_id' => $registration->id,
                'event_id' => $registration->event_id,
                'occurrence_id' => $registration->occurrence_id,
                'title' => $registration->event?->title,
                'start_at' => optional($registration->occurrence?->start_at)->toISOString(),
                'end_at' => optional($registration->occurrence?->end_at)->toISOString(),
                'location_text' => $registration->event?->location_text,
                'mode' => $registration->event?->mode,
                'status' => $registration->status,
                'checkin_status' => $registration->checkin_status,
                'payment_gateway' => ($registration->payment_required ?? false) ? 'razorpay' : null,
                'payment_status' => $registration->payment_status ?? null,
                'razorpay_order_id' => $registration->razorpay_order_id ?? null,
                'checkout_url' => null,
                'qr_code_url' => ($registration->payment_required ?? false) && ($registration->payment_status ?? null) !== 'paid' ? null : ($registration->qr_code_url ?: $qr->url($registration->qr_code_path)),
                'attendee_type' => $registration->user_id ? 'member' : 'visitor',
            ])->values(),
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
        if (! $this->events->canViewAttendance($event, $request->user())) {
            return $this->error('You are not authorized to view attendance.', 403);
        }

        return $this->success(
            $this->events->attendanceReport($event, $request->only(['occurrence_id', 'status', 'checkin_status', 'attendee_type', 'search'])),
            'Attendance fetched successfully.'
        );
    }

    private function invoicePayload(EventRegistration $registration): array
    {
        return [
            'registration_id' => $registration->id,
            'zoho_invoice_id' => $registration->zoho_invoice_id ?? null,
            'zoho_invoice_number' => $registration->zoho_invoice_number ?? null,
            'invoice_url' => $registration->zoho_invoice_url ?? null,
            'invoice_pdf_url' => $registration->zoho_invoice_pdf_url ?? null,
        ];
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
