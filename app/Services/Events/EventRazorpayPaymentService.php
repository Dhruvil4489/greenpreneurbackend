<?php

namespace App\Services\Events;

use App\Models\EventRegistration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Razorpay\Api\Api;

class EventRazorpayPaymentService
{
    public function createOrder(EventRegistration $registration): EventRegistration
    {
        $registration->loadMissing(['event', 'occurrence', 'user']);

        if (! (bool) ($registration->payment_required ?? false)) {
            return $registration;
        }

        if (! empty($registration->razorpay_order_id)) {
            return $registration->fresh(['event.circle', 'occurrence', 'user']);
        }

        $amount = round((float) ($registration->amount ?? 0), 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Paid event amount must be greater than zero.']);
        }

        $currency = strtoupper((string) ($registration->currency ?: config('razorpay.currency', 'INR')));

        try {
            $api = new Api((string) config('razorpay.key_id'), (string) config('razorpay.key_secret'));
            $order = $api->order->create([
                'amount' => (int) round($amount * 100),
                'currency' => $currency,
                'receipt' => 'EVT-'.$registration->id,
                'notes' => [
                    'type' => 'event_registration',
                    'registration_id' => (string) $registration->id,
                    'event_id' => (string) $registration->event_id,
                    'occurrence_id' => (string) $registration->occurrence_id,
                ],
            ]);
        } catch (\Throwable $exception) {
            Log::error('Razorpay event order creation failed', [
                'event_registration_id' => (string) $registration->id,
                'error' => $exception->getMessage(),
            ]);

            throw ValidationException::withMessages(['payment' => 'Unable to create Razorpay order.']);
        }

        return DB::transaction(function () use ($registration, $order): EventRegistration {
            $locked = EventRegistration::query()->lockForUpdate()->findOrFail($registration->id);
            $locked->forceFill($this->filterRegistrationColumns([
                'razorpay_order_id' => (string) ($order['id'] ?? ''),
                'razorpay_payment_status' => 'created',
                'payment_status' => 'pending',
            ]))->save();

            return $locked->fresh(['event.circle', 'occurrence', 'user']);
        });
    }

    public function checkoutPayload(EventRegistration $registration): array
    {
        $registration->loadMissing(['event', 'occurrence', 'user']);
        $amount = round((float) ($registration->amount ?? 0), 2);
        $currency = strtoupper((string) ($registration->currency ?: config('razorpay.currency', 'INR')));
        $attendee = $this->attendee($registration);

        return [
            'key_id' => config('razorpay.key_id'),
            'order_id' => $registration->razorpay_order_id,
            'amount' => (int) round($amount * 100),
            'currency' => $currency,
            'name' => 'Peers Global Unity',
            'description' => 'Event Registration - '.(string) ($registration->event?->title ?? 'Event'),
            'prefill' => [
                'name' => $attendee['name'],
                'email' => $attendee['email'],
                'contact' => $attendee['phone'],
            ],
        ];
    }

    public function verifySignature(string $orderId, string $paymentId, string $signature): bool
    {
        $secret = (string) config('razorpay.key_secret');
        if ($secret === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $orderId.'|'.$paymentId, $secret);

        return hash_equals($expected, $signature);
    }

    private function attendee(EventRegistration $registration): array
    {
        if ($registration->user) {
            $user = $registration->user;
            return [
                'name' => $user->display_name ?: trim(($user->first_name ?? '').' '.($user->last_name ?? '')),
                'email' => (string) $user->email,
                'phone' => (string) ($user->phone ?? $user->mobile ?? ''),
            ];
        }

        return [
            'name' => (string) $registration->visitor_name,
            'email' => (string) $registration->visitor_email,
            'phone' => (string) $registration->visitor_phone,
        ];
    }

    private function filterRegistrationColumns(array $data): array
    {
        return array_filter($data, fn ($value, $key) => Schema::hasColumn('event_registrations', $key), ARRAY_FILTER_USE_BOTH);
    }
}
