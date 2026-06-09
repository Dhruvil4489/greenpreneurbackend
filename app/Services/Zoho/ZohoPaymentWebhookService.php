<?php

namespace App\Services\Zoho;

use App\Models\CircleSubscription;
use App\Models\EventRegistration;
use App\Models\Payment;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Events\EventPaymentSyncService;
use App\Services\Membership\MembershipUpgradeService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ZohoPaymentWebhookService
{
    private ?string $lastLookupError = null;

    public function __construct(
        private readonly EventPaymentSyncService $paymentSync,
        private readonly MembershipUpgradeService $membershipUpgradeService,
    ) {}

    public function handle(Request $request): array
    {
        $payload = $request->all();
        $normalized = $this->normalizeZohoPaymentWebhookPayload($payload);
        $normalized['external_event_id'] = $normalized['external_event_id'] ?: $request->header('X-Zoho-Webhook-Id');
        $event = null;

        Log::info('zoho_webhook_received', $this->context(null, $normalized) + [
            'request_url' => $request->fullUrl(),
        ]);
        Log::info('zoho_payment_webhook_received_raw', $this->context(null, $normalized));
        Log::info('zoho_webhook_payment_normalized', $this->context(null, $normalized) + ['normalized' => $normalized]);

        try {
            $event = $this->storeEvent($request, $payload, $normalized);

            if (($event->status ?? null) === 'processed') {
                Log::info('zoho_payment_webhook_duplicate_ignored', $this->context($event, $normalized) + ['duplicate_status' => $event->status]);
                return ['message' => 'Webhook already processed.', 'normalized' => $normalized, 'webhook_event_id' => $event->id];
            }

            if (($event->status ?? null) === 'processing' && $event->updated_at && $event->updated_at->greaterThan(now()->subMinutes(5))) {
                Log::info('zoho_payment_webhook_duplicate_ignored', $this->context($event, $normalized) + ['duplicate_status' => $event->status]);
                return ['message' => 'Webhook is already processing.', 'normalized' => $normalized, 'webhook_event_id' => $event->id];
            }

            if (in_array((string) $event->status, ['ignored', 'failed'], true)) {
                Log::info('zoho_payment_webhook_duplicate_reprocessing', $this->context($event, $normalized) + ['previous_status' => $event->status, 'previous_error' => $event->error]);
            }

            return $this->processEvent($event, $request, $payload, $normalized);
        } catch (\Throwable $e) {
            if ($event instanceof WebhookEvent) {
                $event->forceFill(['status' => 'failed', 'error' => $e->getMessage()])->save();
            } else {
                $event = $this->storeFailedEventSafely($request, $payload, $normalized, $e);
            }

            Log::error('zoho_payment_webhook_sync_failed', $this->context($event, $normalized) + [
                'exception_message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            Log::error('zoho_payment_webhook_unhandled_exception', $this->context($event, $normalized) + [
                'exception_message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return ['message' => 'Webhook received but processing failed. It can be retried.', 'normalized' => $normalized, 'webhook_event_id' => $event?->id, 'error' => $e->getMessage()];
        }
    }

    private function processEvent(WebhookEvent $event, Request $request, array $payload, array $normalized): array
    {
        $event->forceFill([
            'event_type' => $event->event_type ?: $normalized['event_type'],
            'external_event_id' => $event->external_event_id ?: ($normalized['external_event_id'] ?? null),
            'payment_link_id' => $event->payment_link_id ?: ($normalized['payment_link_id'] ?? null),
            'payment_id' => $event->payment_id ?: ($normalized['payment_id'] ?? null),
            'payload' => $payload,
            'headers' => $this->safeHeaders($request),
            'status' => 'processing',
            'processed_at' => null,
            'error' => null,
        ])->save();

        Log::info('zoho_payment_webhook_lookup_started', $this->context($event, $normalized));

        if ($this->isSubscriptionPaymentWebhook($normalized) && ! $this->hasStrongEventRegistrationHint($normalized)) {
            Log::info('zoho_webhook_detected_subscription_payment', $this->context($event, $normalized));
            return $this->processSubscriptionPaymentEvent($event, $payload, $normalized);
        }

        $registration = $this->findRegistration($payload, $normalized, $event);
        if (! $registration) {
            if ($this->isSubscriptionPaymentWebhook($normalized)) {
                Log::info('zoho_webhook_detected_subscription_payment', $this->context($event, $normalized));
                return $this->processSubscriptionPaymentEvent($event, $payload, $normalized);
            }

            $lookupError = $this->lastLookupError ?: 'Registration not found for webhook.';
            $event->forceFill(['status' => 'ignored', 'processed_at' => now(), 'error' => $lookupError])->save();
            Log::warning('zoho_payment_webhook_registration_not_found', $this->context($event, $normalized));
            Log::warning('zoho_payment_webhook_ignored_registration_not_found', $this->context($event, $normalized));
            return ['message' => 'Webhook received but registration not found.', 'normalized' => $normalized, 'webhook_event_id' => $event->id, 'registration_found' => false, 'error' => $lookupError];
        }

        $event->forceFill([
            'registration_id' => $registration->id,
            'payment_link_id' => $event->payment_link_id ?: ($normalized['payment_link_id'] ?: ($normalized['parsed_payment_link_id'] ?? $registration->zoho_payment_link_id)),
            'payment_id' => $normalized['payment_id'] ?: $event->payment_id,
            'status' => 'processing',
        ])->save();
        $normalized['registration_id'] = (string) $registration->id;
        Log::info('zoho_payment_webhook_registration_found', $this->context($event, $normalized));
        Log::info('zoho_payment_webhook_registration_found_final', $this->context($event, $normalized));

        $status = strtolower((string) ($normalized['status'] ?? ''));
        $type = strtolower((string) ($normalized['event_type'] ?? ''));
        if (str_contains($type, 'cancel') || str_contains($type, 'expired') || in_array($status, ['cancelled', 'canceled', 'expired'], true)) {
            $this->markCancelledOrExpired($registration, $payload, str_contains($type.$status, 'expired') ? 'expired' : 'cancelled');
            $event->forceFill(['status' => 'processed', 'processed_at' => now(), 'error' => null])->save();
            Log::info('zoho_payment_webhook_cancelled_or_expired', $this->context($event, $normalized));
            Log::info('zoho_payment_webhook_processed', $this->context($event, $normalized));
            return ['message' => 'Webhook received.', 'normalized' => $normalized, 'webhook_event_id' => $event->id, 'registration_found' => true];
        }

        if ($this->isAlreadyFullySynced($registration)) {
            $event->forceFill(['status' => 'processed', 'processed_at' => now(), 'error' => null])->save();
            Log::info('zoho_payment_webhook_processed', $this->context($event, $normalized));
            return ['message' => 'Webhook already processed.', 'normalized' => $normalized, 'webhook_event_id' => $event->id, 'registration_found' => true, 'registration_id' => (string) $registration->id];
        }

        if ($this->isPaidWebhook($normalized)) {
            Log::info('zoho_payment_webhook_sync_started', $this->context($event, $normalized));
            $this->primePaidFields($registration, $payload, $normalized);
            $this->paymentSync->syncRegistrationPayment($registration->fresh(['event', 'occurrence', 'user']), [
                'source' => 'zoho_webhook',
                'webhook_event_id' => $event->id,
                'payload' => $payload,
                'payment_id' => $normalized['payment_id'] ?? null,
            ]);
            $event->forceFill(['status' => 'processed', 'processed_at' => now(), 'error' => null])->save();
            Log::info('zoho_payment_webhook_sync_success', $this->context($event, $normalized));
            Log::info('zoho_payment_webhook_processed', $this->context($event, $normalized));
        } else {
            $event->forceFill(['status' => 'ignored', 'processed_at' => now(), 'error' => 'Unsupported webhook event/status.'])->save();
        }

        return ['message' => 'Webhook received.', 'normalized' => $normalized, 'webhook_event_id' => $event->id, 'registration_found' => true, 'registration_id' => $normalized['registration_id'] ?? null];
    }

    public function verify(Request $request): bool
    {
        $secret = (string) env('ZOHO_PAYMENT_WEBHOOK_SECRET', '');
        $verifySignature = filter_var(env('ZOHO_PAYMENT_WEBHOOK_VERIFY_SIGNATURE', false), FILTER_VALIDATE_BOOL);
        if ($verifySignature) {
            $signature = $request->header('X-Zoho-Webhook-Signature') ?: $request->header('X-Zoho-Signature') ?: $request->header('X-Zoho-Payments-Signature');
            return $secret !== '' && $signature !== '' && hash_equals(hash_hmac('sha256', $request->getContent(), $secret), (string) $signature);
        }
        if ($secret === '') {
            if (app()->environment('local')) {
                Log::warning('Zoho payment webhook secret is empty; allowing local webhook request.');
                return true;
            }
            return false;
        }
        return hash_equals($secret, (string) $request->query('secret', '')) || hash_equals($secret, (string) $request->header('X-Webhook-Secret', ''));
    }

    public function processStored(WebhookEvent $event): void
    {
        $payload = (array) ($event->payload ?? []);
        $normalized = $this->normalizeZohoPaymentWebhookPayload($payload);
        $normalized['external_event_id'] = $normalized['external_event_id'] ?: $event->external_event_id;
        $fake = Request::create('/internal', 'POST', [], [], [], [], json_encode($payload));
        $fake->headers->set('Content-Type', 'application/json');

        try {
            $this->processEvent($event, $fake, $payload, $normalized);
        } catch (\Throwable $e) {
            $event->forceFill(['status' => 'failed', 'error' => $e->getMessage()])->save();
            Log::error('zoho_payment_webhook_sync_failed', $this->context($event, $normalized) + [
                'exception_message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }

    public function extract(array $payload): array
    {
        return $this->normalizeZohoPaymentWebhookPayload($payload);
    }

    public function normalizeZohoPaymentWebhookPayload(array $payload): array
    {
        $payment = (array) data_get($payload, 'payment', []);
        $dataPayment = (array) data_get($payload, 'data.payment', []);
        $invoice = (array) (data_get($payload, 'payment.invoices.0') ?? data_get($payload, 'data.payment.invoices.0') ?? []);
        $description = $payment['description'] ?? data_get($payload, 'description') ?? data_get($payload, 'data.description') ?? ($dataPayment['description'] ?? null);
        $parsed = $this->parseDescriptionIdentifiers((string) $description);
        $paymentLinkId = array_key_exists('payment_link_id', $payment)
            ? (string) $payment['payment_link_id']
            : (string) (data_get($payload, 'payment_link.payment_link_id') ?? data_get($payload, 'payment_link_id') ?? data_get($payload, 'data.payment_link_id') ?? data_get($payload, 'data.payment_link.payment_link_id') ?? data_get($payload, 'payment_link.id') ?? '');
        $subscriptionIds = data_get($invoice, 'subscription_ids', []);
        $subscriptionId = is_array($subscriptionIds) ? ($subscriptionIds[0] ?? null) : $subscriptionIds;

        return [
            'event_type' => data_get($payload, 'event') ?? data_get($payload, 'event_type') ?? data_get($payload, 'type') ?? data_get($payload, 'event_name') ?? 'customer_payment',
            'external_event_id' => data_get($payload, 'event_id') ?? data_get($payload, 'id') ?? data_get($payload, 'webhook_id'),
            'payment_id' => $payment['payment_id'] ?? data_get($payload, 'payment_id') ?? data_get($payload, 'data.payment_id') ?? ($dataPayment['payment_id'] ?? null) ?? data_get($payload, 'payment.id') ?? data_get($payload, 'customer_payments.0.payment_id') ?? data_get($payload, 'payment_link.customer_payments.0.payment_id'),
            'payment_status' => $payment['payment_status'] ?? ($dataPayment['payment_status'] ?? null),
            'status' => $payment['status'] ?? $payment['payment_status'] ?? data_get($payload, 'status') ?? data_get($payload, 'payment_link.status') ?? data_get($payload, 'data.status') ?? ($dataPayment['status'] ?? null) ?? ($dataPayment['payment_status'] ?? null),
            'payment_link_id' => $paymentLinkId,
            'reference_number' => $payment['reference_number'] ?? data_get($payload, 'reference_number') ?? data_get($payload, 'data.reference_number') ?? ($dataPayment['reference_number'] ?? null),
            'online_transaction_id' => $payment['online_transaction_id'] ?? data_get($payload, 'online_transaction_id') ?? data_get($payload, 'data.online_transaction_id') ?? ($dataPayment['online_transaction_id'] ?? null),
            'description' => $description,
            'customer_id' => $payment['customer_id'] ?? data_get($payload, 'customer_id') ?? data_get($payload, 'data.customer_id') ?? ($dataPayment['customer_id'] ?? null),
            'customer_email' => $payment['email'] ?? data_get($payload, 'email') ?? data_get($payload, 'data.email') ?? ($dataPayment['email'] ?? null),
            'customer_name' => $payment['customer_name'] ?? data_get($payload, 'customer_name') ?? data_get($payload, 'data.customer_name') ?? ($dataPayment['customer_name'] ?? null),
            'amount' => $payment['amount'] ?? data_get($payload, 'amount') ?? data_get($payload, 'data.amount') ?? ($dataPayment['amount'] ?? null),
            'payment_date' => $payment['date'] ?? $payment['payment_date'] ?? data_get($payload, 'payment_date') ?? data_get($payload, 'date') ?? data_get($payload, 'data.date') ?? ($dataPayment['date'] ?? null),
            'invoice_id' => data_get($invoice, 'invoice_id'),
            'invoice_number' => data_get($invoice, 'invoice_number'),
            'hosted_page_id' => data_get($invoice, 'hosted_page_id') ?? data_get($invoice, 'hostedpage_id'),
            'subscription_id' => $subscriptionId,
            'balance_amount' => data_get($invoice, 'balance_amount'),
            'amount_applied' => data_get($invoice, 'amount_applied'),
            'transaction_type' => data_get($invoice, 'transaction_type'),
            'payment_id' => $payment['payment_id'] ?? data_get($payload, 'payment_id') ?? data_get($payload, 'data.payment_id') ?? ($dataPayment['payment_id'] ?? null) ?? data_get($payload, 'payment.id') ?? data_get($payload, 'customerpayment.payment_id') ?? data_get($payload, 'data.customerpayment.payment_id') ?? data_get($payload, 'customer_payments.0.payment_id') ?? data_get($payload, 'payment_link.customer_payments.0.payment_id') ?? data_get($payload, 'transaction_id'),
            'payment_link_id' => $this->blankToNull($payment['payment_link_id'] ?? data_get($payload, 'payment_link.payment_link_id') ?? data_get($payload, 'payment_link_id') ?? data_get($payload, 'data.payment_link_id') ?? data_get($payload, 'data.payment_link.payment_link_id') ?? data_get($payload, 'payment_link.id')),
            'payment_session_id' => $this->blankToNull(data_get($payload, 'payment_session_id') ?? data_get($payload, 'data.payment_session_id') ?? data_get($payload, 'payment.session_id') ?? data_get($payload, 'data.payment.session_id')),
            'hosted_page_id' => $this->blankToNull(data_get($payload, 'hostedpage.hostedpage_id') ?? data_get($payload, 'data.hostedpage.hostedpage_id') ?? data_get($payload, 'hosted_page_id') ?? data_get($payload, 'hostedpage_id') ?? data_get($payload, 'payment.hostedpage_id') ?? data_get($payload, 'data.payment.hostedpage_id')),
            'reference_number' => $payment['reference_number'] ?? data_get($payload, 'reference_number') ?? data_get($payload, 'data.reference_number') ?? data_get($payload, 'invoice.reference_number') ?? data_get($payload, 'data.invoice.reference_number') ?? ($dataPayment['reference_number'] ?? null),
            'online_transaction_id' => $payment['online_transaction_id'] ?? data_get($payload, 'online_transaction_id') ?? data_get($payload, 'data.online_transaction_id') ?? ($dataPayment['online_transaction_id'] ?? null),
            'description' => $description,
            'customer_id' => $payment['customer_id'] ?? data_get($payload, 'customer.customer_id') ?? data_get($payload, 'data.customer.customer_id') ?? data_get($payload, 'customer_id') ?? data_get($payload, 'data.customer_id') ?? ($dataPayment['customer_id'] ?? null),
            'amount' => $payment['amount'] ?? data_get($payload, 'customerpayment.amount') ?? data_get($payload, 'data.customerpayment.amount') ?? data_get($payload, 'amount') ?? data_get($payload, 'data.amount') ?? ($dataPayment['amount'] ?? null),
            'currency' => data_get($payload, 'currency') ?? data_get($payload, 'currency_code') ?? data_get($payload, 'customerpayment.currency_code') ?? data_get($payload, 'data.customerpayment.currency_code') ?? data_get($payload, 'payment.currency_code') ?? data_get($payload, 'data.payment.currency_code'),
            'payment_date' => $payment['date'] ?? $payment['payment_date'] ?? data_get($payload, 'customerpayment.date') ?? data_get($payload, 'data.customerpayment.date') ?? data_get($payload, 'payment_date') ?? data_get($payload, 'date') ?? data_get($payload, 'data.date') ?? ($dataPayment['date'] ?? null),
            'status' => $payment['payment_status'] ?? $payment['status'] ?? data_get($payload, 'customerpayment.status') ?? data_get($payload, 'customerpayment.payment_status') ?? data_get($payload, 'status') ?? data_get($payload, 'payment_link.status') ?? data_get($payload, 'invoice.status') ?? data_get($payload, 'data.status') ?? ($dataPayment['payment_status'] ?? null) ?? ($dataPayment['status'] ?? null),
            'url' => data_get($payload, 'payment_link.url') ?? data_get($payload, 'hostedpage.url') ?? data_get($payload, 'data.hostedpage.url') ?? data_get($payload, 'url') ?? data_get($payload, 'data.url'),
            'registration_id' => data_get($payload, 'metadata.registration_id') ?? data_get($payload, 'data.metadata.registration_id') ?? data_get($payload, 'custom_fields.registration_id') ?? data_get($payload, 'data.custom_fields.registration_id') ?? data_get($payload, 'registration_id'),
            'invoice_id' => data_get($payload, 'invoice.invoice_id') ?? data_get($payload, 'data.invoice.invoice_id') ?? data_get($payload, 'invoice_id'),
            'invoice_number' => data_get($payload, 'invoice.invoice_number') ?? data_get($payload, 'data.invoice.invoice_number') ?? data_get($payload, 'invoice_number'),
            'invoice_url' => data_get($payload, 'invoice.invoice_url') ?? data_get($payload, 'data.invoice.invoice_url') ?? data_get($payload, 'invoice_url'),
            'invoice_pdf_url' => data_get($payload, 'invoice.invoice_pdf_url') ?? data_get($payload, 'data.invoice.invoice_pdf_url') ?? data_get($payload, 'invoice_pdf_url'),
            'invoice_status' => data_get($payload, 'invoice.status') ?? data_get($payload, 'data.invoice.status') ?? data_get($payload, 'invoice_status'),
            'parsed_registration_id' => $parsed['registration_id'] ?? null,
            'parsed_payment_link_id' => $parsed['payment_link_id'] ?? null,
            'parsed_original_payment_id' => $parsed['original_payment_id'] ?? null,
        ];
    }

    private function storeEvent(Request $request, array $payload, array $info): WebhookEvent
    {
        $query = WebhookEvent::query()->where('provider', 'zoho');
        if (! empty($info['external_event_id'])) {
            $query->where('external_event_id', $info['external_event_id']);
        } elseif (! empty($info['payment_link_id']) || ! empty($info['payment_id'])) {
            $query->where('event_type', $info['event_type'])->where('payment_link_id', $info['payment_link_id'])->where('payment_id', $info['payment_id']);
        } else {
            $query->whereRaw('1 = 0');
        }
        $existing = $query->first();
        if ($existing) return $existing;

        $event = WebhookEvent::query()->create([
            'provider' => 'zoho',
            'event_type' => $info['event_type'],
            'external_event_id' => $info['external_event_id'],
            'payment_link_id' => $info['payment_link_id'],
            'payment_id' => $info['payment_id'],
            'status' => 'received',
            'payload' => $payload,
            'headers' => $this->safeHeaders($request),
        ]);
        Log::info('zoho_payment_webhook_event_stored', $this->context($event, $info));
        return $event;
    }

    private function findRegistration(array $payload, array $info, ?WebhookEvent $event = null): ?EventRegistration
    {
        $this->lastLookupError = null;
        Log::info('zoho_payment_webhook_registration_lookup_start', $this->context($event, $info));

        if (! empty($info['parsed_registration_id'])) {
            Log::info('zoho_payment_webhook_parsed_registration_id', $this->context($event, $info) + ['parsed_registration_id' => $info['parsed_registration_id']]);
            Log::info('zoho_payment_webhook_lookup_by_registration_id_start', $this->context($event, $info) + ['parsed_registration_id' => $info['parsed_registration_id']]);
            $registration = EventRegistration::query()->where('id', $info['parsed_registration_id'])->first();
            if ($registration) {
                Log::info('zoho_payment_webhook_lookup_by_registration_id_found', $this->context($event, $info) + ['registration_id' => (string) $registration->id]);
                return $registration;
            }
        }

        if (! empty($info['registration_id']) && Str::isUuid((string) $info['registration_id'])) {
            Log::info('zoho_payment_webhook_lookup_by_payload_registration_id_start', $this->context($event, $info));
            $registration = EventRegistration::query()->where('id', $info['registration_id'])->first();
            if ($registration) {
                Log::info('zoho_payment_webhook_lookup_by_registration_id_found', $this->context($event, $info) + ['registration_id' => (string) $registration->id]);
                return $registration;
            }
        }

        foreach ([
            'zoho_payment_session_id' => $info['payment_session_id'] ?? null,
            'zoho_hosted_page_id' => $info['hosted_page_id'] ?? null,
            'zoho_payment_id' => $info['online_transaction_id'] ?? null,
            'zoho_invoice_id' => $info['invoice_id'] ?? null,
        ] as $column => $value) {
            if (! empty($value) && Schema::hasColumn('event_registrations', $column)) {
                Log::info('zoho_payment_webhook_lookup_by_identifier_start', $this->context($event, $info) + ['column' => $column, 'value' => $value]);
                $registration = EventRegistration::query()->where($column, $value)->latest('created_at')->first();
                if ($registration) {
                    Log::info('zoho_payment_webhook_registration_found_final', $this->context($event, $info) + ['registration_id' => (string) $registration->id, 'matched_column' => $column]);
                    return $registration;
                }
            }
        }

        if (! empty($info['payment_link_id'])) {
            Log::info('zoho_payment_webhook_lookup_by_payment_link_id_start', $this->context($event, $info));
            $registration = EventRegistration::query()->where('zoho_payment_link_id', $info['payment_link_id'])->latest('created_at')->first();
            if ($registration) {
                Log::info('zoho_payment_webhook_lookup_by_payment_link_id_found', $this->context($event, $info) + ['registration_id' => (string) $registration->id]);
                return $registration;
            }
        }

        if (! empty($info['parsed_payment_link_id'])) {
            Log::info('zoho_payment_webhook_lookup_by_parsed_payment_link_id_start', $this->context($event, $info));
            $registration = EventRegistration::query()->where('zoho_payment_link_id', $info['parsed_payment_link_id'])->latest('created_at')->first();
            if ($registration) {
                Log::info('zoho_payment_webhook_lookup_by_payment_link_id_found', $this->context($event, $info) + ['registration_id' => (string) $registration->id]);
                return $registration;
            }
        }

        if (! empty($info['parsed_original_payment_id'])) {
            Log::info('zoho_payment_webhook_lookup_by_original_payment_id_start', $this->context($event, $info));
            $registration = EventRegistration::query()->where('zoho_payment_id', $info['parsed_original_payment_id'])->latest('created_at')->first();
            if ($registration) {
                Log::info('zoho_payment_webhook_registration_found_final', $this->context($event, $info) + ['registration_id' => (string) $registration->id]);
                return $registration;
            }
        }

        if (! empty($info['payment_id'])) {
            Log::info('zoho_payment_webhook_lookup_by_webhook_payment_id_start', $this->context($event, $info));
            $registration = EventRegistration::query()->where('zoho_payment_id', $info['payment_id'])->latest('created_at')->first();
            if ($registration) {
                Log::info('zoho_payment_webhook_registration_found_final', $this->context($event, $info) + ['registration_id' => (string) $registration->id]);
                return $registration;
            }
        }

        if (! empty($info['reference_number'])) {
            Log::info('zoho_payment_webhook_lookup_by_reference_number_start', $this->context($event, $info));
            $registration = EventRegistration::query()
                ->where('razorpay_payment_id', $info['reference_number'])
                ->orWhere('zoho_payment_id', $info['reference_number'])
                ->latest('created_at')
                ->first();
            if ($registration) {
                Log::info('zoho_payment_webhook_registration_found_final', $this->context($event, $info) + ['registration_id' => (string) $registration->id]);
                return $registration;
            }
        }

        $url = $info['url'] ?? data_get($payload, 'payment_link.url') ?? data_get($payload, 'url') ?? data_get($payload, 'data.url');
        if ($url) {
            $registration = EventRegistration::query()
                ->where('zoho_payment_link_url', $url)
                ->orWhere('payment_url', $url)
                ->orWhere('zoho_checkout_url', $url)
                ->orWhere('zoho_hosted_page_url', $url)
                ->latest('created_at')
                ->first();
            if ($registration) return $registration;
        }

        foreach ([data_get($payload, 'registration_id'), data_get($payload, 'reference_number'), data_get($payload, 'payment.reference_number'), data_get($payload, 'data.reference_number')] as $id) {
            if ($id && Str::isUuid((string) $id)) {
                $registration = EventRegistration::query()->find($id);
                if ($registration) return $registration;
            }
        }

        if (! empty($info['customer_id']) && $info['amount'] !== null) {
            $amount = (float) $info['amount'];
            Log::info('zoho_payment_webhook_lookup_by_customer_amount_start', $this->context($event, $info) + ['amount' => $amount]);
            $candidates = $this->customerRegistrationBaseQuery($info['customer_id'], 7)
                ->where(function ($query) use ($amount): void {
                    $query->whereRaw('CAST(amount AS NUMERIC) BETWEEN ? AND ?', [$amount - 0.01, $amount + 0.01]);
                    if (Schema::hasColumn('event_registrations', 'payment_amount')) {
                        $query->orWhereRaw('CAST(payment_amount AS NUMERIC) BETWEEN ? AND ?', [$amount - 0.01, $amount + 0.01]);
                    }
                })
                ->latest('created_at')
                ->limit(2)
                ->get();
            if ($candidates->count() === 1) {
                $registration = $candidates->first();
                Log::info('zoho_payment_webhook_lookup_by_customer_amount_found', $this->context($event, $info) + ['registration_id' => (string) $registration->id, 'amount' => $amount]);
                return $registration;
            }
            if ($candidates->count() > 1) {
                $this->lastLookupError = 'Multiple matching registrations found for customer/amount fallback.';
                Log::warning('zoho_payment_webhook_lookup_multiple_candidates', $this->context($event, $info) + ['candidate_count' => $candidates->count(), 'amount' => $amount]);
                return null;
            }
            Log::warning('zoho_payment_webhook_lookup_by_customer_amount_failed', $this->context($event, $info) + ['amount' => $amount]);
        }

        if (! empty($info['customer_id'])) {
            $candidates = $this->customerRegistrationBaseQuery($info['customer_id'], 1)->latest('created_at')->limit(2)->get();
            if ($candidates->count() === 1) {
                $registration = $candidates->first();
                Log::info('zoho_payment_webhook_lookup_by_customer_only_found', $this->context($event, $info) + ['registration_id' => (string) $registration->id]);
                return $registration;
            }
            if ($candidates->count() > 1) {
                $this->lastLookupError = 'Multiple possible registrations found for payment webhook customer fallback.';
                Log::warning('zoho_payment_webhook_lookup_multiple_candidates', $this->context($event, $info) + ['candidate_count' => $candidates->count()]);
                return null;
            }
        }

        if (! empty($info['customer_email']) && $info['amount'] !== null && Schema::hasColumn('event_registrations', 'visitor_email')) {
            $amount = (float) $info['amount'];
            Log::info('zoho_payment_webhook_lookup_by_email_amount_start', $this->context($event, $info) + ['amount' => $amount]);
            $candidates = EventRegistration::query()
                ->where('visitor_email', (string) $info['customer_email'])
                ->whereIn('payment_status', ['pending', 'processing'])
                ->where('created_at', '>=', now()->subDays(7))
                ->where(function ($query) use ($amount): void {
                    if (Schema::hasColumn('event_registrations', 'amount')) {
                        $query->orWhereRaw('CAST(amount AS NUMERIC) BETWEEN ? AND ?', [$amount - 0.01, $amount + 0.01]);
                    }
                    if (Schema::hasColumn('event_registrations', 'payment_amount')) {
                        $query->orWhereRaw('CAST(payment_amount AS NUMERIC) BETWEEN ? AND ?', [$amount - 0.01, $amount + 0.01]);
                    }
                })
                ->latest('created_at')
                ->limit(2)
                ->get();

            if ($candidates->count() === 1) {
                $registration = $candidates->first();
                Log::info('zoho_payment_webhook_lookup_by_email_amount_found', $this->context($event, $info) + ['registration_id' => (string) $registration->id, 'amount' => $amount]);
                return $registration;
            }

            if ($candidates->count() > 1) {
                $this->lastLookupError = 'Multiple matching registrations found for email/amount fallback.';
                Log::warning('zoho_payment_webhook_lookup_multiple_candidates', $this->context($event, $info) + ['candidate_count' => $candidates->count(), 'amount' => $amount]);
                return null;
            }
        }

        $this->lastLookupError = 'Registration not found for webhook.';
        return null;
    }

    private function customerRegistrationBaseQuery(string $customerId, int $days)
    {
        return EventRegistration::query()
            ->where('zoho_customer_id', $customerId)
            ->where('payment_required', true)
            ->whereIn('payment_status', ['pending', 'processing', 'paid'])
            ->where('created_at', '>=', now()->subDays($days));
    }

    private function primePaidFields(EventRegistration $registration, array $payload, array $info): void
    {
        $registration->forceFill($this->filter([
            'zoho_payment_id' => $registration->zoho_payment_id ?: ($info['payment_id'] ?? ($info['parsed_original_payment_id'] ?? null)),
            'zoho_payment_link_id' => $registration->zoho_payment_link_id ?: ($info['parsed_payment_link_id'] ?? null),
            'status' => 'registered',
            'zoho_payment_id' => $registration->zoho_payment_id ?: ($info['parsed_original_payment_id'] ?? $info['payment_id'] ?? $info['online_transaction_id'] ?? null),
            'zoho_payment_status' => 'paid',
            'payment_status' => 'paid',
            'zoho_invoice_id' => $info['invoice_id'] ?? $registration->zoho_invoice_id,
            'zoho_invoice_number' => $info['invoice_number'] ?? $registration->zoho_invoice_number,
            'zoho_invoice_status' => 'paid',
            'zoho_invoice_sync_error' => null,
            'status' => 'registered',
            'payment_completed_at' => $registration->payment_completed_at ?: ($info['payment_date'] ? now()->parse((string) $info['payment_date']) : now()),
            'zoho_invoice_id' => $info['invoice_id'] ?? $registration->zoho_invoice_id,
            'zoho_invoice_number' => $info['invoice_number'] ?? $registration->zoho_invoice_number,
            'zoho_invoice_url' => $info['invoice_url'] ?? $registration->zoho_invoice_url,
            'zoho_invoice_pdf_url' => $info['invoice_pdf_url'] ?? $registration->zoho_invoice_pdf_url,
            'zoho_invoice_status' => $info['invoice_status'] ?? $registration->zoho_invoice_status,
            'amount' => $info['amount'] ?? $registration->amount,
            'payment_amount' => $info['amount'] ?? $registration->payment_amount,
            'currency' => $info['currency'] ?? $registration->currency,
            'payment_currency' => $info['currency'] ?? $registration->payment_currency,
            'zoho_payment_webhook_payload' => $payload,
            'webhook_payload' => $payload,
            'metadata' => array_merge((array) ($registration->metadata ?? []), [
                'zoho_webhook_payment_id' => $info['payment_id'] ?? null,
                'zoho_webhook_reference_number' => $info['reference_number'] ?? null,
                'zoho_webhook_original_payment_id' => $info['parsed_original_payment_id'] ?? null,
                'zoho_webhook_payment_link_id' => $info['parsed_payment_link_id'] ?? null,
            ]),
        ]))->save();

        $registration->refresh();
        if (empty($registration->qr_code_url) && empty($registration->qr_code_path) && empty($registration->qr_token)) {
            app(\App\Services\Events\EventQrService::class)->generateAndStore($registration);
            Log::info('zoho_payment_webhook_qr_generated', $this->context(null, $info) + ['registration_id' => (string) $registration->id]);
        }
    }

    private function markCancelledOrExpired(EventRegistration $registration, array $payload, string $status): void
    {
        if (($registration->payment_status ?? null) === 'paid') return;
        $registration->forceFill($this->filter([
            'zoho_payment_status' => $status,
            'payment_status' => $status === 'expired' ? 'expired' : 'failed',
            'payment_failed_reason' => 'Zoho payment link '.$status,
            'zoho_payment_webhook_payload' => $payload,
            'webhook_payload' => $payload,
        ]))->save();
    }

    private function isSubscriptionPaymentWebhook(array $info): bool
    {
        return filled($info['subscription_id'] ?? null) || filled($info['hosted_page_id'] ?? null);
    }

    private function hasStrongEventRegistrationHint(array $info): bool
    {
        return filled($info['parsed_registration_id'] ?? null)
            || filled($info['payment_link_id'] ?? null)
            || filled($info['parsed_payment_link_id'] ?? null);
    }

    private function processSubscriptionPaymentEvent(WebhookEvent $event, array $payload, array $info): array
    {
        Log::info('zoho_subscription_payment_lookup_start', $this->context($event, $info));

        $lookup = $this->findSubscriptionPaymentTarget($info);
        $record = $lookup['record'];
        $user = $lookup['user'];

        if (! $user) {
            $error = 'Subscription/member payment user not found for Zoho webhook.';
            $event->forceFill(['status' => 'ignored', 'processed_at' => now(), 'error' => $error])->save();
            Log::warning('zoho_subscription_payment_record_not_found', $this->context($event, $info));

            return [
                'message' => 'Webhook received but subscription/member payment record was not found.',
                'normalized' => $info,
                'webhook_event_id' => $event->id,
                'subscription_payment_found' => false,
                'error' => $error,
            ];
        }

        Log::info('zoho_subscription_payment_user_found', $this->context($event, $info) + ['user_id' => $user->id]);
        Log::info('zoho_subscription_payment_apply_start', $this->context($event, $info) + ['user_id' => $user->id, 'record_type' => $record ? $record::class : null, 'record_id' => $record?->getKey()]);

        if (! $this->isPaidSubscriptionPayment($info)) {
            $error = 'Subscription/member payment webhook is not paid/successful.';
            $event->forceFill(['status' => 'ignored', 'processed_at' => now(), 'error' => $error])->save();
            Log::warning('zoho_subscription_payment_apply_failed', $this->context($event, $info) + ['user_id' => $user->id, 'error' => $error]);

            return ['message' => 'Webhook received but subscription/member payment was not paid.', 'normalized' => $info, 'webhook_event_id' => $event->id, 'error' => $error];
        }

        try {
            $this->applySubscriptionPayment($record, $user, $payload, $info);
            $event->forceFill(['status' => 'processed', 'processed_at' => now(), 'error' => null])->save();

            Log::info('zoho_subscription_payment_apply_success', $this->context($event, $info) + ['user_id' => $user->id, 'record_type' => $record ? $record::class : null, 'record_id' => $record?->getKey()]);

            return [
                'message' => 'Subscription/member payment webhook processed.',
                'normalized' => $info,
                'webhook_event_id' => $event->id,
                'subscription_payment_found' => true,
                'user_id' => $user->id,
            ];
        } catch (\Throwable $throwable) {
            $event->forceFill(['status' => 'failed', 'processed_at' => now(), 'error' => $throwable->getMessage()])->save();
            Log::error('zoho_subscription_payment_apply_failed', $this->context($event, $info) + ['user_id' => $user->id, 'exception_message' => $throwable->getMessage()]);

            return [
                'message' => 'Webhook received but subscription/member payment processing failed.',
                'normalized' => $info,
                'webhook_event_id' => $event->id,
                'error' => $throwable->getMessage(),
            ];
        }
    }

    private function findSubscriptionPaymentTarget(array $info): array
    {
        $record = null;
        $user = null;

        if (filled($info['subscription_id'] ?? null)) {
            Log::info('zoho_subscription_payment_lookup_by_subscription_id', $this->context(null, $info));
            $record = $this->findRecordByColumnOrJson('payments', Payment::class, ['zoho_subscription_id'], (string) $info['subscription_id'], ['metadata', 'webhook_payload', 'raw_webhook_payload']);
            $record ??= $this->findRecordByColumnOrJson('circle_subscriptions', CircleSubscription::class, ['zoho_subscription_id'], (string) $info['subscription_id'], ['raw_webhook_payload', 'raw_checkout_response']);
            $user = $this->userFromRecord($record);
            $user ??= $this->findUserByColumn('zoho_subscription_id', (string) $info['subscription_id']);
        }

        if (! $user && filled($info['hosted_page_id'] ?? null)) {
            Log::info('zoho_subscription_payment_lookup_by_hosted_page_id', $this->context(null, $info));
            $hostedPageColumns = ['zoho_hosted_page_id', 'zoho_hostedpage_id', 'hosted_page_id', 'hostedpage_id', 'zoho_hosted_page_url', 'zoho_hostedpage_url', 'hosted_page_url', 'hostedpage_url', 'zoho_hosted_page_session_id', 'hosted_page_session_id'];
            $record = $this->findRecordByColumnOrJson('payments', Payment::class, $hostedPageColumns, (string) $info['hosted_page_id'], ['metadata', 'webhook_payload', 'raw_webhook_payload']);
            $record ??= $this->findRecordByColumnOrJson('circle_subscriptions', CircleSubscription::class, ['zoho_hosted_page_id'], (string) $info['hosted_page_id'], ['raw_webhook_payload', 'raw_checkout_response']);
            $user = $this->userFromRecord($record);
        }

        if (! $user && filled($info['invoice_id'] ?? null)) {
            Log::info('zoho_subscription_payment_lookup_by_invoice_id', $this->context(null, $info));
            $invoiceColumns = ['zoho_invoice_id', 'zoho_last_invoice_id', 'invoice_id'];
            $record = $this->findRecordByColumnOrJson('payments', Payment::class, $invoiceColumns, (string) $info['invoice_id'], ['metadata', 'webhook_payload', 'raw_webhook_payload']);
            $record ??= $this->findRecordByColumnOrJson('circle_subscriptions', CircleSubscription::class, $invoiceColumns, (string) $info['invoice_id'], ['raw_webhook_payload', 'raw_checkout_response']);
            $user = $this->userFromRecord($record);
            $user ??= $this->findUserByColumn('zoho_last_invoice_id', (string) $info['invoice_id']);
        }

        if (! $user && filled($info['customer_id'] ?? null)) {
            Log::info('zoho_subscription_payment_lookup_by_customer_id', $this->context(null, $info));
            $user = $this->findUserByColumn('zoho_customer_id', (string) $info['customer_id']);
        }

        if (! $user && filled($info['customer_email'] ?? null)) {
            Log::info('zoho_subscription_payment_lookup_by_email', $this->context(null, $info));
            $user = User::query()->where('email', (string) $info['customer_email'])->first();
        }

        if ($user && ! $record) {
            $record = $this->findRecentPendingSubscriptionPayment($user, $info);
        }

        if ($record || $user) {
            Log::info('zoho_subscription_payment_record_found', $this->context(null, $info) + ['user_id' => $user?->id, 'record_type' => $record ? $record::class : null, 'record_id' => $record?->getKey()]);
        } else {
            Log::warning('zoho_subscription_payment_record_not_found', $this->context(null, $info));
        }

        return ['record' => $record, 'user' => $user];
    }

    private function findRecordByColumnOrJson(string $table, string $modelClass, array $columns, string $value, array $jsonColumns = []): ?Model
    {
        if (! Schema::hasTable($table) || $value === '') {
            return null;
        }

        foreach ($columns as $column) {
            if (Schema::hasColumn($table, $column)) {
                $query = $modelClass::query();
                if (str_contains($column, 'url') || str_contains($column, 'session')) {
                    $query->where($column, 'like', '%'.$value.'%');
                } else {
                    $query->where($column, $value);
                }

                $record = $query->latest('created_at')->first();
                if ($record) {
                    return $record;
                }
            }
        }

        foreach ($jsonColumns as $column) {
            if (Schema::hasColumn($table, $column)) {
                $record = $modelClass::query()
                    ->whereRaw($this->jsonTextLikeExpression($column), ['%'.$value.'%'])
                    ->latest('created_at')
                    ->first();
                if ($record) {
                    return $record;
                }
            }
        }

        return null;
    }

    private function jsonTextLikeExpression(string $column): string
    {
        $driver = Schema::getConnection()->getDriverName();
        return match ($driver) {
            'pgsql' => $column.'::text LIKE ?',
            'sqlite' => $column.' LIKE ?',
            default => 'CAST('.$column.' AS CHAR) LIKE ?',
        };
    }

    private function userFromRecord(?Model $record): ?User
    {
        if (! $record || ! isset($record->user_id)) {
            return null;
        }

        return User::query()->where('id', $record->user_id)->first();
    }

    private function findUserByColumn(string $column, string $value): ?User
    {
        if (! Schema::hasColumn('users', $column) || $value === '') {
            return null;
        }

        return User::query()->where($column, $value)->first();
    }

    private function findRecentPendingSubscriptionPayment(User $user, array $info): ?Payment
    {
        if (! Schema::hasTable('payments') || ! Schema::hasColumn('payments', 'user_id')) {
            return null;
        }

        $query = Payment::query()->where('user_id', $user->id)->where('created_at', '>=', now()->subDays(7));

        if (Schema::hasColumn('payments', 'status')) {
            $query->whereIn('status', ['pending', 'processing', 'created']);
        }

        $amount = $info['amount'] ?? null;
        if ($amount !== null) {
            $amount = (float) $amount;
            $query->where(function ($amountQuery) use ($amount): void {
                foreach (['amount', 'total_amount', 'base_amount'] as $column) {
                    if (Schema::hasColumn('payments', $column)) {
                        $amountQuery->orWhereBetween($column, [$amount - 0.01, $amount + 0.01]);
                    }
                }
            });
        }

        return $query->latest('created_at')->first();
    }

    private function isPaidSubscriptionPayment(array $info): bool
    {
        $paymentStatus = strtolower((string) ($info['payment_status'] ?? ''));
        $status = strtolower((string) ($info['status'] ?? ''));
        $amountApplied = (float) ($info['amount_applied'] ?? 0);
        $balanceAmount = (float) ($info['balance_amount'] ?? 0);

        return in_array($paymentStatus, ['paid', 'success', 'succeeded', 'completed', 'payment_success', 'captured'], true)
            || in_array($status, ['paid', 'success', 'succeeded', 'completed', 'payment_success', 'captured'], true)
            || ($amountApplied > 0 && abs($balanceAmount) < 0.00001)
            || ! empty($info['payment_id']);
    }

    private function applySubscriptionPayment(?Model $record, User $user, array $payload, array $info): void
    {
        $paidAt = $this->parseDate($info['payment_date'] ?? null) ?? now();
        $startsAt = $user->membership_starts_at ?: now();
        $endsAt = $user->membership_ends_at ?: Carbon::parse($startsAt)->copy()->addYear();

        if ($record) {
            $this->updateSubscriptionPaymentRecord($record, $payload, $info, $paidAt);
        }

        $this->membershipUpgradeService->markAsOnlyUnityPeerAfterPayment($user, [
            'payment_id' => $record instanceof Payment ? $record->id : null,
            'zoho_customer_id' => $info['customer_id'] ?: $user->zoho_customer_id,
            'zoho_subscription_id' => $info['subscription_id'] ?: $user->zoho_subscription_id,
            'zoho_plan_code' => $user->zoho_plan_code ?: ($info['plan_code'] ?? 'unity_peer'),
            'zoho_invoice_id' => $info['invoice_id'] ?: $user->zoho_last_invoice_id,
            'zoho_payment_id' => $info['payment_id'] ?? null,
            'membership_starts_at' => $startsAt,
            'membership_ends_at' => $endsAt,
            'paid_at' => $paidAt,
            'amount' => $info['amount'] ?? null,
        ]);

        $userUpdates = [];
        if (Schema::hasColumn('users', 'is_paid_member')) {
            $userUpdates['is_paid_member'] = true;
        }
        if (Schema::hasColumn('users', 'membership_started_at')) {
            $userUpdates['membership_started_at'] = $startsAt;
        }
        if (Schema::hasColumn('users', 'membership_expires_at')) {
            $userUpdates['membership_expires_at'] = $endsAt;
        }
        if ($userUpdates !== []) {
            $user->forceFill($userUpdates)->save();
        }

        Log::info('zoho_subscription_user_membership_updated', $this->context(null, $info) + ['user_id' => $user->id]);
    }

    private function updateSubscriptionPaymentRecord(Model $record, array $payload, array $info, Carbon $paidAt): void
    {
        $table = $record->getTable();
        $updates = [];
        foreach ([
            'payment_status' => 'paid',
            'status' => 'paid',
            'zoho_payment_status' => 'paid',
            'zoho_payment_id' => $info['payment_id'] ?? null,
            'zoho_invoice_id' => $info['invoice_id'] ?? null,
            'zoho_invoice_number' => $info['invoice_number'] ?? null,
            'zoho_subscription_id' => $info['subscription_id'] ?? null,
            'zoho_hosted_page_id' => $info['hosted_page_id'] ?? null,
            'zoho_hostedpage_id' => $info['hosted_page_id'] ?? null,
            'payment_completed_at' => $paidAt,
            'paid_at' => $paidAt,
            'webhook_payload' => $payload,
            'zoho_payment_webhook_payload' => $payload,
            'raw_webhook_payload' => $payload,
            'invoice_status' => 'paid',
            'zoho_invoice_status' => 'paid',
        ] as $column => $value) {
            if ($value !== null && Schema::hasColumn($table, $column)) {
                $updates[$column] = $value;
            }
        }

        if ($updates !== []) {
            $record->forceFill($updates)->save();
        }
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function isPaidWebhook(array $info): bool
    {
        $type = strtolower((string) ($info['event_type'] ?? ''));
        $status = strtolower((string) ($info['status'] ?? ''));
        $paymentStatus = strtolower((string) ($info['payment_status'] ?? ''));
        $amountApplied = (float) ($info['amount_applied'] ?? 0);
        $balanceAmount = (float) ($info['balance_amount'] ?? 0);
        $invoiceLooksPaid = $amountApplied > 0 && abs($balanceAmount) < 0.00001;

        return str_contains($type, 'paid')
            || str_contains($type, 'success')
            || in_array($status, ['paid', 'success', 'succeeded', 'completed', 'payment_success', 'captured'], true)
            || in_array($paymentStatus, ['paid', 'success', 'succeeded', 'completed', 'payment_success', 'captured'], true)
            || ($type === 'customer_payment' && ! empty($info['payment_id']) && ($invoiceLooksPaid || in_array($status, ['paid', 'success'], true) || $paymentStatus === 'paid'));
    }

    private function isAlreadyFullySynced(EventRegistration $registration): bool
    {
        return ($registration->payment_status ?? null) === 'paid'
            && in_array(strtolower((string) ($registration->zoho_invoice_status ?? '')), ['paid', 'closed'], true)
            && ! empty($registration->qr_code_url)
            && empty($registration->zoho_invoice_sync_error);
    }

    private function storeFailedEventSafely(Request $request, array $payload, array $info, \Throwable $e): ?WebhookEvent
    {
        try {
            return WebhookEvent::query()->create([
                'provider' => 'zoho',
                'event_type' => $info['event_type'] ?? 'customer_payment',
                'external_event_id' => $info['external_event_id'] ?? null,
                'payment_link_id' => $info['payment_link_id'] ?? null,
                'payment_id' => $info['payment_id'] ?? null,
                'status' => 'failed',
                'payload' => $payload ?: ['raw' => $request->getContent()],
                'headers' => $this->safeHeaders($request),
                'error' => $e->getMessage(),
            ]);
        } catch (\Throwable) {
            return null;
        }
    }

    private function safeHeaders(Request $request): array
    {
        return collect($request->headers->all())->except(['authorization', 'cookie', 'x-webhook-secret'])->all();
    }

    private function context(?WebhookEvent $event, array $info): array
    {
        return [
            'webhook_event_id' => $event?->id,
            'event_type' => $info['event_type'] ?? $event?->event_type,
            'payment_link_id' => $info['payment_link_id'] ?? $event?->payment_link_id,
            'parsed_registration_id' => $info['parsed_registration_id'] ?? null,
            'parsed_payment_link_id' => $info['parsed_payment_link_id'] ?? null,
            'parsed_original_payment_id' => $info['parsed_original_payment_id'] ?? null,
            'payment_id' => $info['payment_id'] ?? $event?->payment_id,
            'payment_status' => $info['payment_status'] ?? null,
            'invoice_id' => $info['invoice_id'] ?? null,
            'invoice_number' => $info['invoice_number'] ?? null,
            'hosted_page_id' => $info['hosted_page_id'] ?? null,
            'subscription_id' => $info['subscription_id'] ?? null,
            'reference_number' => $info['reference_number'] ?? null,
            'description' => $info['description'] ?? null,
            'customer_id' => $info['customer_id'] ?? null,
            'email' => $info['customer_email'] ?? null,
            'amount' => $info['amount'] ?? null,
            'registration_id' => $info['registration_id'] ?? $event?->registration_id,
            'status' => $info['status'] ?? $event?->status,
            'payment_session_id' => $info['payment_session_id'] ?? null,
            'hosted_page_id' => $info['hosted_page_id'] ?? null,
            'url' => $info['url'] ?? null,
        ];
    }


    private function parseDescriptionIdentifiers(string $description): array
    {
        $parsed = [];
        if ($description === '') {
            return $parsed;
        }
        if (preg_match('/registration_id=([0-9a-fA-F-]{36})/', $description, $m)) {
            $parsed['registration_id'] = $m[1];
        }
        if (preg_match('/payment_link_id=([0-9]+)/', $description, $m) || preg_match('/Zoho Payment Link\s+([0-9]+)/i', $description, $m)) {
            $parsed['payment_link_id'] = $m[1];
        }
        if (preg_match('/original_payment_id=([0-9]+)/', $description, $m) || preg_match('/original payment\s+([0-9]+)/i', $description, $m)) {
            $parsed['original_payment_id'] = $m[1];
        }
        return $parsed;
    }

    private function blankToNull($value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function filter(array $data): array
    {
        return array_filter($data, fn ($value, $key) => Schema::hasColumn('event_registrations', $key), ARRAY_FILTER_USE_BOTH);
    }
}
