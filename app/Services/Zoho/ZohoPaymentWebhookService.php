<?php

namespace App\Services\Zoho;

use App\Models\EventRegistration;
use App\Models\WebhookEvent;
use App\Services\Events\EventPaymentSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ZohoPaymentWebhookService
{
    private ?string $lastLookupError = null;

    public function __construct(private readonly EventPaymentSyncService $paymentSync) {}

    public function handle(Request $request): array
    {
        $payload = $request->all();
        $normalized = $this->normalizeZohoPaymentWebhookPayload($payload);
        $normalized['external_event_id'] = $normalized['external_event_id'] ?: $request->header('X-Zoho-Webhook-Id');
        $event = null;

        Log::info('zoho_payment_webhook_received_raw', $this->context(null, $normalized));
        Log::info('zoho_payment_webhook_payload_normalized', $this->context(null, $normalized) + ['normalized' => $normalized]);

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
        $registration = $this->findRegistration($payload, $normalized, $event);
        if (! $registration) {
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
        $description = $payment['description'] ?? data_get($payload, 'description') ?? data_get($payload, 'data.description') ?? ($dataPayment['description'] ?? null);
        $parsed = $this->parseDescriptionIdentifiers((string) $description);

        return [
            'event_type' => data_get($payload, 'event') ?? data_get($payload, 'event_type') ?? data_get($payload, 'type') ?? data_get($payload, 'event_name') ?? 'customer_payment',
            'external_event_id' => data_get($payload, 'event_id') ?? data_get($payload, 'id') ?? data_get($payload, 'webhook_id'),
            'payment_id' => $payment['payment_id'] ?? data_get($payload, 'payment_id') ?? data_get($payload, 'data.payment_id') ?? ($dataPayment['payment_id'] ?? null) ?? data_get($payload, 'payment.id') ?? data_get($payload, 'customer_payments.0.payment_id') ?? data_get($payload, 'payment_link.customer_payments.0.payment_id'),
            'payment_link_id' => $this->blankToNull($payment['payment_link_id'] ?? data_get($payload, 'payment_link.payment_link_id') ?? data_get($payload, 'payment_link_id') ?? data_get($payload, 'data.payment_link_id') ?? data_get($payload, 'data.payment_link.payment_link_id') ?? data_get($payload, 'payment_link.id')),
            'reference_number' => $payment['reference_number'] ?? data_get($payload, 'reference_number') ?? data_get($payload, 'data.reference_number') ?? ($dataPayment['reference_number'] ?? null),
            'online_transaction_id' => $payment['online_transaction_id'] ?? data_get($payload, 'online_transaction_id') ?? data_get($payload, 'data.online_transaction_id') ?? ($dataPayment['online_transaction_id'] ?? null),
            'description' => $description,
            'customer_id' => $payment['customer_id'] ?? data_get($payload, 'customer_id') ?? data_get($payload, 'data.customer_id') ?? ($dataPayment['customer_id'] ?? null),
            'amount' => $payment['amount'] ?? data_get($payload, 'amount') ?? data_get($payload, 'data.amount') ?? ($dataPayment['amount'] ?? null),
            'payment_date' => $payment['date'] ?? $payment['payment_date'] ?? data_get($payload, 'payment_date') ?? data_get($payload, 'date') ?? data_get($payload, 'data.date') ?? ($dataPayment['date'] ?? null),
            'status' => $payment['payment_status'] ?? $payment['status'] ?? data_get($payload, 'status') ?? data_get($payload, 'payment_link.status') ?? data_get($payload, 'data.status') ?? ($dataPayment['payment_status'] ?? null) ?? ($dataPayment['status'] ?? null),
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

        $url = data_get($payload, 'payment_link.url') ?? data_get($payload, 'url') ?? data_get($payload, 'data.url');
        if ($url) {
            $registration = EventRegistration::query()->where('zoho_payment_link_url', $url)->orWhere('payment_url', $url)->latest('created_at')->first();
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
            'zoho_payment_id' => $registration->zoho_payment_id ?: ($info['parsed_original_payment_id'] ?? $info['payment_id']),
            'zoho_payment_status' => 'paid',
            'payment_status' => 'paid',
            'payment_completed_at' => $registration->payment_completed_at ?: ($info['payment_date'] ? now()->parse((string) $info['payment_date']) : now()),
            'zoho_payment_webhook_payload' => $payload,
            'webhook_payload' => $payload,
            'metadata' => array_merge((array) ($registration->metadata ?? []), [
                'zoho_webhook_payment_id' => $info['payment_id'] ?? null,
                'zoho_webhook_reference_number' => $info['reference_number'] ?? null,
            ]),
        ]))->save();
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

    private function isPaidWebhook(array $info): bool
    {
        $type = strtolower((string) ($info['event_type'] ?? ''));
        $status = strtolower((string) ($info['status'] ?? ''));
        return $type === 'customer_payment'
            || str_contains($type, 'paid')
            || str_contains($type, 'success')
            || in_array($status, ['paid', 'success', 'succeeded'], true)
            || ! empty($info['payment_id']);
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
            'parsed_payment_link_id' => $info['parsed_payment_link_id'] ?? null,
            'parsed_original_payment_id' => $info['parsed_original_payment_id'] ?? null,
            'payment_id' => $info['payment_id'] ?? $event?->payment_id,
            'reference_number' => $info['reference_number'] ?? null,
            'description' => $info['description'] ?? null,
            'customer_id' => $info['customer_id'] ?? null,
            'amount' => $info['amount'] ?? null,
            'registration_id' => $info['registration_id'] ?? $event?->registration_id,
            'status' => $info['status'] ?? $event?->status,
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
