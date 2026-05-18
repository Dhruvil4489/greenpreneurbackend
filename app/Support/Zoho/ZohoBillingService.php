<?php

namespace App\Support\Zoho;

use App\Models\Circle;
use App\Models\User;
use App\Services\Membership\MembershipWelcomeEmailService;
use App\Support\Membership\MembershipUpdater;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ZohoBillingService
{
    public function __construct(
        private readonly ZohoBillingClient $client,
        private readonly MembershipUpdater $membershipUpdater,
        private readonly MembershipWelcomeEmailService $membershipWelcomeEmailService,
    ) {
    }

    public function listActivePlans(): array
    {
        $response = $this->client->request('GET', '/plans', [
            'page' => 1,
            'per_page' => 200,
        ], true);
        $plans = $response['plans'] ?? [];

        return collect(array_values(array_filter($plans, fn (array $plan) => strtolower((string) ($plan['status'] ?? '')) === 'active')))
            ->map(fn (array $plan) => [
                'plan_code' => $plan['plan_code'] ?? null,
                'name' => $plan['name'] ?? null,
                'price' => $plan['recurring_price']
                    ?? $plan['price']
                    ?? data_get($plan, 'plan_item.price')
                    ?? data_get($plan, 'item.price')
                    ?? null,
                'interval' => $plan['interval'] ?? null,
                'status' => $plan['status'] ?? null,
                'description' => $plan['description'] ?? null,
            ])
            ->values()
            ->all();
    }


    public function listAddons(bool $activeOnly = true): array
    {
        $response = $this->client->request('GET', '/addons', [
            'page' => 1,
            'per_page' => 200,
        ], true);

        $addons = $response['addons'] ?? [];

        $normalized = collect($addons)
            ->filter(fn ($addon) => is_array($addon))
            ->map(function (array $addon) {
                return [
                    'addon_id' => (string) ($addon['addon_id'] ?? ''),
                    'addon_code' => (string) ($addon['addon_code'] ?? ''),
                    'name' => (string) ($addon['name'] ?? ''),
                    'amount' => $this->resolveAddonAmount($addon),
                    'currency_code' => $this->resolveAddonCurrency($addon),
                    'billing_frequency' => $addon['billing_frequency'] ?? null,
                    'status' => strtolower((string) ($addon['status'] ?? '')),
                    'raw' => $addon,
                ];
            });

        if ($activeOnly) {
            $normalized = $normalized->filter(fn (array $addon) => $addon['status'] === 'active');
        }

        return $normalized->values()->all();
    }

    public function listCirclePackageAddons(bool $activeOnly = true): array
    {
        return collect($this->listAddons($activeOnly))
            ->filter(fn (array $addon) => str_starts_with((string) ($addon['addon_code'] ?? ''), 'Package-'))
            ->values()
            ->all();
    }

    public function findCirclePackageAddonByCodeOrId(string $addonCodeOrId, bool $activeOnly = true): ?array
    {
        $needle = trim($addonCodeOrId);

        if ($needle === '') {
            return null;
        }

        $matchedAddon = collect($this->listCirclePackageAddons($activeOnly))
            ->first(function (array $addon) use ($needle) {
                return (string) ($addon['addon_id'] ?? '') === $needle
                    || (string) ($addon['addon_code'] ?? '') === $needle;
            });

        if (is_array($matchedAddon)) {
            Log::info('circle package addon matched', [
                'addon_code' => $matchedAddon['addon_code'] ?? null,
                'addon_id' => $matchedAddon['addon_id'] ?? null,
                'amount' => $matchedAddon['amount'] ?? null,
                'currency_code' => $matchedAddon['currency_code'] ?? null,
                'raw_selected_addon_payload' => $matchedAddon['raw'] ?? null,
            ]);
        }

        return $matchedAddon;
    }

    public function createHostedPageForCircleAddon(User $user, Circle $circle): array
    {
        $addonCode = trim((string) ($circle->zoho_addon_code ?? ''));

        if ($addonCode === '') {
            throw ValidationException::withMessages([
                'circle' => 'Selected circle does not have a package configured.',
            ]);
        }

        $customerId = $this->ensureCustomerForUser($user);
        $activeSubscription = $this->resolveActiveBaseSubscription($user, $customerId);

        if (! is_array($activeSubscription)) {
            Log::warning('existing zoho subscription not found for circle checkout', [
                'user_id' => $user->id,
                'circle_id' => $circle->id,
                'customer_id' => $customerId,
                'addon_code' => $addonCode,
            ]);

            throw ValidationException::withMessages([
                'subscription' => 'Active Unity subscription is required before purchasing a circle package.',
            ]);
        }

        $subscriptionId = (string) ($activeSubscription['subscription_id'] ?? '');

        if ($subscriptionId === '') {
            throw ValidationException::withMessages([
                'subscription' => 'Active Unity subscription is required before purchasing a circle package.',
            ]);
        }

        Log::info('existing zoho subscription found for circle checkout', [
            'user_id' => $user->id,
            'circle_id' => $circle->id,
            'customer_id' => $customerId,
            'subscription_id' => $subscriptionId,
            'addon_code' => $addonCode,
        ]);

        $referenceId = 'CIR' . now()->format('YmdHis') . random_int(100, 999);
        $payload = [
            'subscription_id' => $subscriptionId,
            'addons' => [[
                'addon_code' => $addonCode,
                'quantity' => 1,
            ]],
            'prorate' => false,
            'reference_id' => $referenceId,
        ];

        Log::info('circle hosted page request payload', [
            'user_id' => $user->id,
            'circle_id' => $circle->id,
            'addon_code' => $addonCode,
            'subscription_id' => $subscriptionId,
            'reference_id' => $referenceId,
            'payload' => $payload,
        ]);

        try {
            $response = $this->client->request('POST', '/hostedpages/updatesubscription', $payload);

            Log::info('circle hosted page response payload', [
                'user_id' => $user->id,
                'circle_id' => $circle->id,
                'subscription_id' => $subscriptionId,
                'reference_id' => $referenceId,
                'response' => $response,
            ]);
        } catch (Throwable $throwable) {
            Log::error('circle hosted page request failed', [
                'user_id' => $user->id,
                'circle_id' => $circle->id,
                'addon_code' => $addonCode,
                'subscription_id' => $subscriptionId,
                'reference_id' => $referenceId,
                'payload' => $payload,
                'error' => $throwable->getMessage(),
            ]);

            throw $throwable;
        }

        return [
            'hostedpage_id' => (string) data_get($response, 'hostedpage.hostedpage_id', ''),
            'checkout_url' => (string) data_get($response, 'hostedpage.url', ''),
            'customer_id' => $customerId,
            'subscription_id' => $subscriptionId,
            'raw' => $response,
        ];
    }

    private function resolveActiveBaseSubscription(User $user, string $customerId): ?array
    {
        $activeStatuses = ['live', 'active', 'non_renewing', 'trial'];

        $candidateSubscriptionId = trim((string) ($user->zoho_subscription_id ?? ''));

        if ($candidateSubscriptionId !== '') {
            try {
                $single = $this->getSubscription($candidateSubscriptionId);
                $singleSubscription = is_array($single['subscription'] ?? null) ? $single['subscription'] : $single;
                $singleStatus = strtolower((string) ($singleSubscription['status'] ?? ''));

                if (in_array($singleStatus, $activeStatuses, true)) {
                    return $singleSubscription;
                }
            } catch (Throwable $throwable) {
                Log::warning('failed to fetch user zoho subscription for circle checkout', [
                    'user_id' => $user->id,
                    'subscription_id' => $candidateSubscriptionId,
                    'message' => $throwable->getMessage(),
                ]);
            }
        }

        $list = $this->listSubscriptionsByCustomer($customerId);
        $subscriptions = data_get($list, 'subscriptions', []);

        if (! is_array($subscriptions)) {
            return null;
        }

        return collect($subscriptions)
            ->filter(fn ($subscription) => is_array($subscription))
            ->first(function (array $subscription) use ($activeStatuses, $user) {
                $status = strtolower((string) ($subscription['status'] ?? ''));
                $planCode = (string) (data_get($subscription, 'plan.plan_code') ?? $subscription['plan_code'] ?? '');

                if (! in_array($status, $activeStatuses, true)) {
                    return false;
                }

                if ((string) ($user->zoho_plan_code ?? '') === '') {
                    return true;
                }

                return $planCode === (string) $user->zoho_plan_code;
            });
    }


    private function resolveAddonAmount(array $addon): mixed
    {
        return data_get($addon, 'price_brackets.0.price')
            ?? $addon['price']
            ?? $addon['amount']
            ?? $addon['rate']
            ?? $addon['recurring_price']
            ?? data_get($addon, 'addon_price')
            ?? data_get($addon, 'price')
            ?? data_get($addon, 'amount')
            ?? data_get($addon, 'rate')
            ?? data_get($addon, 'recurring_price');
    }

    private function resolveAddonCurrency(array $addon): ?string
    {
        $currency = $addon['currency_code']
            ?? $addon['currency']
            ?? data_get($addon, 'currency_code')
            ?? data_get($addon, 'currency')
            ?? 'INR';

        $currency = is_string($currency) ? trim($currency) : (string) $currency;

        return $currency !== '' ? $currency : 'INR';
    }

    public function getOrganization(): array
    {
        $response = $this->client->request('GET', '/organizations');

        return $response['organizations'][0] ?? $response;
    }

    public function ensureCustomerForUser(User $user): string
    {
        $email = trim((string) ($user->email ?? ''));
        $phone = $this->resolveUserPhone($user);

        if ($email === '') {
            throw ValidationException::withMessages([
                'email' => 'User email missing',
            ]);
        }

        if ($phone === '') {
            throw ValidationException::withMessages([
                'phone' => 'User phone missing',
            ]);
        }

        $existingCustomerId = $this->findCustomerByEmail($email);

        if ($existingCustomerId !== null) {
            if ((string) $user->zoho_customer_id !== (string) $existingCustomerId) {
                $user->forceFill(['zoho_customer_id' => $existingCustomerId])->save();
            }

            $this->ensurePortalEnabled($user, $existingCustomerId, $email, $phone);

            return $existingCustomerId;
        }

        $name = trim((string) ($user->display_name ?: trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))));

        $payload = [
            'display_name' => $name !== '' ? $name : ($user->company_name ?: $email),
            'company_name' => $user->company_name,
            'email' => $email,
            'mobile' => $phone,
            'phone' => $phone,
            'is_portal_enabled' => true,
            'contact_persons' => [$this->buildPrimaryContactPerson($user, $email, $phone)],
            'billing_address' => [
                'city' => $user->city ?? '',
                'state' => '',
            ],
        ];

        $response = $this->client->request('POST', '/customers', $payload);
        $customerId = (string) ($response['customer']['customer_id'] ?? '');

        if ($customerId === '') {
            throw new RuntimeException('Unable to create Zoho customer.');
        }

        $user->forceFill(['zoho_customer_id' => $customerId])->save();

        $this->ensurePortalEnabled($user, $customerId, $email, $phone);

        return $customerId;
    }


    public function ensureCustomerForContact(array $contact): string
    {
        $email = trim((string) ($contact['email'] ?? ''));
        $phone = trim((string) ($contact['phone'] ?? ''));
        $name = trim((string) ($contact['name'] ?? ''));
        $company = trim((string) ($contact['company'] ?? ''));
        $city = trim((string) ($contact['city'] ?? ''));

        if ($email === '') {
            throw ValidationException::withMessages(['visitor_email' => 'Email is required for Zoho checkout.']);
        }

        if ($phone === '') {
            throw ValidationException::withMessages(['visitor_phone' => 'Phone is required for Zoho checkout.']);
        }

        $existingCustomerId = $this->findCustomerByEmail($email);
        if ($existingCustomerId !== null) {
            return (string) $existingCustomerId;
        }

        $payload = [
            'display_name' => $name !== '' ? $name : ($company !== '' ? $company : $email),
            'company_name' => $company,
            'email' => $email,
            'mobile' => $phone,
            'phone' => $phone,
            'is_portal_enabled' => true,
            'contact_persons' => [[
                'first_name' => $name !== '' ? $name : 'Unity',
                'last_name' => '',
                'email' => $email,
                'phone' => $phone,
                'mobile' => $phone,
                'is_primary_contact' => true,
            ]],
            'billing_address' => [
                'city' => $city,
                'state' => '',
            ],
        ];

        $response = $this->client->request('POST', '/customers', $payload);
        $customerId = (string) ($response['customer']['customer_id'] ?? '');

        if ($customerId === '') {
            throw new RuntimeException('Unable to create Zoho customer.');
        }

        return $customerId;
    }

    public function createInvoiceForEventRegistration(array $customer, array $eventInvoice): array
    {
        $user = $customer['user'] ?? null;
        $customerId = $user instanceof User
            ? $this->ensureCustomerForUser($user)
            : $this->ensureCustomerForContact($customer);

        $registrationId = (string) ($eventInvoice['registration_id'] ?? '');
        $amount = round((float) ($eventInvoice['amount'] ?? 0), 2);
        $currency = strtoupper((string) ($eventInvoice['currency'] ?? 'INR'));
        $eventTitle = trim((string) ($eventInvoice['event_title'] ?? 'Unity Event'));
        $description = trim((string) ($eventInvoice['description'] ?? $eventTitle));

        if ($registrationId === '' || $amount <= 0) {
            throw new RuntimeException('Invalid event registration invoice payload.');
        }

        $payload = [
            'customer_id' => $customerId,
            'date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'payment_terms' => 0,
            'currency_code' => $currency,
            'reference_number' => $registrationId,
            'invoice_items' => [[
                'name' => 'Event Registration - '.$eventTitle,
                'description' => $description,
                'rate' => $amount,
                'price' => $amount,
                'quantity' => 1,
            ]],
            'notes' => 'Razorpay paid event registration: '.$registrationId,
        ];

        Log::info('event zoho invoice request payload', [
            'event_registration_id' => $registrationId,
            'customer_id' => $customerId,
            'payload_keys' => array_keys($payload),
            'amount' => $amount,
            'currency' => $currency,
        ]);

        $response = $this->client->request('POST', '/invoices', $payload);
        $invoice = is_array($response['invoice'] ?? null) ? $response['invoice'] : $response;

        Log::info('event zoho invoice response shape', [
            'event_registration_id' => $registrationId,
            'response_keys' => array_keys($response),
            'invoice_keys' => is_array($invoice) ? array_keys($invoice) : [],
        ]);

        $invoiceId = (string) data_get($invoice, 'invoice_id', '');
        if ($invoiceId === '') {
            throw new RuntimeException('Unable to create Zoho invoice for event registration.');
        }

        return [
            'customer_id' => $customerId,
            'invoice_id' => $invoiceId,
            'invoice_number' => (string) (data_get($invoice, 'invoice_number') ?? data_get($invoice, 'number') ?? ''),
            'invoice_url' => data_get($invoice, 'invoice_url') ?? data_get($invoice, 'url'),
            'invoice_pdf_url' => data_get($invoice, 'invoice_pdf_url') ?? data_get($invoice, 'pdf_url'),
            'raw' => $response,
        ];
    }

    public function createHostedPageForSubscription(User $user, string $planCode): array
    {
        $customerId = $this->ensureCustomerForUser($user);

        $response = $this->client->request('POST', '/hostedpages/newsubscription', [
            'customer_id' => $customerId,
            'plan' => [
                'plan_code' => $planCode,
            ],
        ]);

        $bodyKeys = array_keys(is_array($response) ? $response : []);
        $hostedPage = data_get($response, 'hostedpage', []);
        $hostedPage = is_array($hostedPage) ? $hostedPage : [];

        Log::info('ZOHO_NEW_SUBSCRIPTION_RESPONSE_IN_SERVICE', [
            'response_keys' => $bodyKeys,
            'hostedpage_keys' => array_keys($hostedPage),
            'hostedpage_url' => data_get($response, 'hostedpage.url'),
            'hostedpage_id' => data_get($response, 'hostedpage.hostedpage_id'),
        ]);

        $checkoutUrl = (string) data_get($response, 'hostedpage.url', '');
        $hostedPageId = (string) data_get($response, 'hostedpage.hostedpage_id', '');

        if ($checkoutUrl === '' || $hostedPageId === '') {
            $zohoCode = (string) data_get($response, 'code', '');
            $zohoMessage = (string) data_get($response, 'message', data_get($response, 'error.message', 'Failed to generate checkout URL.'));

            Log::error('ZOHO_NEW_SUBSCRIPTION_NO_URL', [
                'response' => $response,
                'customer_id' => $customerId,
                'plan_code' => $planCode,
                'body_keys' => $bodyKeys,
                'hostedpage_keys' => array_keys($hostedPage),
                'zoho_code' => $zohoCode,
                'zoho_message' => $zohoMessage,
            ]);

            $formattedCode = $zohoCode !== '' ? ' code ' . $zohoCode : '';
            throw new RuntimeException('Failed to generate checkout URL' . $formattedCode . ': ' . $zohoMessage);
        }

        return [
            'hostedpage_id' => $hostedPageId,
            'checkout_url' => $checkoutUrl,
        ];
    }

    public function getHostedPage(string $hostedpageId): array
    {
        $response = $this->client->request('GET', '/hostedpages/' . $hostedpageId);
        $normalized = $this->normalizeHostedPageResponse($response);

        Log::info('Zoho hosted page response shape', [
            'hostedpage_id' => $hostedpageId,
            'response_keys' => array_keys($response),
            'hostedpage_keys' => array_keys($normalized['hostedpage'] ?? []),
        ]);

        return $normalized;
    }

    public function getSubscription(string $subscriptionId): array
    {
        return $this->client->request('GET', '/subscriptions/' . $subscriptionId);
    }

    public function getInvoice(string $invoiceId): array
    {
        return $this->client->request('GET', '/invoices/' . $invoiceId);
    }

    public function listInvoicesBySubscription(string $subscriptionId, int $page = 1, int $perPage = 10): array
    {
        return $this->client->request('GET', '/invoices', [
            'subscription_id' => $subscriptionId,
            'page' => max(1, $page),
            'per_page' => max(1, $perPage),
        ], true);
    }

    public function hasUserZohoMapping(User $user): bool
    {
        return trim((string) ($user->zoho_customer_id ?? '')) !== ''
            || trim((string) ($user->zoho_subscription_id ?? '')) !== '';
    }

    public function listInvoicesForUser(User $user, int $page = 1, int $perPage = 20): array
    {
        $customerId = trim((string) ($user->zoho_customer_id ?? ''));
        $subscriptionId = trim((string) ($user->zoho_subscription_id ?? ''));

        if ($customerId !== '') {
            return $this->client->request('GET', '/invoices', [
                'customer_id' => $customerId,
                'page' => max(1, $page),
                'per_page' => max(1, $perPage),
                'sort_column' => 'created_time',
                'sort_order' => 'D',
            ], true);
        }

        if ($subscriptionId !== '') {
            return $this->listInvoicesBySubscription($subscriptionId, $page, $perPage);
        }

        return [
            'invoices' => [],
            'page_context' => [
                'page' => max(1, $page),
                'per_page' => max(1, $perPage),
                'has_more_page' => false,
                'total' => 0,
            ],
        ];
    }

    public function getInvoiceForUser(User $user, string $invoiceId): ?array
    {
        $customerId = trim((string) ($user->zoho_customer_id ?? ''));
        $subscriptionId = trim((string) ($user->zoho_subscription_id ?? ''));

        $invoiceResponse = $this->getInvoice($invoiceId);
        $invoice = is_array($invoiceResponse['invoice'] ?? null) ? $invoiceResponse['invoice'] : $invoiceResponse;

        if (! is_array($invoice) || $invoice === []) {
            return null;
        }

        $invoiceCustomerId = trim((string) ($invoice['customer_id'] ?? ''));
        $invoiceSubscriptionId = trim((string) ($invoice['subscription_id'] ?? ''));

        if ($customerId !== '' && $invoiceCustomerId !== '' && $customerId === $invoiceCustomerId) {
            return $invoice;
        }

        if ($subscriptionId !== '' && $invoiceSubscriptionId !== '' && $subscriptionId === $invoiceSubscriptionId) {
            return $invoice;
        }

        return null;
    }

    public function getInvoicePdfForUser(User $user, string $invoiceId): ?array
    {
        $invoice = $this->getInvoiceForUser($user, $invoiceId);

        if (! is_array($invoice)) {
            return null;
        }

        $pdf = $this->client->requestPdf('/invoices/' . $invoiceId, ['accept' => 'pdf']);

        return [
            'content' => $pdf['content'] ?? '',
            'content_type' => $pdf['content_type'] ?? 'application/pdf',
            'invoice_number' => (string) ($invoice['invoice_number'] ?? $invoiceId),
        ];
    }

    public function listSubscriptionsByCustomer(string $customerId): array
    {
        return $this->client->request('GET', '/subscriptions', [
            'customer_id' => $customerId,
            'sort_column' => 'created_time',
            'sort_order' => 'D',
        ], true);
    }

    public function parseHostedPageForMembership(array $hostedPageResponse): array
    {
        $hostedPage = $hostedPageResponse['hostedpage'] ?? [];
        $subscription = $hostedPage['subscription'] ?? [];

        $status = strtolower((string) (
            $hostedPage['payment_status']
            ?? $hostedPage['status']
            ?? $subscription['status']
            ?? ''
        ));

        $isPaid = in_array($status, ['paid', 'success', 'completed', 'active', 'payment_success'], true);

        $planCode = $subscription['plan']['plan_code']
            ?? data_get($hostedPage, 'plan.plan_code')
            ?? data_get($hostedPage, 'plan_code');

        $startsAt = $subscription['start_date']
            ?? $subscription['activated_at']
            ?? $subscription['created_time']
            ?? now()->toDateTimeString();

        $endsAt = $subscription['next_billing_at']
            ?? $subscription['current_term_ends_at']
            ?? $subscription['current_term_end']
            ?? data_get($hostedPage, 'subscription.next_billing_at')
            ?? $this->calculateMembershipEndAt(
                (string) ($subscription['interval'] ?? data_get($hostedPage, 'plan.interval') ?? ''),
                $startsAt,
            );

        return [
            'status' => $status,
            'is_paid' => $isPaid,
            'subscription_id' => $subscription['subscription_id'] ?? data_get($hostedPage, 'subscription_id'),
            'invoice_id' => data_get($hostedPage, 'invoice.invoice_id')
                ?? data_get($subscription, 'invoice_id')
                ?? data_get($hostedPage, 'invoice_id'),
            'plan_code' => $planCode,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ];
    }

    public function syncMembershipFromHostedPage(User $user, array $hostedPageResponse): bool
    {
        $hostedPage = $hostedPageResponse['hostedpage'] ?? [];
        $subscription = $hostedPage['subscription'] ?? [];

        $status = strtolower((string) ($subscription['status'] ?? $hostedPage['status'] ?? ''));
        $isPaid = in_array($status, ['active', 'live', 'paid', 'payment_success', 'success'], true);

        if (! $isPaid && ! isset($subscription['subscription_id'])) {
            return false;
        }

        $applied = $this->membershipUpdater->applyPaidMembership($user, [
            'zoho_subscription_id' => $subscription['subscription_id'] ?? null,
            'zoho_plan_code' => $subscription['plan']['plan_code'] ?? null,
            'zoho_last_invoice_id' => $hostedPage['invoice']['invoice_id'] ?? ($subscription['invoice_id'] ?? null),
            'membership_starts_at' => $subscription['start_date'] ?? $subscription['created_time'] ?? null,
            'membership_ends_at' => $subscription['next_billing_at'] ?? $subscription['expires_at'] ?? null,
            'last_payment_at' => now(),
        ]);

        if ($applied) {
            $this->triggerMembershipWelcomeEmailAfterPaidActivation($user, 'zoho_hosted_page_sync');
        }

        return $applied;
    }

    public function applyWebhookEvent(array $event): bool
    {
        $eventType = strtolower((string) ($event['event_type'] ?? $event['eventType'] ?? ''));
        $payload = $event['payload'] ?? $event['data'] ?? $event;

        if (is_string($payload)) {
            $decodedPayload = json_decode($payload, true);
            $payload = is_array($decodedPayload) ? $decodedPayload : [];
        }

        if (! is_array($payload)) {
            $payload = [];
        }

        if (isset($payload['subscription']) && is_array($payload['subscription'])) {
            $sub = $payload['subscription'];
            $subscriptionId = (string) ($sub['subscription_id'] ?? '');
            $customerId = trim((string) ($sub['customer_id'] ?? ''));

            if ($subscriptionId === '') {
                Log::warning('Zoho webhook subscription payload missing subscription_id');

                return false;
            }

            $planCode = (string) ($sub['plan_code'] ?? data_get($sub, 'plan.plan_code') ?? '');
            $status = strtolower((string) ($sub['status'] ?? ''));
            $startsAt = $sub['start_date'] ?? $sub['created_time'] ?? now()->toDateTimeString();
            $endsAt = $sub['next_billing_at']
                ?? $sub['current_term_ends_at']
                ?? $sub['current_term_end']
                ?? null;

            if ($customerId === '') {
                try {
                    $full = $this->getSubscription($subscriptionId);
                    $subscription = is_array($full['subscription'] ?? null) ? $full['subscription'] : $full;

                    $customerId = trim((string) ($subscription['customer_id'] ?? ''));
                    $planCode = (string) (data_get($subscription, 'plan.plan_code') ?? $subscription['plan_code'] ?? $planCode ?? '');
                    $status = strtolower((string) ($subscription['status'] ?? $status));
                    $startsAt = $subscription['start_date'] ?? $subscription['created_time'] ?? $startsAt;
                    $endsAt = $subscription['next_billing_at']
                        ?? $subscription['current_term_ends_at']
                        ?? $subscription['current_term_end']
                        ?? $endsAt;

                    Log::info('Zoho webhook subscription enriched', [
                        'subscription_id' => $subscriptionId,
                        'customer_id' => $customerId,
                        'plan_code' => $planCode,
                        'status' => $status,
                    ]);
                } catch (\Throwable $throwable) {
                    Log::warning('Zoho webhook subscription enrich failed', [
                        'subscription_id' => $subscriptionId,
                        'message' => $throwable->getMessage(),
                    ]);
                }
            }

            Log::info('Zoho webhook parsed', [
                'event_type' => $eventType,
                'customer_id' => $customerId,
                'subscription_id' => $subscriptionId,
                'status' => $status,
                'plan_code' => $planCode,
            ]);

            $isPaidStatus = in_array($status, ['live', 'active', 'paid', 'success', 'payment_success', 'completed'], true);

            if (! $isPaidStatus) {
                Log::info('Zoho webhook subscription ignored due to non-paid status', [
                    'subscription_id' => $subscriptionId,
                    'customer_id' => $customerId,
                    'status' => $status,
                ]);

                return false;
            }

            $user = User::query()
                ->where(function ($query) use ($customerId, $subscriptionId) {
                    if ($customerId !== '') {
                        $query->orWhere('zoho_customer_id', $customerId);
                    }

                    $query->orWhere('zoho_subscription_id', $subscriptionId);
                })
                ->first();

            if (! $user) {
                Log::warning('Zoho webhook user not found', [
                    'event_type' => $eventType,
                    'customer_id' => $customerId,
                    'subscription_id' => $subscriptionId,
                    'plan_code' => $planCode,
                    'status' => $status,
                ]);

                return false;
            }

            $applied = $this->membershipUpdater->applyPaidMembership($user, [
                'zoho_customer_id' => $customerId !== '' ? $customerId : $user->zoho_customer_id,
                'zoho_subscription_id' => $subscriptionId,
                'zoho_plan_code' => $planCode !== '' ? $planCode : $user->zoho_plan_code,
                'zoho_last_invoice_id' => null,
                'membership_starts_at' => $startsAt,
                'membership_ends_at' => $endsAt,
                'last_payment_at' => now(),
            ]);

            if ($applied) {
                $this->triggerMembershipWelcomeEmailAfterPaidActivation($user, 'zoho_webhook_subscription_payload');
            }

            return $applied;
        }

        $customerId = Arr::get($payload, 'customer.customer_id')
            ?? Arr::get($payload, 'subscription.customer_id')
            ?? Arr::get($payload, 'invoice.customer_id')
            ?? Arr::get($payload, 'customer_id');

        $subscriptionId = Arr::get($payload, 'subscription.subscription_id')
            ?? Arr::get($payload, 'subscription_id')
            ?? Arr::get($payload, 'invoice.subscription_id');

        $planCode = Arr::get($payload, 'subscription.plan.plan_code')
            ?? Arr::get($payload, 'plan.plan_code')
            ?? Arr::get($payload, 'plan_code');

        $status = strtolower((string) (
            Arr::get($payload, 'subscription.status')
            ?? Arr::get($payload, 'invoice.status')
            ?? Arr::get($payload, 'status')
            ?? ''
        ));

        Log::info('Zoho webhook parsed', [
            'event_type' => $eventType,
            'customer_id' => $customerId,
            'subscription_id' => $subscriptionId,
            'status' => $status,
            'plan_code' => $planCode,
        ]);

        $user = $this->resolveUserByZoho($customerId, $subscriptionId);

        if (! $user) {
            Log::warning('Zoho webhook user not found', [
                'event_type' => $eventType,
                'customer_id' => $customerId,
                'subscription_id' => $subscriptionId,
            ]);

            return false;
        }

        $invoiceId = Arr::get($payload, 'invoice.invoice_id') ?? Arr::get($payload, 'invoice_id');

        if (in_array($eventType, ['invoice_created'], true) && $invoiceId) {
            $user->forceFill(['zoho_last_invoice_id' => $invoiceId])->save();
        }

        if (in_array($eventType, [
            'payment_thankyou',
            'subscription_created',
            'subscription_activation',
            'subscription_activated',
            'invoice_paid',
            'payment_success',
        ], true)) {
            $applied = $this->membershipUpdater->applyPaidMembership($user, [
                'zoho_customer_id' => $customerId,
                'zoho_subscription_id' => $subscriptionId,
                'zoho_plan_code' => $planCode,
                'zoho_last_invoice_id' => $invoiceId,
                'membership_starts_at' => Arr::get($payload, 'subscription.start_date')
                    ?? Arr::get($payload, 'subscription.current_term_starts_at')
                    ?? Arr::get($payload, 'subscription.created_time')
                    ?? now(),
                'membership_ends_at' => Arr::get($payload, 'subscription.next_billing_at')
                    ?? Arr::get($payload, 'subscription.current_term_ends_at'),
                'last_payment_at' => now(),
            ]);

            if ($applied) {
                $this->triggerMembershipWelcomeEmailAfterPaidActivation($user, 'zoho_webhook_event_type');
            }

            return $applied;
        }

        return false;
    }

    private function triggerMembershipWelcomeEmailAfterPaidActivation(User $user, string $source): void
    {
        $freshUser = User::query()->find($user->id);

        if (! $freshUser) {
            Log::warning('membership.welcome_email.auto_hook_user_not_found', [
                'source' => $source,
                'user_id' => (string) $user->id,
            ]);

            return;
        }

        try {
            Log::info('membership.welcome_email.auto_hook_reached', [
                'source' => $source,
                'user_id' => (string) $freshUser->id,
                'email' => (string) ($freshUser->email ?? ''),
                'membership_status' => (string) ($freshUser->membership_status ?? ''),
                'zoho_subscription_id' => (string) ($freshUser->zoho_subscription_id ?? ''),
                'zoho_plan_code' => (string) ($freshUser->zoho_plan_code ?? ''),
            ]);

            $result = $this->membershipWelcomeEmailService->sendIfEligible($freshUser);

            Log::info('membership.welcome_email.auto_hook_result', [
                'source' => $source,
                'user_id' => (string) $freshUser->id,
                'sent' => (bool) ($result['sent'] ?? false),
                'reason' => (string) ($result['reason'] ?? 'unknown'),
            ]);
        } catch (Throwable $throwable) {
            Log::warning('membership.welcome_email.auto_hook_exception', [
                'source' => $source,
                'user_id' => (string) $freshUser->id,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    private function findCustomerByEmail(string $email): ?string
    {
        $response = $this->client->request('GET', '/customers', ['email' => $email], true);
        $customers = $response['customers'] ?? [];

        foreach ($customers as $customer) {
            if (strtolower((string) ($customer['email'] ?? '')) === strtolower($email)) {
                return $customer['customer_id'] ?? null;
            }
        }

        return null;
    }

    private function ensurePortalEnabled(User $user, string $customerId, string $email, string $phone): void
    {
        $this->client->request('PUT', '/customers/' . $customerId, [
            'is_portal_enabled' => true,
            'email' => $email,
            'mobile' => $phone,
            'phone' => $phone,
            'contact_persons' => [$this->buildPrimaryContactPerson($user, $email, $phone)],
        ]);
    }

    private function buildPrimaryContactPerson(User $user, string $email, string $phone): array
    {
        return [
            'first_name' => $user->first_name ?: ($user->display_name ?: 'Unity'),
            'last_name' => $user->last_name ?: '',
            'email' => $email,
            'phone' => $phone,
            'mobile' => $phone,
            'is_primary_contact' => true,
        ];
    }

    private function normalizeHostedPageResponse(array $resp): array
    {
        $data = $resp['data'] ?? [];
        $hostedPage = $resp['hostedpage']
            ?? ($data['hostedpage'] ?? null)
            ?? $data
            ?? [];

        return [
            'raw' => $resp,
            'hostedpage' => is_array($hostedPage) ? $hostedPage : [],
        ];
    }

    private function calculateMembershipEndAt(string $interval, string $startsAt): ?string
    {
        $start = now();

        try {
            $start = \Illuminate\Support\Carbon::parse($startsAt);
        } catch (\Throwable) {
            $start = now();
        }

        $normalized = strtolower(trim($interval));

        return match (true) {
            str_contains($normalized, 'year'),
            str_contains($normalized, 'annual') => $start->copy()->addYear()->toDateTimeString(),
            str_contains($normalized, 'quarter') => $start->copy()->addMonths(3)->toDateTimeString(),
            str_contains($normalized, 'month') => $start->copy()->addMonth()->toDateTimeString(),
            default => null,
        };
    }

    private function resolveUserPhone(User $user): string
    {
        $phone = trim((string) ($user->phone ?? $user->getAttribute('mobile') ?? ''));

        if ($phone !== '') {
            return $phone;
        }

        return trim((string) (data_get($user->toArray(), 'mobile') ?? ''));
    }

    private function resolveUserByZoho(?string $customerId, ?string $subscriptionId): ?User
    {
        return User::query()
            ->when($customerId, fn ($query) => $query->orWhere('zoho_customer_id', $customerId))
            ->when($subscriptionId, fn ($query) => $query->orWhere('zoho_subscription_id', $subscriptionId))
            ->first();
    }
}
