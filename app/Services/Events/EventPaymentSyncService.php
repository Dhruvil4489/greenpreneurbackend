<?php

namespace App\Services\Events;

use App\Models\EventRegistration;
use App\Services\Zoho\ZohoBillingPaymentLinkService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class EventPaymentSyncService
{
    public function __construct(
        private readonly ZohoBillingPaymentLinkService $zohoPaymentLinks,
        private readonly EventRegistrationQrService $registrationQr,
    ) {}

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

        if ($this->usesZohoPaymentLink($registration)) {
            Log::info('event_payment_status_api_zoho_sync_start', [
                'registration_id' => (string) $registration->id,
                'payment_status' => $registration->payment_status,
                'zoho_payment_link_id' => $registration->zoho_payment_link_id,
                'zoho_payment_session_id' => $registration->zoho_payment_session_id,
                'zoho_hosted_page_id' => $registration->zoho_hosted_page_id,
                'zoho_payment_id' => $registration->zoho_payment_id,
            ]);
            $registration = $this->zohoPaymentLinks->syncPaymentStatus($registration->fresh(['event', 'occurrence', 'user']));
            Log::info('event_payment_status_api_zoho_sync_result', [
                'registration_id' => (string) $registration->id,
                'payment_status' => $registration->payment_status,
                'zoho_payment_status' => $registration->zoho_payment_status,
                'zoho_payment_id' => $registration->zoho_payment_id,
            ]);
        }

        if (in_array(strtolower((string) ($registration->payment_status ?? '')), ['paid', 'success', 'completed'], true)) {
            $registration = $this->registrationQr->ensureQrGenerated($registration);
        }

        return [
            'registration' => $registration->fresh(['event', 'occurrence', 'user']),
            'payment_status' => $registration->payment_status,
            'zoho_invoice_status' => $registration->zoho_invoice_status,
            'qr_code_url' => $this->registrationQr->qrCodeUrl($registration),
        ];
    }

    private function usesZohoPaymentLink(EventRegistration $registration): bool
    {
        return ($registration->payment_gateway ?? '') === 'zoho_billing_payment_link'
            || ! empty($registration->zoho_payment_link_url)
            || ! empty($registration->zoho_checkout_url)
            || ! empty($registration->zoho_hosted_page_url);
    }

    private function filter(array $data): array
    {
        return array_filter($data, fn ($value, $key) => Schema::hasColumn('event_registrations', $key), ARRAY_FILTER_USE_BOTH);
    }
}
