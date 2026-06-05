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
use App\Models\CircleCategory;
use App\Models\CircleCategoryLevel4;
use App\Models\CircleMember;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventRegistration;
use App\Models\EventRegistrationRequest;
use App\Models\EventRsvp;
use App\Models\ScanAppUser;
use App\Models\User;
use App\Services\Events\EventCheckinService;
use App\Services\Events\EventPaymentService;
use App\Services\Events\EventPaymentSyncService;
use App\Services\Events\EventRegistrationService;
use App\Services\Events\EventScannerQrScanService;
use App\Services\Events\EventService;
use App\Services\Events\EventQrService;
use App\Services\Events\EventRegistrationQrService;
use App\Services\Events\EventRazorpayPaymentFinalizer;
use App\Services\Events\EventRazorpayPaymentService;
use App\Services\Events\EventZohoInvoiceSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class EventController extends BaseApiController
{
    public function __construct(
        private readonly EventService $events,
        private readonly EventRegistrationService $registrations,
        private readonly EventCheckinService $checkins,
        private readonly EventScannerQrScanService $scannerQrScans,
        private readonly EventPaymentService $payments,
        private readonly EventPaymentSyncService $eventPaymentSync,
        private readonly EventRegistrationQrService $registrationQr,
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
        $user = $request->user();
        $event = Event::query()->findOrFail($eventId);
        $occurrence = EventOccurrence::query()->where('event_id', $event->id)->findOrFail($occurrenceId);
        $eventCircleId = $event->circle_id;
        Log::info('member_event_registration_start', ['user_id' => $user->id, 'event_id' => $event->id, 'occurrence_id' => $occurrence->id, 'event_circle_id' => $eventCircleId]);
        Log::info('member_event_circle_check_start', ['user_id' => $user->id, 'event_id' => $event->id, 'occurrence_id' => $occurrence->id, 'event_circle_id' => $eventCircleId]);

        $memberQuery = CircleMember::query()
            ->where('circle_id', $eventCircleId)
            ->where('user_id', $user->id)
            ->whereNull('deleted_at');
        if (\Illuminate\Support\Facades\Schema::hasColumn('circle_members', 'status')) {
            $memberQuery->whereIn('status', ['approved', 'active']);
        }
        if (\Illuminate\Support\Facades\Schema::hasColumn('circle_members', 'expires_at')) {
            $memberQuery->where(function ($q): void {
                $q->whereNull('expires_at')->orWhereDate('expires_at', '>=', now()->toDateString());
            });
        }
        $membership = $memberQuery->first();
        $eligibilityContext = ['user_id' => $user->id, 'event_id' => $event->id, 'occurrence_id' => $occurrence->id, 'event_circle_id' => $eventCircleId];
        Log::info('event_register_eligibility_check_start', $eligibilityContext);

        if (! $membership) {
            Log::info('cross_circle_registration_attempt', $eligibilityContext);
            $approvedRequest = EventRegistrationRequest::query()
                ->where('event_id', $event->id)
                ->where('occurrence_id', $occurrence->id)
                ->where('user_id', $user->id)
                ->where('status', 'approved')
                ->whereNull('deleted_at')
                ->latest('approved_at')
                ->latest('created_at')
                ->first();

            if ($approvedRequest) {
                Log::info('event_register_approved_cross_circle_request_true', $eligibilityContext + ['request_id' => $approvedRequest->id, 'request_status' => $approvedRequest->status]);
                Log::info('cross_circle_registration_after_approval_start', $eligibilityContext + ['request_id' => $approvedRequest->id, 'request_status' => $approvedRequest->status]);
                $registration = $this->registrations->registerApprovedCrossCircleMember(
                    $event,
                    $occurrence,
                    $user,
                    (string) $approvedRequest->id,
                    $request->input('source', 'app')
                );
                $approvedRequest->forceFill(['registration_id' => $registration->id])->save();
                Log::info('cross_circle_registration_after_approval_payment_link_created', $eligibilityContext + ['request_id' => $approvedRequest->id, 'request_status' => $approvedRequest->status, 'registration_id' => (string) $registration->id]);
                Log::info('cross_circle_approved_registration_payment_link_created', $eligibilityContext + ['request_id' => $approvedRequest->id, 'registration_id' => (string) $registration->id]);

                return $this->success($this->payments->responsePayload($registration), 'Payment is required to complete registration.', 201);
            }

            $req = EventRegistrationRequest::query()
                ->where('event_id', $event->id)
                ->where('occurrence_id', $occurrence->id)
                ->where('user_id', $user->id)
                ->whereIn('status', ['pending', 'rejected'])
                ->whereNull('deleted_at')
                ->latest('created_at')
                ->first();

            if ($req && $req->status === 'pending') {
                Log::info('event_register_eligibility_failed_pending_request', $eligibilityContext + ['request_id' => $req->id, 'request_status' => $req->status]);
                return $this->error('Your registration request is pending admin approval.', 403, [
                    'request_required' => true,
                    'request_status' => 'pending',
                    'request_id' => $req->id,
                ]);
            }
            if ($req && $req->status === 'rejected') {
                Log::info('event_register_eligibility_failed_rejected_request', $eligibilityContext + ['request_id' => $req->id, 'request_status' => $req->status]);
                return $this->error('Your registration request was rejected by admin.', 403, [
                    'request_required' => true,
                    'request_status' => 'rejected',
                ]);
            }

            Log::info('event_register_eligibility_failed_no_request', $eligibilityContext);
            Log::info('cross_circle_request_required', $eligibilityContext);
            return $this->error('You are not a member of this event circle. Please submit a registration request for admin approval.', 403, [
                'request_required' => true,
                'request_status' => 'not_requested',
            ]);
        }
        Log::info('event_register_same_circle_member_true', $eligibilityContext);
        Log::info('member_event_circle_check_passed', ['user_id' => $user->id, 'event_id' => $event->id, 'occurrence_id' => $occurrence->id, 'event_circle_id' => $eventCircleId]);

        $existing = EventRegistration::query()
            ->where('occurrence_id', $occurrence->id)
            ->where('user_id', $user->id)
            ->where('status', '!=', 'cancelled')
            ->whereNull('deleted_at')
            ->first();
        if ($existing) {
            Log::info('member_event_registration_existing_found', ['user_id' => $user->id, 'event_id' => $event->id, 'occurrence_id' => $occurrence->id, 'event_circle_id' => $eventCircleId, 'registration_id' => (string) $existing->id]);
        }

        $registration = $this->registrations->registerMemberDirectNoPayment(
            $event,
            $occurrence,
            $user,
            $request->input('source', 'app')
        );
        if ((! empty($registration->qr_code_url) || ! empty($registration->qr_code_path)) && ! $existing) {
            Log::info('member_event_registration_qr_generated', ['user_id' => $user->id, 'event_id' => $event->id, 'occurrence_id' => $occurrence->id, 'event_circle_id' => $eventCircleId, 'registration_id' => (string) $registration->id]);
        }
        Log::info('member_event_registration_success', ['user_id' => $user->id, 'event_id' => $event->id, 'occurrence_id' => $occurrence->id, 'event_circle_id' => $eventCircleId, 'registration_id' => (string) $registration->id]);

        return $this->success($this->payments->responsePayload($registration), 'Event registration successful.', 201);
    }

    public function createRegistrationRequest(Request $request, string $eventId, string $occurrenceId)
    {
        $user = $request->user();
        $event = Event::query()->findOrFail($eventId);
        $occurrence = EventOccurrence::query()->where('event_id', $event->id)->findOrFail($occurrenceId);
        $eventCircleId = $event->circle_id;
        $sameCircle = CircleMember::query()->where('circle_id', $eventCircleId)->where('user_id', $user->id)->whereNull('deleted_at')->whereIn('status', ['approved','active'])->exists();
        if ($sameCircle) return $this->success([], 'You are already a member of this circle. You can register directly.');
        $existingReg = EventRegistration::query()->where('occurrence_id',$occurrence->id)->where('user_id',$user->id)->where('status','!=','cancelled')->whereNull('deleted_at')->first();
        if ($existingReg) return $this->success(['registration_id'=>$existingReg->id], 'You are already registered for this event.');
        $existing = EventRegistrationRequest::query()->where('event_id',$event->id)->where('occurrence_id',$occurrence->id)->where('user_id',$user->id)->whereIn('status',['pending','approved'])->latest('created_at')->first();
        if ($existing) {
            Log::info('cross_circle_registration_request_existing', ['user_id'=>$user->id,'event_id'=>$event->id,'occurrence_id'=>$occurrence->id,'request_id'=>$existing->id,'status'=>$existing->status]);
            return $this->success(['request_id'=>$existing->id,'status'=>$existing->status,'event_id'=>$event->id,'occurrence_id'=>$occurrence->id,'user_id'=>$user->id], $existing->status === 'approved' ? 'Your request is approved. You can register now.' : 'Your registration request is pending admin approval.');
        }
        $req = EventRegistrationRequest::query()->create([
            'event_id'=>$event->id,'occurrence_id'=>$occurrence->id,'user_id'=>$user->id,'event_circle_id'=>$eventCircleId,'status'=>'pending','request_reason'=>$request->input('request_reason'),
        ]);
        Log::info('cross_circle_registration_request_created', ['user_id'=>$user->id,'event_id'=>$event->id,'occurrence_id'=>$occurrence->id,'request_id'=>$req->id]);
        return $this->success(['request_id'=>$req->id,'status'=>$req->status,'event_id'=>$event->id,'occurrence_id'=>$occurrence->id,'user_id'=>$user->id], 'Registration request submitted successfully. Please wait for admin approval.');
    }

    public function myRegistrationRequests(Request $request)
    {
        $items = EventRegistrationRequest::query()
            ->where('user_id', $request->user()->id)
            ->with(['event', 'occurrence'])
            ->latest('created_at')
            ->get()
            ->map(fn ($r) => [
                'request_id' => $r->id,
                'event_id' => $r->event_id,
                'occurrence_id' => $r->occurrence_id,
                'status' => $r->status,
                'admin_note' => $r->admin_note,
                'registration_id' => $r->registration_id,
                'created_at' => optional($r->created_at)->toISOString(),
            ]);
        return $this->success(['items' => $items], 'Registration requests fetched successfully.');
    }

    public function adminRegistrationRequests(Request $request)
    {
        $query = EventRegistrationRequest::query()
            ->with(['event.circle', 'occurrence', 'user.circleMemberships.circle', 'registration'])
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->event_id, fn ($q, $v) => $q->where('event_id', $v))
            ->when($request->occurrence_id, fn ($q, $v) => $q->where('occurrence_id', $v))
            ->when($request->user_id, fn ($q, $v) => $q->where('user_id', $v))
            ->when($request->search, function ($q, $term): void {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';
                $q->where(function ($inner) use ($like): void {
                    $inner->where('request_reason', 'ilike', $like)
                        ->orWhere('admin_note', 'ilike', $like)
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

        $summary = [
            'pending' => EventRegistrationRequest::query()->where('status', 'pending')->count(),
            'approved' => EventRegistrationRequest::query()->where('status', 'approved')->count(),
            'rejected' => EventRegistrationRequest::query()->where('status', 'rejected')->count(),
            'total' => EventRegistrationRequest::query()->count(),
        ];

        $items = $query->latest('created_at')
            ->paginate(max(1, min((int) $request->input('per_page', 20), 100)))
            ->withQueryString();

        return $this->success([
            'summary' => $summary,
            'items' => $items->getCollection()->map(fn (EventRegistrationRequest $joiningRequest) => $this->eventJoiningRequestPayload($joiningRequest))->values(),
            'pagination' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'last_page' => $items->lastPage(),
            ],
        ], 'Event joining requests fetched successfully.');
    }


    public function approveRegistrationRequest(Request $request, string $requestId)
    {
        $r = EventRegistrationRequest::query()->findOrFail($requestId);
        $r->forceFill(['status' => 'approved', 'admin_note' => $request->input('admin_note', 'Approved for cross-circle event registration.'), 'approved_by_user_id' => $request->user()->id, 'approved_at' => now()])->save();
        Log::info('cross_circle_registration_request_approved', ['request_id' => $r->id, 'user_id' => $r->user_id, 'event_id' => $r->event_id, 'occurrence_id' => $r->occurrence_id]);
        $r->load(['event.circle', 'occurrence', 'user.circleMemberships.circle', 'registration']);
        return $this->success($this->eventJoiningRequestPayload($r), 'Registration request approved successfully.');
    }

    public function rejectRegistrationRequest(Request $request, string $requestId)
    {
        $data = $request->validate(['admin_note' => ['required', 'string', 'max:2000']]);
        $r = EventRegistrationRequest::query()->findOrFail($requestId);
        $r->forceFill(['status' => 'rejected', 'admin_note' => $data['admin_note'], 'rejected_by_user_id' => $request->user()->id, 'rejected_at' => now()])->save();
        Log::info('cross_circle_registration_request_rejected', ['request_id' => $r->id, 'user_id' => $r->user_id, 'event_id' => $r->event_id, 'occurrence_id' => $r->occurrence_id]);
        $r->load(['event.circle', 'occurrence', 'user.circleMemberships.circle', 'registration']);
        return $this->success($this->eventJoiningRequestPayload($r), 'Registration request rejected successfully.');
    }

    public function cancelRegistrationRequest(Request $request, string $requestId)
    {
        $r = EventRegistrationRequest::query()->where('user_id', $request->user()->id)->where('status', 'pending')->findOrFail($requestId);
        $r->forceFill(['status' => 'cancelled'])->save();
        return $this->success($r, 'Registration request cancelled successfully.');
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
            $request->validated(),
            $request->input('source', 'visitor_app')
        );
        Log::info('public_event_registration_payment_link_created', ['event_id' => $event->id, 'occurrence_id' => $occurrenceId, 'registration_id' => (string) $registration->id]);

        $requiresPayment = (bool) ($registration->payment_required ?? false);

        return $this->success(
            $this->payments->responsePayload($registration),
            $requiresPayment ? 'Payment required. Please complete payment.' : 'Visitor registered successfully.',
            201
        );
    }


    public function visitorRegisterAsUser(Request $request, string $eventId, string $occurrenceId)
    {
        $user = $request->user();
        $event = Event::query()->findOrFail($eventId);
        $occurrence = EventOccurrence::query()->where('event_id', $event->id)->findOrFail($occurrenceId);
        $context = ['user_id' => $user->id, 'event_id' => $event->id, 'occurrence_id' => $occurrence->id, 'event_circle_id' => $event->circle_id];
        Log::info('app_user_visitor_registration_start', $context);

        try {
            if ($this->isActiveCircleMember($event->circle_id, $user->id)) {
                return $this->success([
                    'direct_registration_available' => true,
                    'registration_api' => '/api/v1/events/'.$event->id.'/occurrences/'.$occurrence->id.'/register',
                ], 'You are already a member of this circle. Please use direct member registration.');
            }

            $existing = EventRegistration::query()
                ->where('occurrence_id', $occurrence->id)
                ->where('user_id', $user->id)
                ->where('status', '!=', 'cancelled')
                ->whereNull('deleted_at')
                ->latest('created_at')
                ->first();
            if ($existing) {
                Log::info('app_user_visitor_existing_registration_found', $context + ['registration_id' => (string) $existing->id]);
            }

            $registration = $this->registrations->registerAppUserVisitor(
                $event,
                $occurrence,
                $user,
                $request->input('source', 'app')
            );
            if (! empty($registration->payment_url) || ! empty($registration->zoho_payment_link_url)) {
                Log::info('app_user_visitor_payment_link_created', $context + ['registration_id' => (string) $registration->id]);
            }
            Log::info('app_user_visitor_registration_success', $context + ['registration_id' => (string) $registration->id]);

            return $this->success(
                $this->payments->responsePayload($registration),
                'Payment is required to complete your event registration.',
                $existing ? 200 : 201
            );
        } catch (\Throwable $e) {
            Log::error('app_user_visitor_registration_failed', $context + ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function paymentStatus(string $registrationId)
    {
        $registration = EventRegistration::query()->with(['event', 'occurrence', 'user', 'invitedByUser', 'businessCategoryMain', 'businessCategorySub'])->findOrFail($registrationId);

        if (($registration->payment_gateway ?? '') === 'zoho_billing_payment_link'
            && in_array((string) ($registration->payment_status ?? ''), ['pending', 'processing', 'failed', 'expired'], true)) {
            try {
                $syncResult = $this->eventPaymentSync->syncRegistrationPayment($registration, ['source' => 'payment_status_api']);
                $registration = $syncResult['registration'];
            } catch (\Throwable $e) {
                Log::warning('event_payment_status_api_zoho_sync_failed', [
                    'registration_id' => (string) $registration->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (in_array(strtolower((string) ($registration->payment_status ?? '')), ['paid', 'success', 'completed'], true)) {
            $registration = $this->registrationQr->ensureQrGenerated($registration);
        }

        return $this->success([
            'registration_id' => $registration->id,
            'payment_required' => (bool) ($registration->payment_required ?? false),
            'payment_gateway' => ($registration->payment_required ?? false) ? ($registration->payment_gateway ?: (string) config('services.event_payment_gateway', 'zoho_billing_payment_link')) : null,
            'payment_status' => $registration->payment_status ?? ((bool) ($registration->payment_required ?? false) ? 'pending' : 'not_required'),
            'status' => $registration->status,
            'payment_completed_at' => optional($registration->payment_completed_at)->toISOString(),
            'qr_token' => $registration->qr_token ?? null,
            'qr_code_url' => ($registration->payment_required ?? false) && ! in_array(strtolower((string) ($registration->payment_status ?? '')), ['paid', 'success', 'completed'], true)
                ? null
                : $this->registrationQr->qrCodeUrl($registration),
            'qr_code_svg' => $registration->qr_code_svg ?? null,
            'zoho_invoice_id' => $registration->zoho_invoice_id ?? null,
            'zoho_invoice_number' => $registration->zoho_invoice_number ?? null,
            'zoho_invoice_url' => $registration->zoho_invoice_url ?? null,
            'zoho_invoice_pdf_url' => $registration->zoho_invoice_pdf_url ?? null,
            'zoho_invoice_status' => $registration->zoho_invoice_status ?? null,
            'zoho_payment_status' => $registration->zoho_payment_status ?? null,
            'zoho_payment_id' => $registration->zoho_payment_id ?? null,
            'invoice_sync_error' => $registration->zoho_invoice_sync_error ?? null,
            'invoice' => array_merge($this->invoicePayload($registration), ['invoice_sync_error' => $registration->zoho_invoice_sync_error ?? null]),
        ], 'Payment status fetched successfully.');
    }

    public function verifyRazorpay(Request $request, string $registrationId)
    {
        $data = $request->validate([
            'razorpay_order_id' => ['required', 'string'],
            'razorpay_payment_id' => ['required', 'string'],
            'razorpay_signature' => ['required', 'string'],
        ]);

        $registration = EventRegistration::query()->with(['event.circle', 'occurrence', 'user', 'invitedByUser', 'businessCategoryMain', 'businessCategorySub'])->findOrFail($registrationId);
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
        $registration = EventRegistration::query()->with(['event', 'user', 'invitedByUser', 'businessCategoryMain', 'businessCategorySub'])->findOrFail($registrationId);

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

    public function publicRegistrationForm(string $eventId, string $occurrenceId)
    {
        $occurrence = EventOccurrence::query()
            ->with(['event.circle'])
            ->where('event_id', $eventId)
            ->findOrFail($occurrenceId);
        $event = $occurrence->event;

        if (! ($event->is_public || $event->visibility === 'public' || $this->events->visitorRegistrationEnabled($event))) {
            return $this->error('Event is not available for public registration.', 403);
        }

        return $this->success($this->publicRegistrationFormPayload($event, $occurrence), 'Public event registration form fetched successfully.');
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
            $request->validated(),
            'api'
        );
        Log::info('public_event_registration_payment_link_created', ['event_id' => $event->id, 'occurrence_id' => $occurrenceId, 'registration_id' => (string) $registration->id]);
        $requiresPayment = (bool) ($registration->payment_required ?? false);

        return $this->success(
            $this->payments->responsePayload($registration),
            $requiresPayment ? 'Payment required. Please complete payment.' : 'Visitor registered successfully.',
            201
        );
    }

    public function myEventRegistrations(Request $request)
    {
        $user = $request->user();
        Log::info('my_event_registrations_fetch_start', ['user_id' => $user->id]);

        if ($user->email) {
            EventRegistration::query()
                ->whereNull('user_id')
                ->whereRaw('LOWER(visitor_email) = ?', [strtolower($user->email)])
                ->update(['user_id' => $user->id]);
        }

        $query = EventRegistration::query()
            ->with(['event.circle', 'occurrence', 'user', 'invitedByUser', 'businessCategoryMain', 'businessCategorySub'])
            ->where(function ($q) use ($user): void {
                $q->where('user_id', $user->id);
                if ($user->email) {
                    $q->orWhereRaw('LOWER(visitor_email) = ?', [strtolower($user->email)]);
                }
                if ($user->phone) {
                    $q->orWhere('visitor_phone', $user->phone);
                }
            });

        foreach (['status', 'payment_status', 'registration_type', 'event_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }
        if ($request->boolean('upcoming')) {
            $query->whereHas('occurrence', fn ($q) => $q->where('start_at', '>=', now()));
        }
        if ($request->boolean('past')) {
            $query->whereHas('occurrence', fn ($q) => $q->where('end_at', '<', now())->orWhere(fn ($inner) => $inner->whereNull('end_at')->where('start_at', '<', now())));
        }

        $items = $query->latest('registered_at')->paginate(max(1, min((int) $request->input('per_page', 20), 100)));
        $qr = app(EventQrService::class);
        $mapped = $items->getCollection()->map(fn (EventRegistration $registration) => $this->myEventRegistrationPayload($registration, $qr))->values();
        Log::info('my_event_registrations_fetch_success', ['user_id' => $user->id, 'total' => $items->total()]);

        return $this->success([
            'items' => $mapped,
            'pagination' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'last_page' => $items->lastPage(),
            ],
        ], 'My event registrations fetched successfully.');
    }

    public function myRegistrations(Request $request)
    {
        $items = EventRegistration::query()
            ->with(['event.circle', 'occurrence', 'user', 'invitedByUser', 'businessCategoryMain', 'businessCategorySub'])
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
                'payment_gateway' => ($registration->payment_required ?? false) ? ($registration->payment_gateway ?: (string) config('services.event_payment_gateway', 'zoho_billing_payment_link')) : null,
                'payment_status' => $registration->payment_status ?? null,
                'razorpay_order_id' => $registration->razorpay_order_id ?? null,
                'payment_url' => $registration->payment_url ?? $registration->zoho_payment_link_url ?? $registration->zoho_hosted_page_url ?? null,
                'checkout_url' => $registration->payment_url ?? $registration->zoho_payment_link_url ?? $registration->zoho_hosted_page_url ?? null,
                'qr_code_url' => ($registration->payment_required ?? false) && ($registration->payment_status ?? null) !== 'paid' ? null : ($registration->qr_code_path ? $qr->url($registration->qr_code_path) : $registration->qr_code_url),
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
        $authUser = $request->user();
        $qrToken = trim((string) $request->input('qr_token'));

        if ($authUser instanceof ScanAppUser) {
            return $this->scannerScanResponse(
                $this->scannerQrScans->scan($authUser, $qrToken, $this->deviceInfo($request))
            );
        }

        if ($authUser instanceof User) {
            $registration = $this->checkins->scan($qrToken, $authUser, (bool) $request->boolean('force'));

            return $this->success(new EventRegistrationResource($registration), 'Attendance marked successfully.');
        }

        return response()->json([
            'success' => false,
            'message' => 'Unauthenticated.',
        ], 401);
    }

    private function scannerScanResponse(array $result)
    {
        if ($result['success']) {
            return $this->success($result['data'], $result['message'], $result['status']);
        }

        if ($result['errors'] !== null) {
            return $this->error($result['message'], $result['status'], $result['errors']);
        }

        return response()->json([
            'success' => false,
            'message' => $result['message'],
        ], $result['status']);
    }

    private function deviceInfo(Request $request): ?array
    {
        $deviceInfo = $request->input('device_info');

        return is_array($deviceInfo) ? $deviceInfo : null;
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


    public function invoices(Request $request)
    {
        $q = EventRegistration::query()->with(['event', 'occurrence', 'user', 'invitedByUser', 'businessCategoryMain', 'businessCategorySub'])->where('payment_status', 'paid')->latest('created_at');
        if ($request->filled('payment_status')) $q->where('payment_status', $request->input('payment_status'));
        if ($request->filled('event_id')) $q->where('event_id', $request->input('event_id'));
        if ($request->filled('occurrence_id')) $q->where('occurrence_id', $request->input('occurrence_id'));
        if ($request->filled('user_id')) $q->where('user_id', $request->input('user_id'));
        if ($request->filled('visitor_email')) $q->where('visitor_email', $request->input('visitor_email'));

        $items = $q->paginate(max(1, min((int) $request->input('per_page', 20), 100)));

        return $this->success([
            'total' => $items->total(),
            'items' => $items->getCollection()->map(fn(EventRegistration $r) => $this->invoiceListItem($r))->values(),
            'pagination' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ], 'Event invoices fetched successfully.');
    }

    public function invoiceDetails(Request $request, string $registrationId)
    {
        $r = EventRegistration::query()->with(['event', 'occurrence', 'user', 'invitedByUser', 'businessCategoryMain', 'businessCategorySub'])->findOrFail($registrationId);
        if ($r->user_id && $request->user() && $r->user_id !== $request->user()->id && ! $this->events->canViewAttendance($r->event, $request->user())) {
            return $this->error('You are not authorized to view this invoice.', 403);
        }

        return $this->success(array_merge($this->invoiceListItem($r), [
            'event' => [
                'title' => $r->event?->title,
                'location_text' => $r->event?->location_text,
                'mode' => $r->event?->mode,
                'start_at' => optional($r->occurrence?->start_at)->toISOString(),
                'end_at' => optional($r->occurrence?->end_at)->toISOString(),
            ],
            'qr_code_url' => $r->qr_code_path ? app(EventQrService::class)->url($r->qr_code_path) : $r->qr_code_url,
            'invoice_sync_error' => $r->zoho_invoice_sync_error,
        ]), 'Event invoice fetched successfully.');
    }

    private function invoiceListItem(EventRegistration $registration): array
    {
        $attendeeName = $registration->user?->display_name ?: trim(($registration->user?->first_name ?? '').' '.($registration->user?->last_name ?? '')) ?: $registration->visitor_name;
        $email = $registration->user?->email ?: $registration->visitor_email;
        $phone = $registration->user?->phone ?: $registration->visitor_phone;

        return [
            'registration_id' => $registration->id,
            'event_id' => $registration->event_id,
            'event_title' => $registration->event?->title,
            'occurrence_id' => $registration->occurrence_id,
            'attendee_name' => $attendeeName,
            'email' => $email,
            'phone' => $phone,
            'payment_status' => $registration->payment_status,
            'payment_gateway' => $registration->payment_gateway,
            'zoho_payment_link_id' => $registration->zoho_payment_link_id,
            'amount' => $registration->amount !== null ? (string) $registration->amount : null,
            'currency' => $registration->currency ?? 'INR',
            'zoho_invoice_id' => $registration->zoho_invoice_id,
            'zoho_invoice_number' => $registration->zoho_invoice_number,
            'zoho_invoice_status' => $registration->zoho_invoice_status,
            'zoho_invoice_url' => $registration->zoho_invoice_url,
            'zoho_invoice_pdf_url' => $registration->zoho_invoice_pdf_url,
            'zoho_invoice_sync_error' => $registration->zoho_invoice_sync_error,
            'zoho_payment_id' => $registration->zoho_payment_id,
            'paid_at' => optional($registration->payment_completed_at)->toISOString(),
            'qr_code_url' => $registration->qr_code_path ? app(EventQrService::class)->url($registration->qr_code_path) : $registration->qr_code_url,
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
            'created_at' => optional($registration->created_at)->toISOString(),
        ];
    }

    private function publicRegistrationFormPayload(Event $event, EventOccurrence $occurrence, bool $includeCategories = true): array
    {
        return [
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'name' => $event->title,
                'description' => $event->description,
                'basic_details' => [
                    'event_type' => $event->event_type,
                    'event_category' => $event->event_category,
                    'mode' => $event->mode,
                    'circle' => $event->circle ? [
                        'id' => $event->circle->id,
                        'name' => $event->circle->name ?? null,
                    ] : null,
                ],
                'start_at' => optional($occurrence->start_at ?? $event->start_at)->toISOString(),
                'end_at' => optional($occurrence->end_at ?? $event->end_at)->toISOString(),
                'location_text' => $event->location_text,
                'mode' => $event->mode,
                'online_meeting_url' => $event->online_meeting_url,
                'is_paid' => (bool) $event->is_paid,
                'ticket_price' => (string) ($event->ticket_price ?? '0.00'),
                'currency' => $this->payments->currency($event),
                'visitor_registration_enabled' => $this->events->visitorRegistrationEnabled($event),
            ],
            'occurrence' => [
                'id' => $occurrence->id,
                'event_id' => $occurrence->event_id,
                'occurrence_date' => optional($occurrence->occurrence_date)->toDateString(),
                'start_at' => optional($occurrence->start_at)->toISOString(),
                'end_at' => optional($occurrence->end_at)->toISOString(),
                'status' => $occurrence->status,
                'sequence' => $occurrence->sequence,
                'registration_limit' => $occurrence->registration_limit,
                'registered_count' => $occurrence->registered_count,
                'metadata' => $occurrence->metadata,
            ],
            'categories' => $includeCategories ? $this->publicRegistrationCategories() : null,
            'submit_url' => url('/api/v1/public/events/'.$event->id.'/occurrences/'.$occurrence->id.'/register'),
            'web_form_url' => url('/events/'.$event->id.'/occurrences/'.$occurrence->id.'/visitor-register'),
        ];
    }

    private function publicRegistrationCategories(): array
    {
        $main = Schema::hasTable('circle_categories')
            ? CircleCategory::query()->orderBy('name')->get(['id', 'name'])->map(fn ($category) => [
                'id' => $category->id,
                'name' => $category->name,
            ])->values()->all()
            : [];

        $sub = (Schema::hasTable('level4_categories') || Schema::hasTable('circle_category_level4'))
            ? CircleCategoryLevel4::query()->orderBy('name')->get(['id', 'name'])->map(fn ($category) => [
                'id' => $category->id,
                'name' => $category->name,
            ])->values()->all()
            : [];

        return [
            'main' => $main,
            'sub' => $sub,
        ];
    }

    private function eventJoiningRequestPayload(EventRegistrationRequest $joiningRequest): array
    {
        $user = $joiningRequest->user;
        $userCircle = $user?->circleMemberships?->first()?->circle;
        $event = $joiningRequest->event;
        $occurrence = $joiningRequest->occurrence;
        $registration = $joiningRequest->registration;

        return [
            'id' => $joiningRequest->id,
            'status' => $joiningRequest->status,
            'request_reason' => $joiningRequest->request_reason,
            'admin_note' => $joiningRequest->admin_note,
            'created_at' => optional($joiningRequest->created_at)->toISOString(),
            'approved_at' => optional($joiningRequest->approved_at)->toISOString(),
            'rejected_at' => optional($joiningRequest->rejected_at)->toISOString(),
            'user' => [
                'id' => $user?->id,
                'display_name' => $user?->display_name ?: trim(($user?->first_name ?? '').' '.($user?->last_name ?? '')),
                'email' => $user?->email,
                'phone' => $user?->phone,
                'company_name' => $user?->company_name,
                'city' => $user?->city ?? $user?->city_of_residence,
            ],
            'user_circle' => $userCircle ? ['id' => $userCircle->id, 'name' => $userCircle->name] : null,
            'event_circle' => $event?->circle ? ['id' => $event->circle->id, 'name' => $event->circle->name] : null,
            'event' => [
                'id' => $event?->id,
                'title' => $event?->title,
                'event_type' => $event?->event_type,
                'mode' => $event?->mode,
                'location_text' => $event?->location_text,
            ],
            'occurrence' => [
                'id' => $occurrence?->id,
                'start_at' => optional($occurrence?->start_at)->toISOString(),
                'end_at' => optional($occurrence?->end_at)->toISOString(),
            ],
            'registration' => $registration ? [
                'id' => $registration->id,
                'registration_type' => $registration->registration_type,
                'payment_status' => $registration->payment_status,
                'payment_required' => (bool) ($registration->payment_required ?? false),
                'qr_code_url' => $registration->qr_code_url,
                'checkin_status' => $registration->checkin_status,
            ] : null,
        ];
    }


    private function invitedByUserPayload(?\App\Models\User $user): ?array
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

    private function isActiveCircleMember(?string $circleId, string $userId): bool
    {
        if (! $circleId) {
            return false;
        }

        $query = CircleMember::query()
            ->where('circle_id', $circleId)
            ->where('user_id', $userId)
            ->whereNull('deleted_at');
        if (\Illuminate\Support\Facades\Schema::hasColumn('circle_members', 'status')) {
            $query->whereIn('status', ['approved', 'active']);
        }
        if (\Illuminate\Support\Facades\Schema::hasColumn('circle_members', 'expires_at')) {
            $query->where(function ($q): void {
                $q->whereNull('expires_at')->orWhereDate('expires_at', '>=', now()->toDateString());
            });
        }

        return $query->exists();
    }

    private function myEventRegistrationPayload(EventRegistration $registration, EventQrService $qr): array
    {
        $qrUrl = ($registration->payment_required ?? false) && ($registration->payment_status ?? null) !== 'paid'
            ? null
            : ($registration->qr_code_path ? $qr->url($registration->qr_code_path) : $registration->qr_code_url);

        return [
            'registration_id' => $registration->id,
            'registration_type' => $registration->registration_type ?? null,
            'status' => $registration->status,
            'checkin_status' => $registration->checkin_status,
            'payment_required' => (bool) ($registration->payment_required ?? false),
            'payment_status' => $registration->payment_status,
            'amount' => $registration->amount !== null ? (string) $registration->amount : null,
            'currency' => $registration->currency ?? 'INR',
            'payment_url' => $registration->payment_url ?? $registration->zoho_payment_link_url ?? $registration->zoho_hosted_page_url ?? null,
            'qr_code_url' => $qrUrl,
            'zoho_invoice_id' => $registration->zoho_invoice_id,
            'zoho_invoice_number' => $registration->zoho_invoice_number,
            'zoho_invoice_status' => $registration->zoho_invoice_status,
            'invoice_url' => $registration->zoho_invoice_url,
            'invoice_pdf_url' => $registration->zoho_invoice_pdf_url,
            'registered_at' => optional($registration->registered_at)->toISOString(),
            'checked_in_at' => optional($registration->checked_in_at)->toISOString(),
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
            'event' => [
                'id' => $registration->event?->id,
                'title' => $registration->event?->title,
                'event_type' => $registration->event?->event_type,
                'mode' => $registration->event?->mode,
                'location_text' => $registration->event?->location_text,
                'circle_id' => $registration->event?->circle_id,
                'circle_name' => $registration->event?->circle?->name,
            ],
            'occurrence' => [
                'id' => $registration->occurrence?->id,
                'start_at' => optional($registration->occurrence?->start_at)->toISOString(),
                'end_at' => optional($registration->occurrence?->end_at)->toISOString(),
            ],
        ];
    }

    private function invoicePayload(EventRegistration $registration): array
    {
        return [
            'registration_id' => $registration->id,
            'zoho_invoice_id' => $registration->zoho_invoice_id ?? null,
            'zoho_invoice_number' => $registration->zoho_invoice_number ?? null,
            'invoice_url' => $registration->zoho_invoice_url ?? null,
            'invoice_pdf_url' => $registration->zoho_invoice_pdf_url ?? null,
            'zoho_invoice_status' => $registration->zoho_invoice_status ?? null,
            'invoice_balance' => data_get($registration->metadata ?? [], 'invoice_balance'),
            'amount_paid' => data_get($registration->metadata ?? [], 'invoice_amount_paid'),
            'payment_applied' => data_get($registration->metadata ?? [], 'invoice_payment_applied'),
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
