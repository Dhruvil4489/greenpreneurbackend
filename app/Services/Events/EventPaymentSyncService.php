<?php

namespace App\Services\Events;

use App\Models\EventRegistration;
use App\Services\Zoho\ZohoBillingPaymentLinkService;
use Illuminate\Support\Facades\Schema;

class EventPaymentSyncService
{
    public function __construct(private readonly ZohoBillingPaymentLinkService $zohoPaymentLinks) {}

    public function syncRegistrationPayment(EventRegistration $registration, array $options = []): array
    {
        $updates = [];
        if (isset($options['payload'])) {
            $updates['zoho_payment_webhook_payload'] = $options['payload'];
            $updates['webhook_payload'] = $options['payload'];
        }
        if (! empty($options['payment_id']) && empty($registration->zoho_payment_id)) {
            $updates['zoho_payment_id'] = $options['payment_id'];
        }
        if (! empty($updates)) {
            $registration->forceFill($this->filter($updates))->save();
            $registration->refresh();
        }

        if (($registration->payment_gateway ?? '') === 'zoho_billing_payment_link' && ! empty($registration->zoho_payment_link_id)) {
            $registration = $this->zohoPaymentLinks->syncPaymentStatus($registration->fresh(['event', 'occurrence', 'user']));
        }

        return [
            'registration' => $registration->fresh(['event', 'occurrence', 'user']),
            'payment_status' => $registration->payment_status,
            'zoho_invoice_status' => $registration->zoho_invoice_status,
            'qr_code_url' => $registration->qr_code_path ? app(EventQrService::class)->url($registration->qr_code_path) : $registration->qr_code_url,
        ];
    }

    private function filter(array $data): array
    {
        return array_filter($data, fn ($value, $key) => Schema::hasColumn('event_registrations', $key), ARRAY_FILTER_USE_BOTH);
    }
}
