<?php

namespace App\Services\Events;

use App\Models\EventRegistration;
use App\Models\User;
use App\Support\Zoho\ZohoBillingClient;
use App\Support\Zoho\ZohoBillingService;
use App\Support\Zoho\ZohoBillingTokenService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class EventZohoInvoiceSyncService
{
    public function __construct(
        private readonly ZohoBillingService $zohoBillingService,
        private readonly ZohoBillingClient $zohoBillingClient,
        private readonly ZohoBillingTokenService $zohoBillingTokenService,
    ) {}

    public function sync(EventRegistration $registration): EventRegistration
    {
        $registration->loadMissing(['event', 'occurrence', 'user']);

        try {
            Log::info('zoho_invoice_create_start', [
                'event_registration_id' => (string) $registration->id,
                'existing_invoice_id' => $registration->zoho_invoice_id,
            ]);

            $customerPayload = $this->customerPayload($registration);
            $invoicePayload = $this->invoicePayload($registration);

            $invoice = ! empty($registration->zoho_invoice_id)
                ? $this->updateInvoiceForEventRegistration((string) $registration->zoho_invoice_id, $invoicePayload)
                : $this->zohoBillingService->createInvoiceForEventRegistration($customerPayload, $invoicePayload);
            if (! empty($registration->zoho_invoice_id)) {
                Log::info('zoho_invoice_existing_found', [
                    'event_registration_id' => (string) $registration->id,
                    'zoho_invoice_id' => $registration->zoho_invoice_id,
                ]);
            }

            $registration->forceFill($this->filterRegistrationColumns([
                'zoho_customer_id' => $invoice['customer_id'] ?? $registration->zoho_customer_id,
                'zoho_invoice_id' => $invoice['invoice_id'] ?? $registration->zoho_invoice_id,
                'zoho_invoice_number' => $invoice['invoice_number'] ?? $registration->zoho_invoice_number,
                'zoho_invoice_url' => $invoice['invoice_url'] ?? $registration->zoho_invoice_url,
                'zoho_invoice_pdf_url' => $invoice['invoice_pdf_url'] ?? $registration->zoho_invoice_pdf_url,
                'zoho_invoice_synced_at' => now(),
                'zoho_invoice_sync_error' => null,
            ]))->save();

            Log::info(! empty($registration->getOriginal('zoho_invoice_id')) ? 'zoho_event_invoice_updated_after_payment' : 'zoho_invoice_created', [
                'event_registration_id' => (string) $registration->id,
                'zoho_invoice_id' => $registration->zoho_invoice_id,
            ]);

            try {
                if (! empty($registration->zoho_invoice_id)) {
                    Log::info('zoho_invoice_mark_sent_start', ['event_registration_id' => (string) $registration->id, 'zoho_invoice_id' => $registration->zoho_invoice_id]);
                    $this->markInvoiceAsSent((string) $registration->zoho_invoice_id);
                    Log::info('zoho_invoice_mark_sent_success', [
                        'event_registration_id' => (string) $registration->id,
                        'zoho_invoice_id' => $registration->zoho_invoice_id,
                    ]);

                    if (! empty($registration->zoho_invoice_id)) {
                        try {
                            $beforeInvoiceResponse = $this->booksRequest('GET', '/invoices/'.$registration->zoho_invoice_id);
                            $beforeInvoiceData = is_array($beforeInvoiceResponse['invoice'] ?? null) ? $beforeInvoiceResponse['invoice'] : $beforeInvoiceResponse;
                            $beforeStatus = strtolower((string) data_get($beforeInvoiceData, 'status', ''));
                            $beforeBalance = (float) (data_get($beforeInvoiceData, 'balance') ?? data_get($beforeInvoiceData, 'balance_due') ?? 0);
                            $creditsAvailable = (float) (data_get($beforeInvoiceData, 'credits_applied') ?? data_get($beforeInvoiceData, 'unused_credits_receivable_amount') ?? 0);

                            if (in_array($beforeStatus, ['paid'], true) || $beforeBalance <= 0.0) {
                                Log::info('zoho_invoice_payment_record_success', [
                                    'event_registration_id' => (string) $registration->id,
                                    'zoho_invoice_id' => $registration->zoho_invoice_id,
                                    'skipped' => true,
                                    'reason' => 'already_paid_or_zero_balance',
                                ]);
                            } else {
                                $applied = false;
                                if ($creditsAvailable > 0) {
                                    Log::info('zoho_invoice_apply_credit_start', ['event_registration_id' => (string) $registration->id, 'zoho_invoice_id' => $registration->zoho_invoice_id]);
                                    $creditId = data_get($beforeInvoiceData, 'customer_payments.0.payment_id') ?? data_get($beforeInvoiceData, 'credits.0.creditnote_id');
                                    if (! empty($creditId)) {
                                        $this->booksRequest('POST', '/invoices/'.$registration->zoho_invoice_id.'/credits', [
                                            'credits' => [[
                                                'credit_id' => (string) $creditId,
                                                'amount_applied' => (float) ($registration->payment_amount ?? $registration->amount ?? 0),
                                            ]],
                                        ]);
                                        Log::info('zoho_invoice_apply_credit_success', ['event_registration_id' => (string) $registration->id, 'zoho_invoice_id' => $registration->zoho_invoice_id]);
                                        $applied = true;
                                    }
                                }

                                if (! $applied) {
                                    Log::info('zoho_invoice_customer_payment_start', ['event_registration_id' => (string) $registration->id, 'zoho_invoice_id' => $registration->zoho_invoice_id]);
                                    $paymentResponse = $this->booksRequest('POST', '/customerpayments', [
                                        'customer_id' => $registration->zoho_customer_id,
                                        'payment_mode' => 'upi',
                                        'amount' => (float) ($registration->payment_amount ?? $registration->amount ?? 0),
                                        'date' => now()->toDateString(),
                                        'reference_number' => (string) ($registration->zoho_payment_link_id ?? $registration->zoho_payment_id ?? $registration->id),
                                        'description' => 'Payment received via Zoho Payment Link',
                                        'invoices' => [[
                                            'invoice_id' => $registration->zoho_invoice_id,
                                            'amount_applied' => (float) ($registration->payment_amount ?? $registration->amount ?? 0),
                                        ]],
                                    ]);
                                    $registration->forceFill($this->filterRegistrationColumns([
                                        'zoho_payment_id' => data_get($paymentResponse, 'payment.payment_id') ?? data_get($paymentResponse, 'payment_id') ?? $registration->zoho_payment_id,
                                    ]))->save();
                                    Log::info('zoho_invoice_customer_payment_success', ['event_registration_id' => (string) $registration->id, 'zoho_invoice_id' => $registration->zoho_invoice_id]);
                                }
                                Log::info('zoho_invoice_payment_record_success', ['event_registration_id' => (string) $registration->id, 'zoho_invoice_id' => $registration->zoho_invoice_id]);
                            }
                        } catch (\Throwable $paymentException) {
                            $registration->forceFill($this->filterRegistrationColumns(['zoho_invoice_sync_error' => $paymentException->getMessage()]))->save();
                            Log::error('zoho_invoice_payment_record_failed', ['event_registration_id' => (string) $registration->id, 'error' => $paymentException->getMessage()]);
                        }
                    }

                    $invoiceResponse = $this->booksRequest('GET', '/invoices/'.$registration->zoho_invoice_id);
                    Log::info('zoho_invoice_final_fetch', [
                        'event_registration_id' => (string) $registration->id,
                        'zoho_invoice_id' => $registration->zoho_invoice_id,
                        'response' => $invoiceResponse,
                    ]);
                    $invoiceData = is_array($invoiceResponse['invoice'] ?? null) ? $invoiceResponse['invoice'] : $invoiceResponse;
                    $registration->forceFill($this->filterRegistrationColumns([
                        'zoho_invoice_url' => data_get($invoiceData, 'invoice_url') ?? data_get($invoiceData, 'url') ?? $registration->zoho_invoice_url,
                        'zoho_invoice_pdf_url' => data_get($invoiceData, 'invoice_pdf_url') ?? data_get($invoiceData, 'pdf_url') ?? $registration->zoho_invoice_pdf_url,
                        'zoho_invoice_status' => strtolower((string) (data_get($invoiceData, 'status') ?? $registration->zoho_invoice_status)) === 'paid' ? 'paid' : (data_get($invoiceData, 'status') ?? $registration->zoho_invoice_status),
                    ]))->save();
                    Log::info('zoho_invoice_fetch_success', ['event_registration_id' => (string) $registration->id, 'zoho_invoice_id' => $registration->zoho_invoice_id]);
                    Log::info('zoho_invoice_final_status', ['event_registration_id' => (string) $registration->id, 'zoho_invoice_id' => $registration->zoho_invoice_id, 'status' => $registration->zoho_invoice_status]);
                }
            } catch (\Throwable $fetchException) {
                Log::error('zoho_invoice_fetch_failed', [
                    'event_registration_id' => (string) $registration->id,
                    'error' => $fetchException->getMessage(),
                ]);
            }
        } catch (\Throwable $exception) {
            Log::error('zoho_invoice_create_failed', [
                'event_registration_id' => (string) $registration->id,
                'error' => $exception->getMessage(),
            ]);

            $registration->forceFill($this->filterRegistrationColumns([
                'zoho_invoice_sync_error' => $exception->getMessage(),
            ]))->save();
        }

        return $registration->fresh(['event.circle', 'occurrence', 'user']);
    }

    public function finalizeAndApplyPaymentToEventInvoice(EventRegistration $registration): array
    {
        $registration->refresh();
        $context = [
            'registration_id' => (string) $registration->id,
            'zoho_invoice_id' => $registration->zoho_invoice_id,
            'zoho_invoice_number' => $registration->zoho_invoice_number,
            'zoho_customer_id' => $registration->zoho_customer_id,
            'payment_amount' => (float) ($registration->payment_amount ?? $registration->amount ?? 0),
            'payment_link_id' => $registration->zoho_payment_link_id,
            'payment_id' => $registration->zoho_payment_id,
        ];
        Log::info('zoho_invoice_finalize_start', $context);

        try {
            if (empty($registration->zoho_invoice_id) || empty($registration->zoho_customer_id)) {
                throw new \RuntimeException('Missing Zoho invoice/customer details for sync.');
            }
            $invoiceResponse = $this->booksRequest('GET', '/invoices/'.$registration->zoho_invoice_id);
            $invoice = is_array($invoiceResponse['invoice'] ?? null) ? $invoiceResponse['invoice'] : $invoiceResponse;
            Log::info('zoho_invoice_fetch_before_apply', $context + ['response' => $invoiceResponse]);

            $status = strtolower((string) data_get($invoice, 'status', ''));
            $balance = (float) (data_get($invoice, 'balance') ?? data_get($invoice, 'balance_due') ?? 0);
            if ($status === 'draft') {
                Log::info('zoho_invoice_mark_sent_start', $context);
                try {
                    $this->markInvoiceAsSent((string) $registration->zoho_invoice_id);
                    Log::info('zoho_invoice_mark_sent_success', $context);
                } catch (\Throwable $e) {
                    if (! str_contains(strtolower($e->getMessage()), 'already')) {
                        throw $e;
                    }
                }
            }

            $paymentApplied = false;
            $matchedPaymentId = $registration->zoho_payment_id;
            if ($balance > 0 && ! in_array($status, ['paid', 'closed'], true)) {
                Log::info('zoho_invoice_payment_search_start', $context);
                $paymentsResponse = $this->booksRequest('GET', '/customerpayments', ['customer_id' => $registration->zoho_customer_id]);
                $payments = (array) data_get($paymentsResponse, 'customerpayments', []);
                Log::info('zoho_invoice_payment_search_response', $context + ['count' => count($payments)]);

                $targetAmount = (float) ($registration->payment_amount ?? $registration->amount ?? 0);
                foreach ($payments as $payment) {
                    $unused = (float) (data_get($payment, 'unused_amount') ?? data_get($payment, 'amount') ?? 0);
                    $amount = (float) (data_get($payment, 'amount') ?? 0);
                    $reference = (string) (data_get($payment, 'reference_number') ?? '');
                    if ($unused > 0 && abs($amount - $targetAmount) < 0.01 && ($reference === '' || str_contains($reference, (string) $registration->zoho_payment_link_id) || str_contains($reference, (string) $registration->zoho_payment_id))) {
                        $matchedPaymentId = (string) data_get($payment, 'payment_id');
                        Log::info('zoho_invoice_matching_payment_found', $context + ['payment_id' => $matchedPaymentId]);
                        break;
                    }
                }

                if (! empty($matchedPaymentId)) {
                    Log::info('zoho_invoice_payment_apply_start', $context + ['payment_id' => $matchedPaymentId]);
                    $applyResponse = $this->booksRequest('PUT', '/customerpayments/'.$matchedPaymentId, [
                        'customer_id' => $registration->zoho_customer_id,
                        'amount' => $targetAmount,
                        'invoices' => [[
                            'invoice_id' => $registration->zoho_invoice_id,
                            'amount_applied' => min($targetAmount, $balance),
                        ]],
                    ]);
                    Log::info('zoho_invoice_payment_apply_success', $context + ['payment_id' => $matchedPaymentId, 'response' => $applyResponse]);
                    $paymentApplied = true;
                }
            }

            $finalInvoiceResponse = $this->booksRequest('GET', '/invoices/'.$registration->zoho_invoice_id);
            Log::info('zoho_invoice_final_fetch', $context + ['response' => $finalInvoiceResponse]);
            $finalInvoice = is_array($finalInvoiceResponse['invoice'] ?? null) ? $finalInvoiceResponse['invoice'] : $finalInvoiceResponse;
            Log::info('zoho_invoice_finalize_success', $context + ['payment_id' => $matchedPaymentId]);

            return [
                'invoice_id' => (string) data_get($finalInvoice, 'invoice_id', $registration->zoho_invoice_id),
                'invoice_number' => (string) (data_get($finalInvoice, 'invoice_number') ?? $registration->zoho_invoice_number),
                'status' => (string) data_get($finalInvoice, 'status', ''),
                'balance' => (float) (data_get($finalInvoice, 'balance') ?? data_get($finalInvoice, 'balance_due') ?? 0),
                'total' => (float) (data_get($finalInvoice, 'total') ?? 0),
                'amount_paid' => (float) (data_get($finalInvoice, 'amount_paid') ?? 0),
                'invoice_url' => data_get($finalInvoice, 'invoice_url') ?? data_get($finalInvoice, 'url'),
                'invoice_pdf_url' => data_get($finalInvoice, 'invoice_pdf_url') ?? data_get($finalInvoice, 'pdf_url'),
                'payment_id' => $matchedPaymentId,
                'payment_applied' => $paymentApplied || ((float) (data_get($finalInvoice, 'balance') ?? 0) <= 0),
                'sync_error' => null,
            ];
        } catch (\Throwable $e) {
            Log::error('zoho_invoice_finalize_failed', $context + ['error' => $e->getMessage()]);
            return ['sync_error' => $e->getMessage()];
        }
    }

    private function customerPayload(EventRegistration $registration): array
    {
        if ($registration->user instanceof User) {
            $user = $registration->user;

            return [
                'user' => $user,
                'name' => $user->display_name ?: trim(($user->first_name ?? '').' '.($user->last_name ?? '')),
                'email' => (string) $user->email,
                'phone' => (string) ($user->phone ?? $user->mobile ?? ''),
                'company' => (string) ($user->company_name ?? ''),
                'city' => (string) ($user->city ?? $user->business_city ?? ''),
            ];
        }

        return [
            'name' => (string) $registration->visitor_name,
            'email' => (string) $registration->visitor_email,
            'phone' => (string) $registration->visitor_phone,
            'company' => (string) $registration->visitor_company,
            'city' => (string) $registration->visitor_city,
        ];
    }

    private function invoicePayload(EventRegistration $registration): array
    {
        $attendeeName = $registration->user
            ? ($registration->user->display_name ?: trim(($registration->user->first_name ?? '').' '.($registration->user->last_name ?? '')))
            : (string) $registration->visitor_name;
        $attendeeEmail = $registration->user?->email ?: $registration->visitor_email;
        $eventTitle = (string) ($registration->event?->title ?? 'Unity Event');
        $startAt = $registration->occurrence?->start_at;
        $eventDate = optional($startAt)->format('M d, Y') ?: 'TBD';
        $eventTime = optional($startAt)->format('h:i A') ?: 'TBD';
        $modeOrVenue = $registration->event?->location_text ?: (($registration->event?->mode ?? '') === 'online' ? 'Online' : 'TBD');
        $itemId = (string) config('services.zoho_event_ticket_item_id', '');

        $details = "Event Date: {$eventDate}\n"
            ."Event Time: {$eventTime}\n"
            ."Venue: {$modeOrVenue}\n"
            ."Attendee: {$attendeeName}";

        $shortRegistrationId = substr((string) $registration->id, 0, 8);

        $payload = [
            'registration_id' => (string) $registration->id,
            'event_title' => $eventTitle,
            'description' => $details,
            'amount' => (float) ($registration->payment_amount ?? $registration->amount ?? 0),
            'currency' => (string) ($registration->currency ?? 'INR'),
            'invoice_payload' => [
                'customer_id' => $registration->zoho_customer_id,
                'reference_number' => (string) $registration->id,
                'date' => now()->toDateString(),
                'notes' => 'Payment received via Zoho Payment Link.\nRegistration Ref: '.$shortRegistrationId,
                'terms' => 'Thank you for registering for this event.',
                'line_items' => [[
                    'item_id' => $itemId,
                    'name' => 'Event Registration - '.$eventTitle,
                    'rate' => (float) ($registration->amount ?? 0),
                    'quantity' => 1,
                    'description' => $details,
                ]],
            ],
        ];

        Log::info('zoho_invoice_payload', [
            'event_registration_id' => (string) $registration->id,
            'payload' => $payload['invoice_payload'],
        ]);

        return $payload;
    }

    private function updateInvoiceForEventRegistration(string $invoiceId, array $eventInvoice): array
    {
        $payload = (array) ($eventInvoice['invoice_payload'] ?? []);
        $response = $this->zohoBillingClient->request('PUT', '/invoices/'.$invoiceId, $payload);
        $invoice = is_array($response['invoice'] ?? null) ? $response['invoice'] : $response;

        return [
            'customer_id' => $invoice['customer_id'] ?? data_get($payload, 'customer_id'),
            'invoice_id' => (string) data_get($invoice, 'invoice_id', $invoiceId),
            'invoice_number' => (string) (data_get($invoice, 'invoice_number') ?? data_get($invoice, 'number') ?? ''),
            'invoice_url' => data_get($invoice, 'invoice_url') ?? data_get($invoice, 'url'),
            'invoice_pdf_url' => data_get($invoice, 'invoice_pdf_url') ?? data_get($invoice, 'pdf_url'),
            'raw' => $response,
        ];
    }


    private function markInvoiceAsSent(string $invoiceId): void
    {
        $paths = [
            '/invoices/'.$invoiceId.'/markassent',
            '/invoices/'.$invoiceId.'/submit',
        ];

        $lastError = null;
        foreach ($paths as $path) {
            try {
                Log::info('zoho_invoice_mark_sent_attempt', ['invoice_id' => $invoiceId, 'path' => $path]);
                $response = $this->booksRequest('POST', $path);
                Log::info('zoho_invoice_mark_sent_attempt_success', ['invoice_id' => $invoiceId, 'path' => $path, 'response_keys' => array_keys($response)]);
                return;
            } catch (\Throwable $e) {
                $lastError = ['path' => $path, 'error' => $e->getMessage()];
                Log::error('zoho_invoice_mark_sent_attempt_failed', ['invoice_id' => $invoiceId, 'path' => $path, 'error' => $e->getMessage()]);
            }
        }

        throw new \RuntimeException('Unable to mark invoice as sent: '.json_encode($lastError));
    }

    private function booksRequest(string $method, string $path, array $payload = []): array
    {
        $token = $this->zohoBillingTokenService->getAccessToken();
        $orgId = (string) (config('services.zoho.billing_org_id') ?: config('zoho_billing.org_id') ?: env('ZOHO_BILLING_ORG_ID'));
        $base = 'https://www.zohoapis.in/books/v3';
        $url = rtrim($base, '/').'/'.ltrim($path, '/');

        $request = Http::acceptJson()->asJson()->withHeaders([
            'Authorization' => 'Zoho-oauthtoken '.$token,
            'Content-Type' => 'application/json',
        ]);

        $response = strtoupper($method) === 'GET'
            ? $request->get($url, array_merge(['organization_id' => $orgId], $payload))
            : $request->send(strtoupper($method), $url.'?organization_id='.$orgId, ['json' => $payload]);

        if (! $response->successful()) {
            throw new \RuntimeException('Books request failed: '.$response->status().' '.($response->body() ?: 'unknown'));
        }

        return $response->json() ?? [];
    }

    private function filterRegistrationColumns(array $data): array
    {
        return array_filter($data, fn ($value, $key) => Schema::hasColumn('event_registrations', $key), ARRAY_FILTER_USE_BOTH);
    }
}
