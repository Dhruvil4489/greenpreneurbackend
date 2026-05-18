<?php

namespace App\Services\Events;

use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventRegistration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EventPaymentService
{
    public function __construct(private readonly EventRazorpayPaymentService $razorpay) {}

    public function paymentRequired(Event $event): bool
    {
        return (bool) ($event->is_paid ?? false) || (float) ($event->ticket_price ?? 0) > 0;
    }

    public function amount(Event $event): float
    {
        return round(max((float) ($event->ticket_price ?? 0), 0), 2);
    }

    public function currency(Event $event): string
    {
        $currency = (string) data_get($event->metadata, 'currency', 'INR');

        return $currency !== '' ? strtoupper($currency) : 'INR';
    }

    public function applyInitialPaymentState(EventRegistration $registration, Event $event, string $registrationType): EventRegistration
    {
        $paymentRequired = $this->paymentRequired($event);
        $updates = [
            'payment_required' => $paymentRequired,
            'payment_status' => $paymentRequired ? 'pending' : 'not_required',
            'amount' => $paymentRequired ? $this->amount($event) : 0,
            'currency' => $this->currency($event),
            'registration_type' => $registrationType,
        ];

        if ($paymentRequired) {
            $updates['status'] = 'pending_payment';
            $updates['checkin_status'] = 'pending';
        }

        $registration->forceFill($this->filterRegistrationColumns($updates))->save();

        return $registration->fresh(['event.circle', 'occurrence', 'user']);
    }

    public function attachCheckout(EventRegistration $registration): EventRegistration
    {
        if (! (bool) ($registration->payment_required ?? false)) {
            return $registration;
        }

        $registration = $this->razorpay->createOrder($registration->fresh(['event', 'occurrence', 'user']));

        return DB::transaction(function () use ($registration): EventRegistration {
            $registration = EventRegistration::query()->lockForUpdate()->findOrFail($registration->id);
            $metadata = array_merge((array) ($registration->metadata ?? []), [
                'event_payment' => [
                    'type' => 'event_registration',
                    'gateway' => 'razorpay',
                    'registration_id' => (string) $registration->id,
                    'event_id' => (string) $registration->event_id,
                    'occurrence_id' => (string) $registration->occurrence_id,
                ],
            ]);

            $registration->forceFill($this->filterRegistrationColumns([
                'metadata' => $metadata,
            ]))->save();

            return $registration->fresh(['event.circle', 'occurrence', 'user']);
        });
    }

    public function responsePayload(EventRegistration $registration): array
    {
        $requiresPayment = (bool) ($registration->payment_required ?? false);

        $payload = [
            'registration_id' => $registration->id,
            'requires_payment' => $requiresPayment,
            'payment_status' => $registration->payment_status ?? ($requiresPayment ? 'pending' : 'not_required'),
            'amount' => $registration->amount !== null ? (string) $registration->amount : null,
            'currency' => $registration->currency ?? 'INR',
            'payment_gateway' => $requiresPayment ? 'razorpay' : null,
            'checkout_url' => null,
            'qr_code_url' => $requiresPayment && ($registration->payment_status ?? null) !== 'paid'
                ? null
                : ($registration->qr_code_url ?: app(EventQrService::class)->url($registration->qr_code_path)),
        ];

        if ($requiresPayment) {
            $payload['razorpay'] = $this->razorpay->checkoutPayload($registration);
        }

        return $payload;
    }

    private function filterRegistrationColumns(array $data): array
    {
        return array_filter($data, fn ($value, $key) => Schema::hasColumn('event_registrations', $key), ARRAY_FILTER_USE_BOTH);
    }
}
