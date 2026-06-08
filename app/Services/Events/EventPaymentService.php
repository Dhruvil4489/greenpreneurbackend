<?php

namespace App\Services\Events;

use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventRegistration;
use App\Models\User;
use App\Services\Zoho\ZohoBillingPaymentLinkService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        $gateway = $registration->payment_gateway ?: (string) config('services.event_payment_gateway', env('EVENT_PAYMENT_GATEWAY', 'zoho_billing_payment_link'));
        $gateway = $this->gatewayFor($registration);
        $currentPaymentUrl = $registration->payment_url
            ?? $registration->zoho_checkout_url
            ?? $registration->zoho_payment_link_url
            ?? $registration->zoho_hosted_page_url
            ?? null;

        if (! in_array(strtolower((string) ($registration->payment_status ?? '')), ['paid', 'success', 'completed'], true)) {
            if ($gateway === 'zoho_billing_payment_link') {
                if (empty($currentPaymentUrl)) {
                    $registration = app(\App\Services\Zoho\ZohoBillingPaymentLinkService::class)
                        ->createPaymentLink($registration->fresh(['event', 'occurrence', 'user', 'businessCategoryMain', 'businessCategorySub']));
                    Log::warning('event_registration_payment_url_missing_creating_zoho_link', [
                        'registration_id' => (string) $registration->id,
                        'event_id' => (string) $registration->event_id,
                        'occurrence_id' => (string) $registration->occurrence_id,
                        'payment_gateway' => $registration->payment_gateway,
                        'payment_status' => $registration->payment_status,
                    ]);
                    $registration = app(\App\Services\Zoho\ZohoBillingPaymentLinkService::class)
                        ->createPaymentLink($registration->fresh(['event', 'occurrence', 'user', 'businessCategoryMain', 'businessCategorySub']));
                } else {
                    $registration->forceFill($this->filterRegistrationColumns([
                        'payment_gateway' => 'zoho_billing_payment_link',
                        'payment_url' => $currentPaymentUrl,
                        'checkout_url' => $currentPaymentUrl,
                    ]))->save();
                    $registration = $registration->fresh(['event.circle', 'occurrence', 'user', 'invitedByUser', 'businessCategoryMain', 'businessCategorySub']);
                }
            } else {
                $registration = $this->razorpay->createOrder($registration->fresh(['event', 'occurrence', 'user', 'businessCategoryMain', 'businessCategorySub']));
            }
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
        $paymentStatus = strtolower((string) ($registration->payment_status ?? ''));
        $paid = in_array($paymentStatus, ['paid', 'success', 'completed'], true);
        $paymentRequired = (bool) ($registration->payment_required ?? false);
        $requiresPayment = $paymentRequired && ! $paid;
        $gateway = $paymentRequired ? ($registration->payment_gateway ?: (string) config('services.event_payment_gateway', env('EVENT_PAYMENT_GATEWAY', 'zoho_billing_payment_link'))) : null;
        $gateway = $paymentRequired ? $this->gatewayFor($registration) : null;
        $paymentUrl = $registration->payment_url
            ?? $registration->checkout_url
            ?? $registration->zoho_checkout_url
            ?? $registration->zoho_payment_link_url
            ?? $registration->zoho_hosted_page_url
            ?? null;
        $formUrl = $registration->visitor_registration_form_url ?: url('/events/'.$registration->event_id.'/occurrences/'.$registration->occurrence_id.'/visitor-register?registration_id='.$registration->id);
        $formUrl = $registration->visitor_registration_form_url ?: app(EventRegistrationService::class)->visitorRegistrationFormUrl($registration);
        $zohoLinkFailed = $requiresPayment && $gateway === 'zoho_billing_payment_link' && empty($paymentUrl);

        $payload = [
            'registration_id' => $registration->id,
            'registration_type' => $registration->registration_type ?? null,
            'status' => ! $zohoLinkFailed,
            'success' => ! $zohoLinkFailed,
            'payment_required' => $paymentRequired,
            'requires_payment' => $requiresPayment,
            'payment_status' => $registration->payment_status ?? ($requiresPayment ? 'pending' : 'not_required'),
            'amount' => $registration->amount !== null ? (string) $registration->amount : null,
            'currency' => $registration->currency ?? 'INR',
            'payment_gateway' => $gateway,
            'payment_url' => $paymentUrl,
            'checkout_url' => $paymentUrl,
            'zoho_checkout_url' => $registration->zoho_checkout_url ?? null,
            'zoho_payment_link_url' => $registration->zoho_payment_link_url ?? null,
            'zoho_hosted_page_url' => $registration->zoho_hosted_page_url ?? null,
            'visitor_registration_form_url' => $formUrl,
            'form_url' => $formUrl,
            'qr_code_url' => $requiresPayment
                ? null
                : ($registration->qr_code_path ? app(EventQrService::class)->url($registration->qr_code_path) : $registration->qr_code_url),
            'zoho_invoice_id' => $registration->zoho_invoice_id ?? null,
            'zoho_invoice_number' => $registration->zoho_invoice_number ?? null,
            'zoho_invoice_url' => $registration->zoho_invoice_url ?? null,
            'zoho_invoice_pdf_url' => $registration->zoho_invoice_pdf_url ?? null,
            'message' => $zohoLinkFailed
                ? 'Unable to create Zoho payment link. Please try again.'
                : ($paid
                ? 'Already registered. Payment completed.'
                : ($requiresPayment
                ? 'Payment required. Please complete payment.'
                : 'Event registration successful.')),
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
            'visitor_business_category_main' => $registration->visitor_business_category_main ?? data_get($registration->metadata, 'visitor_business_category_main'),
            'visitor_business_category_sub' => $registration->visitor_business_category_sub ?? data_get($registration->metadata, 'visitor_business_category_sub'),
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

    private function gatewayFor(EventRegistration $registration): string
    {
        $gateway = strtolower((string) ($registration->payment_gateway ?: config('services.event_payment_gateway', env('EVENT_PAYMENT_GATEWAY', 'zoho_billing_payment_link'))));

        if ($gateway === '' || in_array($gateway, ['none', 'not_required', 'null'], true)) {
            return 'zoho_billing_payment_link';
        }

        return $gateway;
    }

    private function filterRegistrationColumns(array $data): array
    {
        return array_filter($data, fn ($value, $key) => Schema::hasColumn('event_registrations', $key), ARRAY_FILTER_USE_BOTH);
    }
}
