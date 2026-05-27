<?php

namespace App\Services\Zoho;

use App\Models\EventRegistration;
use App\Services\Events\EventQrService;
use App\Services\Events\EventZohoInvoiceSyncService;
use App\Support\Zoho\ZohoBillingClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ZohoBillingPaymentLinkService
{
    public function __construct(
        private readonly ZohoBillingClient $client,
        private readonly EventQrService $qrService,
        private readonly EventZohoInvoiceSyncService $invoiceSyncService,
    ) {}

    public function findOrCreateCustomer(EventRegistration $registration): ?string
    {
        if (! empty($registration->zoho_customer_id)) {
            return (string) $registration->zoho_customer_id;
        }

        $registration->loadMissing(['user']);
        $email = (string) ($registration->user?->email ?: $registration->visitor_email ?: '');
        if ($email === '') {
            return null;
        }

        $phone = (string) ($registration->user?->phone ?: $registration->visitor_phone ?: '');
        $name = (string) ($registration->user?->name ?: $registration->visitor_name ?: 'Event Attendee');

        $search = $this->client->request('GET', '/customers', ['email' => $email], true);
        $customer = data_get($search, 'customers.0');
        $customerId = data_get($customer, 'customer_id');

        if (! $customerId) {
            $created = $this->client->request('POST', '/customers', [
                'display_name' => $name,
                'email' => $email,
                'phone' => $phone,
            ]);
            $customerId = data_get($created, 'customer.customer_id') ?? data_get($created, 'customer_id');
        }

        if ($customerId) {
            $registration->forceFill($this->filter(['zoho_customer_id' => $customerId]))->save();
        }

        return $customerId ? (string) $customerId : null;
    }

    public function createPaymentLink(EventRegistration $registration): EventRegistration
    {
        $registration->loadMissing(['event', 'user']);

        if (($registration->payment_status ?? null) === 'pending' && ! empty($registration->zoho_payment_link_url)) {
            return $registration;
        }

        $event = $registration->event;
        $amountValue = (float) ($registration->payment_amount ?? $registration->amount ?? 0);
        if ($amountValue < 1) {
            throw ValidationException::withMessages([
                'ticket_price' => 'Paid event ticket price must be at least ₹1 for Zoho payment link.',
            ]);
        }

        $customerId = $this->findOrCreateCustomer($registration);
        if (empty($customerId)) {
            throw ValidationException::withMessages([
                'customer' => 'Zoho customer_id missing for payment link.',
            ]);
        }

        $payload = [
            'customer_id' => (string) $customerId,
            'payment_amount' => (float) ($registration->payment_amount ?? $registration->amount ?? 0),
            'description' => 'Event Registration - '.($event->title ?? 'Event'),
            'expiry_time' => now()->addDays(15)->format('Y-m-d'),
        ];

        Log::info('zoho_billing_payment_link_create_request', [
            'registration_id' => (string) $registration->id,
            'payload' => $payload,
            'body_keys' => array_keys($payload),
        ]);
        Log::info('Zoho Billing request path=/paymentlinks', ['registration_id' => (string) $registration->id]);

        try {
            $response = $this->client->request('POST', '/paymentlinks', $payload);
            $link = data_get($response, 'payment_link') ?? data_get($response, 'payment_links') ?? $response;
            $url = data_get($link, 'url');

            if (empty($url)) {
                Log::error('zoho_billing_payment_link_error', [
                    'registration_id' => (string) $registration->id,
                    'response' => $response,
                    'error' => 'Zoho Billing payment link URL missing.',
                ]);

                throw new \RuntimeException('Zoho Billing payment link URL missing.');
            }

            $registration->forceFill($this->filter([
                'zoho_payment_link_id' => data_get($link, 'payment_link_id') ?? null,
                'zoho_payment_link_url' => $url,
                'payment_url' => $url,
                'checkout_url' => $url,
                'zoho_payment_status' => data_get($link, 'status', 'Generated'),
                'payment_gateway' => 'zoho_billing_payment_link',
                'payment_status' => 'pending',
                'status' => 'pending_payment',
            ]))->save();

            Log::info('zoho_billing_payment_link_created', [
                'registration_id' => (string) $registration->id,
                'zoho_payment_link_id' => $registration->zoho_payment_link_id,
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $registration->forceFill($this->filter([
                'zoho_invoice_sync_error' => $e->getMessage(),
                'payment_status' => 'pending',
                'status' => 'pending_payment',
            ]))->save();
        }

        return $registration->fresh(['event', 'occurrence', 'user']);
    }

    public function syncPaymentStatus(EventRegistration $registration): EventRegistration
    {
        $registration->loadMissing(['event', 'occurrence', 'user']);

        if (($registration->payment_gateway ?? null) !== 'zoho_billing_payment_link' || empty($registration->zoho_payment_link_id)) {
            return $registration;
        }

        Log::info('zoho_billing_payment_link_sync_start', ['registration_id' => (string) $registration->id]);
        $response = $this->client->request('GET', '/paymentlinks/'.$registration->zoho_payment_link_id);
        Log::info('zoho_billing_payment_link_sync_response', ['registration_id' => (string) $registration->id, 'response' => $response]);

        $link = data_get($response, 'payment_link') ?? data_get($response, 'payment_links') ?? $response;
        return $this->markRegistrationPaid($registration, $link, $response);
    }

    public function markRegistrationPaid(EventRegistration $registration, array $paymentLinkData, ?array $rawPayload = null): EventRegistration
    {
        $status = strtolower((string) (data_get($paymentLinkData, 'status') ?? ''));
        $alreadyPaid = ($registration->payment_status ?? null) === 'paid';
        if (! $alreadyPaid && ! in_array($status, ['paid', 'success', 'succeeded'], true)) {
            return $registration;
        }

        if (! $alreadyPaid) {
            $registration->forceFill($this->filter([
                'status' => 'registered',
                'payment_status' => 'paid',
                'zoho_payment_status' => 'paid',
                'payment_completed_at' => now(),
                'zoho_payment_id' => data_get($paymentLinkData, 'payments.0.payment_id') ?? data_get($paymentLinkData, 'payment_id') ?? data_get($paymentLinkData, 'transaction_id'),
                'webhook_payload' => $rawPayload ?? $paymentLinkData,
                'zoho_payment_webhook_payload' => $rawPayload ?? $paymentLinkData,
            ]))->save();
        }

        if (empty($registration->qr_code_url) && empty($registration->qr_code_path)) {
            $this->qrService->generateAndStore($registration);
            Log::info('zoho_billing_payment_link_qr_generated', ['registration_id' => (string) $registration->id]);
        }

        Log::info('zoho_billing_payment_link_marked_paid', ['registration_id' => (string) $registration->id]);

        try {
            $registration = $this->invoiceSyncService->sync($registration->fresh(['event', 'occurrence', 'user']));
            $syncResult = $this->invoiceSyncService->finalizeAndApplyPaymentToEventInvoice($registration);
            $registration->forceFill($this->filter([
                'zoho_invoice_status' => $syncResult['status'] ?? $registration->zoho_invoice_status,
                'zoho_invoice_synced_at' => now(),
                'zoho_invoice_sync_error' => $syncResult['sync_error'] ?? null,
                'zoho_payment_id' => $syncResult['payment_id'] ?? $registration->zoho_payment_id,
                'zoho_invoice_url' => $syncResult['invoice_url'] ?? $registration->zoho_invoice_url,
                'zoho_invoice_pdf_url' => $syncResult['invoice_pdf_url'] ?? $registration->zoho_invoice_pdf_url,
            ]))->save();
            if (! empty($registration->zoho_invoice_id)) {
                Log::info('zoho_billing_payment_link_invoice_created', ['registration_id' => (string) $registration->id, 'zoho_invoice_id' => $registration->zoho_invoice_id]);
            }
        } catch (\Throwable $e) {
            $registration->forceFill($this->filter(['zoho_invoice_sync_error' => $e->getMessage()]))->save();
            Log::error('zoho_billing_payment_link_invoice_failed', ['registration_id' => (string) $registration->id, 'error' => $e->getMessage()]);
        }

        return $registration->fresh(['event', 'occurrence', 'user']);
    }

    private function filter(array $data): array
    {
        return array_filter($data, fn ($value, $key) => Schema::hasColumn('event_registrations', $key), ARRAY_FILTER_USE_BOTH);
    }
}
