<?php

namespace App\Http\Controllers;

use App\Http\Requests\Event\VisitorEventRegistrationRequest;
use App\Models\CircleCategory;
use App\Models\CircleCategoryLevel4;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Services\Events\EventPaymentService;
use App\Services\Events\EventRegistrationService;
use App\Services\Events\EventRegistrationQrService;
use App\Services\Events\EventService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class PublicEventRegistrationFormController extends Controller
{
    public function __construct(
        private readonly EventService $events,
        private readonly EventRegistrationService $registrations,
        private readonly EventPaymentService $payments,
        private readonly EventRegistrationQrService $registrationQr,
    ) {}

    public function show(string $event, string $occurrence): View
    {
        [$event, $occurrence] = $this->publicEventAndOccurrence($event, $occurrence);

        return view('events.visitor-register', [
            'event' => $event,
            'occurrence' => $occurrence,
            'categories' => $this->categories(),
            'payment' => null,
            'qr' => null,
            'registration' => null,
        ]);
    }

    public function submit(VisitorEventRegistrationRequest $request, string $event, string $occurrence): View|RedirectResponse
    {
        [$event, $occurrence] = $this->publicEventAndOccurrence($event, $occurrence);

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

        $registration = $this->registrationQr->ensureQrGenerated($registration);
        $payment = $this->payments->responsePayload($registration);
        $qr = ((bool) ($registration->payment_required ?? false) && ($registration->payment_status ?? null) !== 'paid')
            ? null
            : $this->registrations->qrDetails($registration);

        return view('events.visitor-register', [
            'event' => $event,
            'occurrence' => $occurrence,
            'categories' => $this->categories(),
            'payment' => $payment,
            'qr' => $qr,
            'registration' => $registration,
        ]);
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
