<?php

namespace App\Services\Events;

use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventRegistration;
use App\Models\User;
use App\Services\Zoho\ZohoBillingPaymentLinkService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EventPaymentService
{
    public function __construct(private readonly EventRazorpayPaymentService $razorpay, private readonly ZohoBillingPaymentLinkService $zohoBillingPaymentLink) {}

    public function paymentRequired(Event $event): bool
    {
        return (bool) ($event->is_paid ?? false) || (float) ($event->ticket_price ?? 0) > 0;
    }

    public function amount(Event $event): float
    {
        return round(max((float) ($event->ticket_price ?? 0), 0), 2);
    }

    public function currency(Event $event): string
    {
        $currency = (string) data_get($event->metadata, 'currency', 'INR');

        return $currency !== '' ? strtoupper($currency) : 'INR';
    }

    public function applyInitialPaymentState(EventRegistration $registration, Event $event, string $registrationType): EventRegistration
    {
        $paymentRequired = $this->paymentRequired($event);
        $updates = [
            'payment_required' => $paymentRequired,
            'payment_status' => $paymentRequired ? 'pending' : 'not_required',
            'amount' => $paymentRequired ? $this->amount($event) : 0,
            'currency' => $this->currency($event),
            'registration_type' => $registrationType,
        ];

        if ($paymentRequired) {
            $updates['status'] = 'pending_payment';
            $updates['checkin_status'] = 'pending';
        }

        $registration->forceFill($this->filterRegistrationColumns($updates))->save();

        return $registration->fresh(['event.circle', 'occurrence', 'user', 'invitedByUser', 'businessCategoryMain', 'businessCategorySub']);
    }

    public function attachCheckout(EventRegistration $registration): EventRegistration
    {
        if (! (bool) ($registration->payment_required ?? false)) {
            return $registration;
        }

        $gateway = (string) config('services.event_payment_gateway', env('EVENT_PAYMENT_GATEWAY', 'zoho_billing_payment_link'));
        if ($gateway === 'zoho_billing_payment_link') {
            $registration = app(\App\Services\Zoho\ZohoBillingPaymentLinkService::class)
                ->createPaymentLink($registration->fresh(['event', 'occurrence', 'user', 'businessCategoryMain', 'businessCategorySub']));
        } else {
            $registration = $this->razorpay->createOrder($registration->fresh(['event', 'occurrence', 'user', 'businessCategoryMain', 'businessCategorySub']));
        }

        return DB::transaction(function () use ($registration, $gateway): EventRegistration {
            $registration = EventRegistration::query()->lockForUpdate()->findOrFail($registration->id);
            $metadata = array_merge((array) ($registration->metadata ?? []), [
                'event_payment' => [
                    'type' => 'event_registration',
                    'gateway' => $gateway,
                    'registration_id' => (string) $registration->id,
                    'event_id' => (string) $registration->event_id,
                    'occurrence_id' => (string) $registration->occurrence_id,
                ],
            ]);

            $registration->forceFill($this->filterRegistrationColumns([
                'metadata' => $metadata,
            ]))->save();

            return $registration->fresh(['event.circle', 'occurrence', 'user', 'invitedByUser', 'businessCategoryMain', 'businessCategorySub']);
        });
    }

    public function responsePayload(EventRegistration $registration): array
    {
        $requiresPayment = (bool) ($registration->payment_required ?? false);
        $gateway = $requiresPayment ? (string) config('services.event_payment_gateway', env('EVENT_PAYMENT_GATEWAY', 'zoho_billing_payment_link')) : null;
        $paymentUrl = $registration->payment_url ?? $registration->zoho_payment_link_url ?? $registration->zoho_hosted_page_url ?? null;
        $zohoLinkFailed = $requiresPayment && $gateway === 'zoho_billing_payment_link' && empty($paymentUrl);

        $payload = [
            'registration_id' => $registration->id,
            'registration_type' => $registration->registration_type ?? null,
            'status' => ! $zohoLinkFailed,
            'success' => ! $zohoLinkFailed,
            'payment_required' => $requiresPayment,
            'requires_payment' => $requiresPayment,
            'payment_status' => $registration->payment_status ?? ($requiresPayment ? 'pending' : 'not_required'),
            'amount' => $registration->amount !== null ? (string) $registration->amount : null,
            'currency' => $registration->currency ?? 'INR',
            'payment_gateway' => $gateway,
            'payment_url' => $paymentUrl,
            'checkout_url' => $paymentUrl,
            'qr_code_url' => $requiresPayment && ($registration->payment_status ?? null) !== 'paid'
                ? null
                : ($registration->qr_code_url ?: app(EventQrService::class)->url($registration->qr_code_path)),
            'zoho_invoice_id' => $registration->zoho_invoice_id ?? null,
            'zoho_invoice_number' => $registration->zoho_invoice_number ?? null,
            'zoho_invoice_url' => $registration->zoho_invoice_url ?? null,
            'zoho_invoice_pdf_url' => $registration->zoho_invoice_pdf_url ?? null,
            'message' => $zohoLinkFailed
                ? 'Unable to create Zoho payment link. Please try again.'
                : ($requiresPayment
                ? 'Payment required. Please complete Zoho Billing payment.'
                : 'Event registration successful.'),
            'error' => $zohoLinkFailed ? ($registration->zoho_invoice_sync_error ?? 'Zoho API request failed.') : null,
        ] + $this->visitorRegistrationDetails($registration);

        if ($requiresPayment && $gateway === 'razorpay') {
            $payload['razorpay'] = $this->razorpay->checkoutPayload($registration);
        }

        return $payload;
    }

    private function visitorRegistrationDetails(EventRegistration $registration): array
    {
        return [
            'visitor_designation' => $registration->visitor_designation ?? data_get($registration->metadata, 'visitor_designation'),
            'visitor_business_category_id' => $registration->visitor_business_category_id ?? data_get($registration->metadata, 'visitor_business_category_id'),
            'visitor_business_category' => $registration->visitor_business_category ?? data_get($registration->metadata, 'visitor_business_category'),
            'visitor_business_category_main_id' => $registration->visitor_business_category_main_id ?? data_get($registration->metadata, 'visitor_business_category_main_id'),
            'visitor_business_category_sub_id' => $registration->visitor_business_category_sub_id ?? data_get($registration->metadata, 'visitor_business_category_sub_id') ?? $registration->visitor_business_category_id ?? data_get($registration->metadata, 'visitor_business_category_id'),
            'business_category_main' => $registration->businessCategoryMainPayload(),
            'business_category_sub' => $registration->businessCategorySubPayload(),
            'visitor_business_website' => $registration->visitor_business_website ?? data_get($registration->metadata, 'visitor_business_website'),
            'visitor_business_brief' => $registration->visitor_business_brief ?? data_get($registration->metadata, 'visitor_business_brief'),
            'invited_by_type' => $registration->invited_by_type ?? data_get($registration->metadata, 'invited_by_type'),
            'invited_by_user_id' => $registration->invited_by_user_id ?? data_get($registration->metadata, 'invited_by_user_id'),
            'invited_by_user' => $this->invitedByUserPayload($registration->invitedByUser),
        ];
    }


    private function invitedByUserPayload(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'display_name' => $user->display_name ?: trim(($user->first_name ?? '').' '.($user->last_name ?? '')),
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'company_name' => $user->company_name,
            'designation' => $user->designation,
            'profile_photo_url' => $user->profile_photo_url ?? null,
        ];
    }

    private function filterRegistrationColumns(array $data): array
    {
        return array_filter($data, fn ($value, $key) => Schema::hasColumn('event_registrations', $key), ARRAY_FILTER_USE_BOTH);
    }
}
