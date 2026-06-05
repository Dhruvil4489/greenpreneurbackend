<?php

namespace App\Services\Events;

use App\Models\EventRegistration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class EventRazorpayPaymentFinalizer
{
    public function __construct(
        private readonly EventRegistrationQrService $registrationQr,
        private readonly EventZohoInvoiceSyncService $zohoInvoices,
    ) {}

    public function markPaid(EventRegistration $registration, array $paymentData = []): EventRegistration
    {
        $registration = DB::transaction(function () use ($registration, $paymentData): EventRegistration {
            $locked = EventRegistration::query()->lockForUpdate()->findOrFail($registration->id);

            if (($locked->payment_status ?? null) !== 'paid') {
                $locked->forceFill($this->filterRegistrationColumns([
                    'payment_status' => 'paid',
                    'status' => 'registered',
                    'payment_completed_at' => now(),
                    'razorpay_payment_id' => $paymentData['razorpay_payment_id'] ?? $locked->razorpay_payment_id,
                    'razorpay_signature' => $paymentData['razorpay_signature'] ?? $locked->razorpay_signature,
                    'razorpay_payment_status' => $paymentData['razorpay_payment_status'] ?? 'captured',
                    'razorpay_paid_at' => now(),
                ]))->save();
                Log::info('payment_success_registration_updated', ['event_registration_id' => (string) $locked->id]);
            }

            $locked = $this->registrationQr->ensureQrGenerated($locked);

            return $locked->fresh(['event.circle', 'occurrence', 'user']);
        });

        return $this->zohoInvoices->sync($registration);
    }

    private function filterRegistrationColumns(array $data): array
    {
        return array_filter($data, fn ($value, $key) => Schema::hasColumn('event_registrations', $key), ARRAY_FILTER_USE_BOTH);
    }
}
