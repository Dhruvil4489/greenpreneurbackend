<?php

namespace App\Services\Events;

use App\Models\EventRegistration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class EventRegistrationPaymentSyncService
{
    public function __construct(private readonly EventRegistrationQrService $registrationQr) {}

    public function syncFromZohoWebhook(array $payload): ?EventRegistration
    {
        $registration = $this->resolveRegistration($payload);
        if (! $registration) {
            return null;
        }

        $status = $this->resolvePaymentStatus($payload);
        $invoiceId = $this->firstValue($payload, ['invoice.invoice_id', 'data.invoice.invoice_id', 'invoice_id', 'data.invoice_id']);
        $invoiceNumber = $this->firstValue($payload, ['invoice.invoice_number', 'invoice.number', 'data.invoice.invoice_number', 'data.invoice.number', 'invoice_number']);

        return DB::transaction(function () use ($registration, $status, $invoiceId, $invoiceNumber, $payload): EventRegistration {
            $registration = EventRegistration::query()->lockForUpdate()->findOrFail($registration->id);
            $updates = [
                'payment_status' => $status,
                'zoho_invoice_id' => $invoiceId ?: $registration->zoho_invoice_id,
                'zoho_invoice_number' => $invoiceNumber ?: $registration->zoho_invoice_number,
                'metadata' => array_merge((array) ($registration->metadata ?? []), ['latest_zoho_payment_webhook' => $payload]),
            ];

            if ($status === 'paid') {
                $updates['status'] = 'registered';
                $updates['payment_completed_at'] = now();
            }

            if (in_array($status, ['failed', 'cancelled'], true)) {
                $updates['status'] = 'pending_payment';
            }

            $registration->forceFill($this->filterRegistrationColumns($updates))->save();

            if ($status === 'paid') {
                $registration = $this->registrationQr->ensureQrGenerated($registration);
            }

            Log::info('Event registration payment webhook synced.', [
                'event_registration_id' => (string) $registration->id,
                'payment_status' => $status,
            ]);

            return $registration->fresh(['event.circle', 'occurrence', 'user']);
        });
    }

    private function resolveRegistration(array $payload): ?EventRegistration
    {
        $hostedPageId = $this->firstValue($payload, [
            'hostedpage.hostedpage_id', 'hosted_page.hostedpage_id', 'hostedpage_id', 'hosted_page_id', 'data.hostedpage.hostedpage_id',
            'payment.invoices.0.hosted_page_id', 'data.payment.invoices.0.hosted_page_id', 'customerpayment.hostedpage_id', 'data.customerpayment.hostedpage_id',
        ]);
        if ($hostedPageId && Schema::hasColumn('event_registrations', 'zoho_hosted_page_id')) {
            $registration = EventRegistration::query()->where('zoho_hosted_page_id', $hostedPageId)->first();
            if ($registration) {
                return $registration;
            }
        }

        $invoiceId = $this->firstValue($payload, [
            'invoice.invoice_id', 'data.invoice.invoice_id', 'invoice_id', 'data.invoice_id',
            'payment.invoices.0.invoice_id', 'data.payment.invoices.0.invoice_id', 'customerpayment.invoices.0.invoice_id',
        ]);
        if ($invoiceId && Schema::hasColumn('event_registrations', 'zoho_invoice_id')) {
            $registration = EventRegistration::query()->where('zoho_invoice_id', $invoiceId)->first();
            if ($registration) {
                return $registration;
            }
        }

        return $this->resolveLatestPendingRegistration($payload);
    }

    private function resolveLatestPendingRegistration(array $payload): ?EventRegistration
    {
        $customerId = $this->firstValue($payload, [
            'customer.customer_id', 'data.customer.customer_id', 'customer_id', 'data.customer_id',
            'invoice.customer_id', 'data.invoice.customer_id', 'payment.customer_id', 'data.payment.customer_id',
            'customerpayment.customer_id', 'data.customerpayment.customer_id',
        ]);
        $email = $this->firstValue($payload, [
            'customer.email', 'data.customer.email', 'email', 'data.email',
            'invoice.email', 'data.invoice.email', 'payment.email', 'data.payment.email',
            'customerpayment.email', 'data.customerpayment.email',
        ]);
        $amount = $this->firstValue($payload, [
            'invoice.total', 'data.invoice.total', 'invoice.amount', 'data.invoice.amount',
            'payment.amount', 'data.payment.amount', 'customerpayment.amount', 'data.customerpayment.amount',
            'amount', 'data.amount', 'total', 'data.total',
        ]);

        $query = EventRegistration::query()
            ->with('user')
            ->where('payment_required', true)
            ->where('payment_status', 'pending')
            ->where('status', 'pending_payment')
            ->whereNull('deleted_at');

        if ($customerId && Schema::hasColumn('event_registrations', 'zoho_customer_id')) {
            $query->where('zoho_customer_id', (string) $customerId);
        } elseif ($email) {
            $email = strtolower((string) $email);
            $query->where(function ($inner) use ($email): void {
                $inner->whereRaw("LOWER(COALESCE(visitor_email, '')) = ?", [$email])
                    ->orWhereHas('user', fn ($userQuery) => $userQuery->whereRaw("LOWER(COALESCE(email, '')) = ?", [$email]));
            });
        } else {
            return null;
        }

        if (is_numeric($amount)) {
            $query->where('amount', round((float) $amount, 2));
        }

        return $query->latest('registered_at')->first();
    }

    private function resolvePaymentStatus(array $payload): string
    {
        $raw = strtolower((string) $this->firstValue($payload, [
            'payment.status', 'data.payment.status', 'invoice.status', 'data.invoice.status', 'status', 'event_type', 'event_type_formatted',
        ]));

        if (str_contains($raw, 'paid') || str_contains($raw, 'success') || str_contains($raw, 'payment_thankyou')) {
            return 'paid';
        }

        if (str_contains($raw, 'cancel')) {
            return 'cancelled';
        }

        if (str_contains($raw, 'fail') || str_contains($raw, 'declin') || str_contains($raw, 'void')) {
            return 'failed';
        }

        return 'pending';
    }

    private function firstValue(array $payload, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = data_get($payload, $key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function filterRegistrationColumns(array $data): array
    {
        return array_filter($data, fn ($value, $key) => Schema::hasColumn('event_registrations', $key), ARRAY_FILTER_USE_BOTH);
    }
}
