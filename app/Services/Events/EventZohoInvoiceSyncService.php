<?php

namespace App\Services\Events;

use App\Models\EventRegistration;
use App\Models\User;
use App\Support\Zoho\ZohoBillingClient;
use App\Support\Zoho\ZohoBillingService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class EventZohoInvoiceSyncService
{
    public function __construct(
        private readonly ZohoBillingService $zohoBillingService,
        private readonly ZohoBillingClient $zohoBillingClient,
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
                            $beforeInvoiceResponse = $this->zohoBillingClient->request('GET', '/invoices/'.$registration->zoho_invoice_id);
                            $beforeInvoiceData = is_array($beforeInvoiceResponse['invoice'] ?? null) ? $beforeInvoiceResponse['invoice'] : $beforeInvoiceResponse;
                            $beforeStatus = strtolower((string) data_get($beforeInvoiceData, 'status', ''));
                            $beforeBalance = (float) (data_get($beforeInvoiceData, 'balance') ?? data_get($beforeInvoiceData, 'balance_due') ?? 0);

                            if (in_array($beforeStatus, ['paid'], true) || $beforeBalance <= 0.0) {
                                Log::info('zoho_invoice_payment_record_success', [
                                    'event_registration_id' => (string) $registration->id,
                                    'zoho_invoice_id' => $registration->zoho_invoice_id,
                                    'skipped' => true,
                                    'reason' => 'already_paid_or_zero_balance',
                                ]);
                            } else {
                                Log::info('zoho_invoice_payment_record_start', ['event_registration_id' => (string) $registration->id, 'zoho_invoice_id' => $registration->zoho_invoice_id]);
                                $paymentResponse = $this->zohoBillingClient->request('POST', '/customerpayments', [
                                    'customer_id' => $registration->zoho_customer_id,
                                    'payment_mode' => 'UPI',
                                    'amount' => (float) ($registration->payment_amount ?? $registration->amount ?? 0),
                                    'date' => now()->toDateString(),
                                    'reference_number' => (string) ($registration->zoho_payment_link_id ?? $registration->zoho_payment_id ?? $registration->id),
                                    'description' => 'Payment received via Zoho Payment Link for Event Registration',
                                    'invoices' => [[
                                        'invoice_id' => $registration->zoho_invoice_id,
                                        'amount_applied' => (float) ($registration->payment_amount ?? $registration->amount ?? 0),
                                    ]],
                                ]);
                                $registration->forceFill($this->filterRegistrationColumns([
                                    'zoho_payment_id' => data_get($paymentResponse, 'payment.payment_id') ?? data_get($paymentResponse, 'payment_id') ?? $registration->zoho_payment_id,
                                ]))->save();
                                Log::info('zoho_invoice_payment_record_success', ['event_registration_id' => (string) $registration->id, 'zoho_invoice_id' => $registration->zoho_invoice_id]);
                            }
                        } catch (\Throwable $paymentException) {
                            $registration->forceFill($this->filterRegistrationColumns(['zoho_invoice_sync_error' => $paymentException->getMessage()]))->save();
                            Log::error('zoho_invoice_payment_record_failed', ['event_registration_id' => (string) $registration->id, 'error' => $paymentException->getMessage()]);
                        }
                    }

                    $invoiceResponse = $this->zohoBillingClient->request('GET', '/invoices/'.$registration->zoho_invoice_id);
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
                $response = $this->zohoBillingClient->request('POST', $path);
                Log::info('zoho_invoice_mark_sent_attempt_success', ['invoice_id' => $invoiceId, 'path' => $path, 'response_keys' => array_keys($response)]);
                return;
            } catch (\Throwable $e) {
                $lastError = ['path' => $path, 'error' => $e->getMessage()];
                Log::error('zoho_invoice_mark_sent_attempt_failed', ['invoice_id' => $invoiceId, 'path' => $path, 'error' => $e->getMessage()]);
            }
        }

        throw new \RuntimeException('Unable to mark invoice as sent: '.json_encode($lastError));
    }

    private function filterRegistrationColumns(array $data): array
    {
        return array_filter($data, fn ($value, $key) => Schema::hasColumn('event_registrations', $key), ARRAY_FILTER_USE_BOTH);
    }
}
