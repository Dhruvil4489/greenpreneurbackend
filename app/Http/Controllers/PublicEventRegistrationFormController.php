<?php

namespace App\Http\Controllers;

use App\Http\Requests\Event\VisitorEventRegistrationRequest;
use App\Models\CircleCategory;
use App\Models\CircleCategoryLevel4;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventRegistration;
use App\Services\Events\EventPaymentService;
use App\Services\Events\EventPaymentSyncService;
use App\Services\Events\EventRegistrationService;
use App\Services\Events\EventRegistrationQrService;
use App\Services\Events\EventService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class PublicEventRegistrationFormController extends Controller
{
    public function __construct(
        private readonly EventService $events,
        private readonly EventRegistrationService $registrations,
        private readonly EventPaymentService $payments,
        private readonly EventPaymentSyncService $paymentSync,
        private readonly EventRegistrationQrService $registrationQr,
    ) {}

    public function show(Request $request, string $event, string $occurrence): View
    {
        [$event, $occurrence] = $this->publicEventAndOccurrence($event, $occurrence);
        $unavailableMessage = $this->occurrenceUnavailableMessage($event, $occurrence);

        Log::info('public_event_registration_form_route_loaded', [
            'event_id' => (string) $event->id,
            'occurrence_id' => (string) $occurrence->id,
            'registration_id' => $request->query('registration_id'),
        ]);

        $registration = null;
        $payment = null;
        $qr = null;

        if ($request->filled('registration_id')) {
            $registration = EventRegistration::query()
                ->where('event_id', $event->id)
                ->where('occurrence_id', $occurrence->id)
                ->findOrFail((string) $request->query('registration_id'));

            if ((string) $registration->event_id !== (string) $event->id || (string) $registration->occurrence_id !== (string) $occurrence->id) {
                Log::warning('public_event_registration_form_registration_mismatch', [
                    'requested_event_id' => (string) $event->id,
                    'requested_occurrence_id' => (string) $occurrence->id,
                    'registration_id' => (string) $registration->id,
                    'registration_event_id' => (string) $registration->event_id,
                    'registration_occurrence_id' => (string) $registration->occurrence_id,
                ]);
                abort(404);
            }
            $registration = $this->prepareRegistrationForDisplay($registration);
            $payment = $this->payments->responsePayload($registration);
            $qr = $this->qrDetailsForDisplay($registration);
        }

        return view('public.events.visitor-register', [
            'event' => $event,
            'occurrence' => $occurrence,
            'categories' => $this->categories(),
            'payment' => $payment,
            'qr' => $qr,
            'registration' => $registration,
            'unavailableMessage' => $unavailableMessage,
        ]);
    }

    public function submit(VisitorEventRegistrationRequest $request, string $event, string $occurrence): RedirectResponse
    {
        [$event, $occurrence] = $this->publicEventAndOccurrence($event, $occurrence);

        if ($message = $this->occurrenceUnavailableMessage($event, $occurrence)) {
            return back()
                ->withInput()
                ->withErrors(['registration' => $message]);
        }

        try {
            $registration = $this->registrations->registerVisitor(
                $event,
                $occurrence,
                $request->validated(),
                'web_form'
            );
        } catch (\Throwable $exception) {
            Log::error('public_event_registration_form_submit_failed', [
                'event_id' => $event->id,
                'occurrence_id' => $occurrence->id,
                'error' => $exception->getMessage(),
            ]);

            return back()
                ->withInput()
                ->withErrors(['registration' => $exception->getMessage() ?: 'Unable to complete registration. Please try again.']);
        }

        $registration = $this->registrations->ensureVisitorRegistrationFormUrl($registration);

        return redirect()->to($this->registrations->visitorRegistrationFormUrl($registration));
    }


    private function occurrenceUnavailableMessage(Event $event, EventOccurrence $occurrence): ?string
    {
        $status = strtolower((string) ($occurrence->status ?? $event->status ?? 'scheduled'));

        if (in_array($status, ['cancelled', 'canceled', 'rejected', 'deleted', 'archived', 'inactive'], true)) {
            return 'Registration is currently unavailable for this event occurrence.';
        }

        $limit = $occurrence->registration_limit ?? $event->registration_limit;
        if ($limit && (int) ($occurrence->registered_count ?? 0) >= (int) $limit) {
            return 'Registration is closed because this event occurrence is full.';
        }

        return null;
    }

    private function prepareRegistrationForDisplay(EventRegistration $registration): EventRegistration
    {
        $registration = $this->registrations->ensureVisitorRegistrationFormUrl($registration);

        if ($this->registrationUsesZohoPaymentLink($registration)
            && in_array(strtolower((string) ($registration->payment_status ?? '')), ['pending', 'processing', 'failed', 'expired'], true)) {
            try {
                $syncResult = $this->paymentSync->syncRegistrationPayment($registration, ['source' => 'visitor_form']);
                $registration = $syncResult['registration'];
            } catch (\Throwable $exception) {
                Log::warning('public_event_registration_form_payment_sync_failed', [
                    'registration_id' => (string) $registration->id,
                    'error' => $exception->getMessage(),
                ]);
                $registration = $registration->fresh() ?? $registration;
            }
        }

        $registration = $this->ensurePendingPaymentUrl($registration);

        if ($this->registrationIsConfirmed($registration)) {
            $registration = $this->registrationQr->ensureQrGenerated($registration);
        }

        return $registration->fresh(['event.circle', 'occurrence', 'user', 'invitedByUser', 'businessCategoryMain', 'businessCategorySub']) ?? $registration;
    }

    private function qrDetailsForDisplay(?EventRegistration $registration): ?array
    {
        if (! $registration || ! $this->registrationIsConfirmed($registration)) {
            return null;
        }

        return $this->registrations->qrDetails($registration);
    }

    private function ensurePendingPaymentUrl(EventRegistration $registration): EventRegistration
    {
        if (! (bool) ($registration->payment_required ?? false)
            || $this->registrationIsConfirmed($registration)
            || ! empty($this->registrationPaymentUrl($registration))) {
            return $registration;
        }

        Log::warning('public_event_registration_form_payment_url_missing', [
            'registration_id' => (string) $registration->id,
            'event_id' => (string) $registration->event_id,
            'occurrence_id' => (string) $registration->occurrence_id,
            'payment_gateway' => $registration->payment_gateway,
            'payment_status' => $registration->payment_status,
        ]);

        try {
            return $this->payments->attachCheckout($registration->fresh(['event.circle', 'occurrence', 'user', 'invitedByUser', 'businessCategoryMain', 'businessCategorySub']));
        } catch (\Throwable $exception) {
            Log::error('public_event_registration_form_payment_url_regeneration_failed', [
                'registration_id' => (string) $registration->id,
                'error' => $exception->getMessage(),
            ]);

            $registration->forceFill(array_filter([
                'payment_gateway' => 'zoho_billing_payment_link',
                'payment_status' => 'pending',
                'status' => 'pending_payment',
                'zoho_invoice_sync_error' => $exception->getMessage(),
            ], fn ($value, $key) => Schema::hasColumn('event_registrations', $key), ARRAY_FILTER_USE_BOTH))->save();

            return $registration->fresh(['event.circle', 'occurrence', 'user', 'invitedByUser', 'businessCategoryMain', 'businessCategorySub']) ?? $registration;
        }
    }

    private function registrationPaymentUrl(EventRegistration $registration): ?string
    {
        return $registration->payment_url
            ?? $registration->checkout_url
            ?? $registration->zoho_checkout_url
            ?? $registration->zoho_payment_link_url
            ?? $registration->zoho_hosted_page_url
            ?? null;
    }

    private function registrationUsesZohoPaymentLink(EventRegistration $registration): bool
    {
        return ($registration->payment_gateway ?? '') === 'zoho_billing_payment_link'
            || ! empty($registration->zoho_payment_link_url)
            || ! empty($registration->zoho_checkout_url)
            || ! empty($registration->zoho_hosted_page_url);
    }

    private function registrationIsConfirmed(EventRegistration $registration): bool
    {
        if (! (bool) ($registration->payment_required ?? false)) {
            return true;
        }

        return in_array(strtolower((string) ($registration->payment_status ?? '')), ['paid', 'success', 'completed'], true);
    }

    private function publicEventAndOccurrence(string $eventId, string $occurrenceId): array
    {
        $occurrence = EventOccurrence::query()
            ->with(['event.circle'])
            ->where('event_id', $eventId)
            ->findOrFail($occurrenceId);
        $event = $occurrence->event ?: Event::query()->findOrFail($eventId);

        abort_unless(
            $event->is_public || $event->visibility === 'public' || $this->events->visitorRegistrationEnabled($event),
            403,
            'Event is not available for public registration.'
        );
        abort_unless(
            $this->events->visitorRegistrationEnabled($event),
            403,
            'Visitor registration is not enabled for this event.'
        );

        return [$event, $occurrence];
    }

    private function categories(): array
    {
        $main = Schema::hasTable('circle_categories')
            ? CircleCategory::query()->orderBy('name')->get(['id', 'name'])
            : collect();
        $sub = (Schema::hasTable('level4_categories') || Schema::hasTable('circle_category_level4'))
            ? CircleCategoryLevel4::query()->orderBy('name')->get(['id', 'name'])
            : collect();

        return [
            'main' => $main,
            'sub' => $sub,
        ];
    }
}
