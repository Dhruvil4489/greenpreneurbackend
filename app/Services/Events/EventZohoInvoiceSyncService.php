<?php

namespace App\Services\Events;

use App\Models\EventRegistration;
use App\Models\User;
use App\Support\Zoho\ZohoBillingService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class EventZohoInvoiceSyncService
{
    public function __construct(private readonly ZohoBillingService $zohoBillingService) {}

    public function sync(EventRegistration $registration): EventRegistration
    {
        $registration->loadMissing(['event', 'occurrence', 'user']);

        if (! empty($registration->zoho_invoice_id)) {
            return $registration->fresh(['event.circle', 'occurrence', 'user']);
        }

        try {
            $invoice = $this->zohoBillingService->createInvoiceForEventRegistration(
                $this->customerPayload($registration),
                $this->invoicePayload($registration)
            );

            $registration->forceFill($this->filterRegistrationColumns([
                'zoho_customer_id' => $invoice['customer_id'] ?? $registration->zoho_customer_id,
                'zoho_invoice_id' => $invoice['invoice_id'] ?? null,
                'zoho_invoice_number' => $invoice['invoice_number'] ?? null,
                'zoho_invoice_url' => $invoice['invoice_url'] ?? null,
                'zoho_invoice_pdf_url' => $invoice['invoice_pdf_url'] ?? null,
                'zoho_invoice_synced_at' => now(),
                'zoho_invoice_sync_error' => null,
            ]))->save();
        } catch (\Throwable $exception) {
            Log::error('Event Zoho invoice sync failed', [
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
        $eventTitle = (string) ($registration->event?->title ?? 'Unity Event');
        $occurrenceDate = optional($registration->occurrence?->start_at)->toDateTimeString();

        return [
            'registration_id' => (string) $registration->id,
            'event_title' => $eventTitle,
            'description' => trim($eventTitle.' '.($occurrenceDate ? 'on '.$occurrenceDate : '').' - '.$attendeeName),
            'amount' => (float) ($registration->amount ?? 0),
            'currency' => (string) ($registration->currency ?? 'INR'),
        ];
    }

    private function filterRegistrationColumns(array $data): array
    {
        return array_filter($data, fn ($value, $key) => Schema::hasColumn('event_registrations', $key), ARRAY_FILTER_USE_BOTH);
    }
}
