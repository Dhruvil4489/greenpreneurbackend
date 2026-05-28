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
    public function __construct(private readonly EventPaymentSyncService $paymentSync) {}

    public function handle(Request $request): array
    {
        $payload = $request->all();
        $info = $this->extract($payload);
        $info['external_event_id'] = $info['external_event_id'] ?: $request->header('X-Zoho-Webhook-Id');
        Log::info('zoho_payment_webhook_received', $this->context(null, $info));

        $event = $this->storeEvent($request, $payload, $info);
        if (in_array($event->status, ['processed', 'ignored'], true) && $event->processed_at) {
            Log::info('zoho_payment_webhook_duplicate_ignored', $this->context($event, $info));
            return ['message' => 'Webhook already processed.'];
        }

        $event->forceFill(['status' => 'processing'])->save();
        $registration = $this->findRegistration($payload, $info);
        if (! $registration) {
            $event->forceFill(['status' => 'ignored', 'processed_at' => now(), 'error' => 'Registration not found.'])->save();
            Log::warning('zoho_payment_webhook_registration_not_found', $this->context($event, $info));
            return ['message' => 'Webhook received.'];
        }

        $event->forceFill(['registration_id' => $registration->id])->save();
        $info['registration_id'] = (string) $registration->id;
        Log::info('zoho_payment_webhook_registration_found', $this->context($event, $info));

        $status = strtolower((string) ($info['status'] ?? ''));
        $type = strtolower((string) ($info['event_type'] ?? ''));
        if (str_contains($type, 'cancel') || str_contains($type, 'expired') || in_array($status, ['cancelled', 'canceled', 'expired'], true)) {
            $this->markCancelledOrExpired($registration, $payload, str_contains($type.$status, 'expired') ? 'expired' : 'cancelled');
            $event->forceFill(['status' => 'processed', 'processed_at' => now()])->save();
            Log::info('zoho_payment_webhook_cancelled_or_expired', $this->context($event, $info));
            return ['message' => 'Webhook received.'];
        }

        if (str_contains($type, 'paid') || str_contains($type, 'success') || in_array($status, ['paid', 'success', 'succeeded'], true) || ! empty($info['payment_id'])) {
            Log::info('zoho_payment_webhook_paid_sync_start', $this->context($event, $info));
            try {
                $this->primePaidFields($registration, $payload, $info);
                $this->paymentSync->syncRegistrationPayment($registration->fresh(['event', 'occurrence', 'user']), [
                    'source' => 'zoho_webhook',
                    'webhook_event_id' => $event->id,
                    'payload' => $payload,
                    'payment_id' => $info['payment_id'] ?? null,
                ]);
                $event->forceFill(['status' => 'processed', 'processed_at' => now(), 'error' => null])->save();
                Log::info('zoho_payment_webhook_paid_sync_success', $this->context($event, $info));
                Log::info('zoho_payment_webhook_processed', $this->context($event, $info));
            } catch (\Throwable $e) {
                $event->forceFill(['status' => 'failed', 'error' => $e->getMessage()])->save();
                Log::error('zoho_payment_webhook_paid_sync_failed', $this->context($event, $info) + ['error' => $e->getMessage()]);
            }
        } else {
            $event->forceFill(['status' => 'ignored', 'processed_at' => now(), 'error' => 'Unsupported webhook event/status.'])->save();
        }

        return ['message' => 'Webhook received.'];
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
        $fake = Request::create('/internal', 'POST', [], [], [], [], json_encode($event->payload));
        $fake->headers->set('Content-Type', 'application/json');
        $this->handle($fake);
    }

    public function extract(array $payload): array
    {
        return [
            'event_type' => data_get($payload, 'event') ?? data_get($payload, 'event_type') ?? data_get($payload, 'type') ?? data_get($payload, 'event_name'),
            'external_event_id' => data_get($payload, 'event_id') ?? data_get($payload, 'id') ?? data_get($payload, 'webhook_id'),
            'payment_link_id' => data_get($payload, 'payment_link.payment_link_id') ?? data_get($payload, 'payment_link_id') ?? data_get($payload, 'data.payment_link_id') ?? data_get($payload, 'data.payment_link.payment_link_id') ?? data_get($payload, 'payment_link.id'),
            'payment_id' => data_get($payload, 'payment.payment_id') ?? data_get($payload, 'payment_id') ?? data_get($payload, 'data.payment_id') ?? data_get($payload, 'data.payment.payment_id') ?? data_get($payload, 'payment.id') ?? data_get($payload, 'customer_payments.0.payment_id') ?? data_get($payload, 'payment_link.customer_payments.0.payment_id'),
            'status' => data_get($payload, 'status') ?? data_get($payload, 'payment_link.status') ?? data_get($payload, 'payment.status') ?? data_get($payload, 'data.status'),
        ];
    }

    private function storeEvent(Request $request, array $payload, array $info): WebhookEvent
    {
        $query = WebhookEvent::query()->where('provider', 'zoho');
        if (! empty($info['external_event_id'])) {
            $query->where('external_event_id', $info['external_event_id']);
        } else {
            $query->where('event_type', $info['event_type'])->where('payment_link_id', $info['payment_link_id'])->where('payment_id', $info['payment_id']);
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

    private function findRegistration(array $payload, array $info): ?EventRegistration
    {
        Log::info('zoho_payment_webhook_registration_lookup_start', $this->context(null, $info));
        if (! empty($info['payment_link_id'])) {
            $r = EventRegistration::query()->where('zoho_payment_link_id', $info['payment_link_id'])->first();
            if ($r) return $r;
        }
        if (! empty($info['payment_id'])) {
            $r = EventRegistration::query()->where('zoho_payment_id', $info['payment_id'])->first();
            if ($r) return $r;
        }
        $url = data_get($payload, 'payment_link.url') ?? data_get($payload, 'url') ?? data_get($payload, 'data.url');
        if ($url) {
            $r = EventRegistration::query()->where('zoho_payment_link_url', $url)->orWhere('payment_url', $url)->first();
            if ($r) return $r;
        }
        foreach ([data_get($payload, 'registration_id'), data_get($payload, 'reference_number'), data_get($payload, 'payment.reference_number'), data_get($payload, 'data.reference_number')] as $id) {
            if ($id && Str::isUuid((string) $id)) {
                $r = EventRegistration::query()->find($id);
                if ($r) return $r;
            }
        }
        return null;
    }

    private function primePaidFields(EventRegistration $registration, array $payload, array $info): void
    {
        $paidAt = data_get($payload, 'payment.date') ?? data_get($payload, 'payment.payment_date') ?? data_get($payload, 'payment_link.customer_payments.0.payment_date') ?? data_get($payload, 'customer_payments.0.payment_date');
        $registration->forceFill($this->filter([
            'zoho_payment_id' => $info['payment_id'] ?: $registration->zoho_payment_id,
            'zoho_payment_status' => 'paid',
            'payment_status' => 'paid',
            'payment_completed_at' => $registration->payment_completed_at ?: ($paidAt ? now()->parse((string) $paidAt) : now()),
            'zoho_payment_webhook_payload' => $payload,
            'webhook_payload' => $payload,
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
            'payment_id' => $info['payment_id'] ?? $event?->payment_id,
            'registration_id' => $info['registration_id'] ?? $event?->registration_id,
            'status' => $info['status'] ?? $event?->status,
        ];
    }

    private function filter(array $data): array
    {
        return array_filter($data, fn ($value, $key) => Schema::hasColumn('event_registrations', $key), ARRAY_FILTER_USE_BOTH);
    }
}
