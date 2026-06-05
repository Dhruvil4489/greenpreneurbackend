<?php

namespace App\Services\Zoho;

use App\Models\EventRegistration;
use App\Services\Events\EventRegistrationQrService;
use App\Services\Events\EventZohoInvoiceSyncService;
use App\Support\Zoho\ZohoBillingClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ZohoBillingPaymentLinkService
{
    public function __construct(
        private readonly ZohoBillingClient $client,
        private readonly EventRegistrationQrService $registrationQr,
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

        $description = 'Event Registration - '.($event->title ?? 'Event')
            .' | registration_id='.(string) $registration->id
            .' | event_id='.(string) $registration->event_id
            .' | occurrence_id='.(string) $registration->occurrence_id;

        $payload = [
            'customer_id' => (string) $customerId,
            'payment_amount' => (float) ($registration->payment_amount ?? $registration->amount ?? 0),
            'description' => $description,
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

        if (($registration->payment_gateway ?? null) !== 'zoho_billing_payment_link') {
            return $registration;
        }

        Log::info('zoho_billing_payment_link_sync_start', [
            'registration_id' => (string) $registration->id,
            'zoho_payment_link_id' => $registration->zoho_payment_link_id,
            'zoho_hosted_page_id' => $registration->zoho_hosted_page_id,
            'zoho_payment_id' => $registration->zoho_payment_id,
        ]);

        foreach ($this->syncEndpoints($registration) as $endpoint) {
            try {
                $response = $this->client->request('GET', $endpoint['path']);
                Log::info('zoho_billing_payment_link_sync_response', [
                    'registration_id' => (string) $registration->id,
                    'path' => $endpoint['path'],
                    'response' => $response,
                ]);

                $node = data_get($response, $endpoint['root']) ?? data_get($response, 'payment_link') ?? data_get($response, 'payment_links') ?? data_get($response, 'hostedpage') ?? data_get($response, 'payment') ?? data_get($response, 'customerpayment') ?? $response;
                $registration = $this->markRegistrationPaid($registration->fresh(['event', 'occurrence', 'user']), is_array($node) ? $node : $response, $response);

                if (in_array(strtolower((string) ($registration->payment_status ?? '')), ['paid', 'success', 'completed'], true)) {
                    Log::info('zoho_billing_payment_link_sync_marked_paid', [
                        'registration_id' => (string) $registration->id,
                        'path' => $endpoint['path'],
                    ]);
                    return $registration;
                }
            } catch (\Throwable $e) {
                Log::warning('zoho_billing_payment_link_sync_endpoint_failed', [
                    'registration_id' => (string) $registration->id,
                    'path' => $endpoint['path'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('zoho_billing_payment_link_sync_no_paid_status', ['registration_id' => (string) $registration->id]);

        return $registration->fresh(['event', 'occurrence', 'user']);
    }

    private function syncEndpoints(EventRegistration $registration): array
    {
        $endpoints = [];
        $paymentLinkId = $registration->zoho_payment_link_id ?: $this->identifierFromUrls($registration, 'paymentlink');
        if (! empty($paymentLinkId)) {
            $endpoints[] = ['path' => '/paymentlinks/'.$paymentLinkId, 'root' => 'payment_link'];
        }
        if (! empty($registration->zoho_payment_id)) {
            $endpoints[] = ['path' => '/customerpayments/'.$registration->zoho_payment_id, 'root' => 'customerpayment'];
            $endpoints[] = ['path' => '/payments/'.$registration->zoho_payment_id, 'root' => 'payment'];
        }
        $hostedPageId = $registration->zoho_hosted_page_id ?: $this->identifierFromUrls($registration, 'hostedpage');
        if (! empty($hostedPageId)) {
            $endpoints[] = ['path' => '/hostedpages/'.$hostedPageId, 'root' => 'hostedpage'];
        }
        if (! empty($registration->zoho_invoice_id)) {
            $endpoints[] = ['path' => '/invoices/'.$registration->zoho_invoice_id, 'root' => 'invoice'];
        }

        return collect($endpoints)->unique('path')->values()->all();
    }

    private function identifierFromUrls(EventRegistration $registration, string $type): ?string
    {
        $urls = array_filter([
            $registration->zoho_payment_link_url ?? null,
            $registration->zoho_checkout_url ?? null,
            $registration->zoho_hosted_page_url ?? null,
            $registration->payment_url ?? null,
        ]);

        foreach ($urls as $url) {
            if ($type === 'paymentlink' && preg_match('~/paymentlinks?/([^/?#]+)~i', (string) $url, $matches)) {
                return $matches[1];
            }
            if ($type === 'hostedpage' && preg_match('~/hostedpages?/([^/?#]+)~i', (string) $url, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    public function markRegistrationPaid(EventRegistration $registration, array $paymentLinkData, ?array $rawPayload = null): EventRegistration
    {
        $status = strtolower((string) (data_get($paymentLinkData, 'status') ?? data_get($paymentLinkData, 'payment_status') ?? data_get($paymentLinkData, 'invoice.status') ?? ''));
        $paymentId = data_get($paymentLinkData, 'customer_payments.0.payment_id')
            ?? data_get($paymentLinkData, 'payments.0.payment_id')
            ?? data_get($paymentLinkData, 'payment.payment_id')
            ?? data_get($paymentLinkData, 'customerpayment.payment_id')
            ?? data_get($paymentLinkData, 'payment_id')
            ?? data_get($paymentLinkData, 'transaction_id')
            ?? data_get($paymentLinkData, 'online_transaction_id');
        $paidStatuses = ['paid', 'success', 'succeeded', 'completed', 'payment_success', 'captured'];
        $hasPaymentRecord = ! empty($paymentId)
            || ! empty(data_get($paymentLinkData, 'customer_payments.0'))
            || ! empty(data_get($paymentLinkData, 'payments.0'))
            || ! empty(data_get($paymentLinkData, 'payment'))
            || ! empty(data_get($paymentLinkData, 'customerpayment'));
        $isPaid = in_array($status, $paidStatuses, true) || ($hasPaymentRecord && ! in_array($status, ['generated', 'pending', 'failed', 'failure', 'cancelled', 'canceled', 'expired'], true));
        Log::info('zoho_payment_id_save_attempt', [
            'registration_id' => (string) $registration->id,
            'invoice_id' => $registration->zoho_invoice_id,
            'customer_id' => $registration->zoho_customer_id,
            'payment_id' => $paymentId,
            'payment_amount' => (float) ($registration->payment_amount ?? $registration->amount ?? 0),
        ]);
        if (! $isPaid) {
            return $registration;
        }

        $paymentDate = data_get($paymentLinkData, 'customer_payments.0.payment_date')
            ?? data_get($paymentLinkData, 'payments.0.payment_date')
            ?? data_get($paymentLinkData, 'payment.date')
            ?? data_get($paymentLinkData, 'customerpayment.date')
            ?? data_get($paymentLinkData, 'date')
            ?? data_get($paymentLinkData, 'payment_date')
            ?? data_get($paymentLinkData, 'paid_at');
        $amount = data_get($paymentLinkData, 'customer_payments.0.amount')
            ?? data_get($paymentLinkData, 'payments.0.amount')
            ?? data_get($paymentLinkData, 'payment.amount')
            ?? data_get($paymentLinkData, 'customerpayment.amount')
            ?? data_get($paymentLinkData, 'amount');
        $currency = data_get($paymentLinkData, 'customer_payments.0.currency_code')
            ?? data_get($paymentLinkData, 'payments.0.currency_code')
            ?? data_get($paymentLinkData, 'payment.currency_code')
            ?? data_get($paymentLinkData, 'customerpayment.currency_code')
            ?? data_get($paymentLinkData, 'currency_code')
            ?? data_get($paymentLinkData, 'currency');

        $registration->forceFill($this->filter([
            'status' => 'registered',
            'payment_status' => 'paid',
            'zoho_payment_status' => 'paid',
            'payment_completed_at' => $registration->payment_completed_at ?: ($paymentDate ? now()->parse((string) $paymentDate) : now()),
            'zoho_payment_id' => $paymentId ?: $registration->zoho_payment_id,
            'amount' => $amount ?? $registration->amount,
            'payment_amount' => $amount ?? $registration->payment_amount,
            'currency' => $currency ?: $registration->currency,
            'payment_currency' => $currency ?: $registration->payment_currency,
            'zoho_invoice_id' => data_get($paymentLinkData, 'invoice.invoice_id') ?? data_get($paymentLinkData, 'invoice_id') ?? $registration->zoho_invoice_id,
            'zoho_invoice_number' => data_get($paymentLinkData, 'invoice.invoice_number') ?? data_get($paymentLinkData, 'invoice_number') ?? $registration->zoho_invoice_number,
            'zoho_invoice_url' => data_get($paymentLinkData, 'invoice.invoice_url') ?? data_get($paymentLinkData, 'invoice_url') ?? $registration->zoho_invoice_url,
            'zoho_invoice_pdf_url' => data_get($paymentLinkData, 'invoice.invoice_pdf_url') ?? data_get($paymentLinkData, 'invoice_pdf_url') ?? $registration->zoho_invoice_pdf_url,
            'zoho_invoice_status' => data_get($paymentLinkData, 'invoice.status') ?? data_get($paymentLinkData, 'invoice_status') ?? $registration->zoho_invoice_status,
            'webhook_payload' => $rawPayload ?? $paymentLinkData,
            'zoho_payment_webhook_payload' => $rawPayload ?? $paymentLinkData,
        ]))->save();
        $registration->refresh();
        Log::info('zoho_payment_id_saved_from_payment_link', ['registration_id' => (string) $registration->id, 'payment_id' => $paymentId]);

        $registration = $this->registrationQr->ensureQrGenerated($registration);

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
                'metadata' => array_merge((array) ($registration->metadata ?? []), [
                    'invoice_balance' => $syncResult['balance'] ?? null,
                    'invoice_amount_paid' => $syncResult['amount_paid'] ?? null,
                    'invoice_payment_applied' => $syncResult['payment_applied'] ?? null,
                ]),
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
