<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Controller;
use App\Mail\MembershipPurchaseCongratulationsMail;
use App\Models\CircleSubscription;
use App\Models\CircleJoinRequest;
use App\Models\EmailLog;
use App\Models\User;
use App\Services\Billing\MembershipSyncService;
use App\Services\EmailLogs\EmailLogService;
use App\Services\Circles\CircleJoinRequestPaymentSyncService;
use App\Services\Membership\MembershipWelcomeEmailService;
use App\Services\Circles\PaidCircleMembershipFinalizer;
use App\Support\Zoho\ZohoBillingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ZohoBillingWebhookController extends Controller
{
    public function __construct(
        private readonly ZohoBillingService $zohoBillingService,
        private readonly MembershipSyncService $membershipSyncService,
        private readonly CircleJoinRequestPaymentSyncService $circleJoinRequestPaymentSyncService,
        private readonly MembershipWelcomeEmailService $membershipWelcomeEmailService,
        private readonly PaidCircleMembershipFinalizer $paidCircleMembershipFinalizer,
    ) {
    }

    public function handle(Request $request)
    {
        if (! $this->isValidWebhook($request)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $payload = $request->all();

        $subscriptionId = data_get($payload, 'subscription.subscription_id')
            ?? data_get($payload, 'data.subscription.subscription_id')
            ?? data_get($payload, 'subscription_id');
        $invoiceId = data_get($payload, 'invoice.invoice_id')
            ?? data_get($payload, 'data.invoice.invoice_id')
            ?? data_get($payload, 'invoice_id');
        $customerId = data_get($payload, 'customer.customer_id')
            ?? data_get($payload, 'data.customer.customer_id')
            ?? data_get($payload, 'customer_id');
        $email = data_get($payload, 'customer.email')
            ?? data_get($payload, 'data.customer.email')
            ?? data_get($payload, 'email');

        $user = User::query()
            ->when($subscriptionId, fn ($q) => $q->orWhere('zoho_subscription_id', $subscriptionId))
            ->when($customerId, fn ($q) => $q->orWhere('zoho_customer_id', $customerId))
            ->when($email, fn ($q) => $q->orWhere('email', $email))
            ->first();

        if (! $user) {
            Log::warning('Zoho webhook user not found', [
                'subscription_id' => $subscriptionId,
                'customer_id' => $customerId,
                'email_masked' => $this->maskEmail($email),
            ]);

            return response()->json(['success' => true, 'message' => 'No matching user']);
        }

        try {
            $subscription = [];
            $invoice = [];

            if ($subscriptionId) {
                $subscriptionResp = $this->zohoBillingService->getSubscription($subscriptionId);
                $subscription = $subscriptionResp['subscription'] ?? $subscriptionResp;
            }

            if ($invoiceId) {
                $invoiceResp = $this->zohoBillingService->getInvoice($invoiceId);
                $invoice = $invoiceResp['invoice'] ?? $invoiceResp;
            } elseif ($subscriptionId) {
                $invoiceList = $this->zohoBillingService->listInvoicesBySubscription($subscriptionId);
                $invoice = ($invoiceList['invoices'][0] ?? []);
            }

            $syncedUser = $this->membershipSyncService->syncUserMembershipFromZoho($user, [
                'subscription' => $subscription,
                'invoice' => $invoice,
            ]);

            $this->membershipWelcomeEmailService->sendIfEligible($syncedUser);
            $this->sendMembershipPurchaseCongratulationsEmail($syncedUser);

            return response()->json(['success' => true]);
        } catch (Throwable $throwable) {
            Log::error('Zoho webhook sync failed', [
                'user_id' => $user->id,
                'subscription_id' => $subscriptionId,
                'invoice_id' => $invoiceId,
                'error' => $throwable->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => 'Webhook sync failed'], 500);
        }
    }


    private function sendMembershipPurchaseCongratulationsEmail(User $user): void
    {
        if ((string) ($user->membership_status ?? '') !== 'active') {
            return;
        }

        if (EmailLog::query()
            ->where('user_id', (string) $user->id)
            ->where('template_key', 'membership_purchase_congratulations')
            ->exists()) {
            return;
        }

        $mailable = new MembershipPurchaseCongratulationsMail($user);

        try {
            Mail::to($user->email)->send($mailable);

            app(EmailLogService::class)->logMailableSent($mailable, [
                'user_id' => (string) $user->id,
                'to_email' => (string) $user->email,
                'to_name' => (string) ($user->display_name ?: trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))),
                'template_key' => 'membership_purchase_congratulations',
                'source_module' => 'billing',
                'related_type' => 'user',
                'related_id' => (string) $user->id,
                'payload' => [
                    'flow' => 'zoho_membership_webhook',
                    'membership_status' => (string) ($user->membership_status ?? ''),
                ],
            ]);
        } catch (Throwable $exception) {
            app(EmailLogService::class)->logMailableFailed($mailable, [
                'user_id' => (string) $user->id,
                'to_email' => (string) $user->email,
                'to_name' => (string) ($user->display_name ?: trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))),
                'template_key' => 'membership_purchase_congratulations',
                'source_module' => 'billing',
                'related_type' => 'user',
                'related_id' => (string) $user->id,
                'payload' => [
                    'flow' => 'zoho_membership_webhook',
                    'membership_status' => (string) ($user->membership_status ?? ''),
                ],
            ], $exception);

            Log::warning('billing.membership_purchase_congratulations_email_failed', [
                'user_id' => (string) $user->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    public function handleCircleSubscription(Request $request)
    {
        if (! $this->isValidWebhook($request)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $payload = $request->all();

        Log::info('circle customer payment webhook received', [
            'payload' => $payload,
        ]);

        try {
            // Customer Payment module is the primary activation trigger.
            // Addon module payloads are insufficient for reliable payment activation.
            $payloadType = $this->detectCircleWebhookPayloadType($payload);

            Log::info('circle webhook payload type detected', [
                'payload_type' => $payloadType,
            ]);

            $identifiers = $this->extractCircleWebhookIdentifiers($payload);

            Log::info('circle webhook extracted identifiers', $identifiers);

            $paymentStatus = strtolower((string) ($identifiers['payment_status'] ?? ''));
            if ($paymentStatus !== '' && ! in_array($paymentStatus, ['paid', 'success', 'completed', 'payment_success'], true)) {
                Log::info('unsupported/insufficient payload for activation', [
                    'reason' => 'non-success payment status',
                    'payment_status' => $paymentStatus,
                    'payload_type' => $payloadType,
                ]);

                return response()->json(['success' => true, 'message' => 'Ignored non-success payment status']);
            }

            $subscription = $this->findPendingCircleSubscription($identifiers);

            if (! $subscription) {
                Log::warning('webhook received but no match found', [
                    'payload_type' => $payloadType,
                    'identifiers' => $identifiers,
                ]);

                return response()->json(['success' => true, 'message' => 'No matching circle subscription']);
            }

            Log::info('pending circle subscription matched', [
                'circle_subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'circle_id' => $subscription->circle_id,
            ]);

            $incomingPaymentId = (string) ($identifiers['payment_id'] ?? '');
            if (
                $subscription->status === 'active'
                && ($incomingPaymentId === '' || (string) ($subscription->zoho_payment_id ?? '') === $incomingPaymentId)
            ) {
                $paidAt = $subscription->paid_at ?? now();
                $startedAt = $subscription->started_at ?? $paidAt;
                $expiresAt = $subscription->expires_at ?? $startedAt->copy()->addMonths(max(1, (int) ($subscription->circle?->circle_duration_months ?: 12)));
                $this->finalizePaidCircleJoin($subscription->user, $subscription, $paidAt, $startedAt, $expiresAt);

                Log::info('duplicate/idempotent webhook ignored', [
                    'circle_subscription_id' => $subscription->id,
                    'incoming_payment_id' => $incomingPaymentId,
                ]);

                return response()->json(['success' => true, 'message' => 'Already processed']);
            }

            $startedAt = $this->parseDateTimeValue($identifiers['paid_at'] ?? null) ?? now();
            $paidAt = $this->parseDateTimeValue($identifiers['paid_at'] ?? null) ?? now();
            $durationMonths = (int) ($subscription->circle?->circle_duration_months ?: 12);
            $expiresAt = $startedAt->copy()->addMonths(max(1, $durationMonths));

            DB::transaction(function () use ($subscription, $identifiers, $startedAt, $paidAt, $expiresAt, $payload): void {
                $subscription->forceFill([
                    'status' => 'active',
                    'zoho_customer_id' => $identifiers['customer_id'] ?: $subscription->zoho_customer_id,
                    'zoho_subscription_id' => $identifiers['subscription_id'] ?: $subscription->zoho_subscription_id,
                    'zoho_payment_id' => $identifiers['payment_id'] ?: $subscription->zoho_payment_id,
                    'zoho_hosted_page_id' => $identifiers['hostedpage_id'] ?: $subscription->zoho_hosted_page_id,
                    'started_at' => $startedAt,
                    'paid_at' => $paidAt,
                    'expires_at' => $expiresAt,
                    'raw_webhook_payload' => $payload,
                ])->save();

                Log::info('circle subscription activated', [
                    'circle_subscription_id' => $subscription->id,
                    'payment_id' => $subscription->zoho_payment_id,
                    'subscription_id' => $subscription->zoho_subscription_id,
                ]);

                $user = $subscription->user;
                $user->forceFill([
                    'active_circle_id' => $subscription->circle_id,
                    'active_circle_subscription_id' => $subscription->id,
                    'circle_joined_at' => $startedAt,
                    'circle_expires_at' => $expiresAt,
                    'active_circle_addon_code' => $subscription->zoho_addon_code,
                    'active_circle_addon_name' => $subscription->zoho_addon_name,
                ])->save();

                Log::info('user active circle snapshot updated for compatibility', [
                    'user_id' => $user->id,
                    'circle_id' => $subscription->circle_id,
                ]);

                $this->finalizePaidCircleJoin($user, $subscription, $paidAt, $startedAt, $expiresAt);
                $this->circleJoinRequestPaymentSyncService->updateUserCircleMembershipTier($user);
            });

            return response()->json(['success' => true]);
        } catch (Throwable $throwable) {
            Log::error('circle webhook failed', [
                'message' => $throwable->getMessage(),
            ]);

            return response()->json(['success' => true, 'message' => 'Webhook received']);
        }
    }

    private function detectCircleWebhookPayloadType(array $payload): string
    {
        if (is_array(data_get($payload, 'customerpayment')) || is_array(data_get($payload, 'data.customerpayment'))) {
            return 'customerpayment';
        }

        if (is_array(data_get($payload, 'payment')) || is_array(data_get($payload, 'data.payment'))) {
            return 'payment';
        }

        if (is_array(data_get($payload, 'invoice_payment')) || is_array(data_get($payload, 'data.invoice_payment'))) {
            return 'invoice_payment';
        }

        if (is_array(data_get($payload, 'invoice')) || is_array(data_get($payload, 'data.invoice'))) {
            return 'invoice';
        }

        if (is_array(data_get($payload, 'subscription')) || is_array(data_get($payload, 'data.subscription'))) {
            return 'subscription';
        }

        if (is_array(data_get($payload, 'addon')) || is_array(data_get($payload, 'data.addon'))) {
            return 'addon';
        }

        return 'unknown';
    }

    private function extractCircleWebhookIdentifiers(array $payload): array
    {
        $paymentInvoice = data_get($payload, 'payment.invoices.0')
            ?? data_get($payload, 'data.payment.invoices.0')
            ?? [];

        return [
            'customer_id' => $this->firstString($payload, [
                'customer.customer_id', 'data.customer.customer_id',
                'customerpayment.customer_id', 'data.customerpayment.customer_id',
                'payment.customer_id', 'data.payment.customer_id',
                'invoice.customer_id', 'data.invoice.customer_id',
                'customer_id',
            ]),
            'payment_id' => $this->firstString($payload, [
                'customerpayment.payment_id', 'data.customerpayment.payment_id',
                'payment.payment_id', 'data.payment.payment_id',
                'invoice_payment.payment_id', 'data.invoice_payment.payment_id',
                'payment_id',
            ]),
            'payment_number' => $this->firstString($payload, [
                'customerpayment.payment_number', 'data.customerpayment.payment_number',
                'payment.payment_number', 'data.payment.payment_number',
                'payment_number',
            ]),
            'invoice_id' => $this->firstString($payload, [
                'payment.invoices.0.invoice_id', 'data.payment.invoices.0.invoice_id',
                'invoice.invoice_id', 'data.invoice.invoice_id',
                'customerpayment.invoice_id', 'data.customerpayment.invoice_id',
                'invoice_payment.invoice_id', 'data.invoice_payment.invoice_id',
                'invoice_id',
            ]),
            'invoice_number' => $this->firstString($payload, [
                'payment.invoices.0.invoice_number', 'data.payment.invoices.0.invoice_number',
                'invoice.invoice_number', 'data.invoice.invoice_number', 'invoice_number',
            ]),
            'subscription_id' => $this->firstString($payload, [
                'payment.invoices.0.subscription_ids.0', 'data.payment.invoices.0.subscription_ids.0',
                'subscription.subscription_id', 'data.subscription.subscription_id',
                'customerpayment.subscription_id', 'data.customerpayment.subscription_id',
                'payment.subscription_id', 'data.payment.subscription_id',
                'invoice.subscription_id', 'data.invoice.subscription_id',
                'subscription_id',
            ]),
            'reference_id' => $this->firstString($payload, [
                'reference_id', 'data.reference_id',
                'customerpayment.reference_id', 'data.customerpayment.reference_id',
                'payment.reference_id', 'data.payment.reference_id',
                'invoice.reference_id', 'data.invoice.reference_id',
                'subscription.reference_id', 'data.subscription.reference_id',
            ]),
            'amount' => data_get($payload, 'customerpayment.amount')
                ?? data_get($payload, 'data.customerpayment.amount')
                ?? data_get($payload, 'payment.amount')
                ?? data_get($payload, 'data.payment.amount')
                ?? data_get($payload, 'payment.invoices.0.amount_applied')
                ?? data_get($payload, 'data.payment.invoices.0.amount_applied')
                ?? data_get($payload, 'invoice.total')
                ?? data_get($payload, 'data.invoice.total')
                ?? data_get($payload, 'amount'),
            'amount_applied' => data_get($payload, 'payment.invoices.0.amount_applied')
                ?? data_get($payload, 'data.payment.invoices.0.amount_applied'),
            'currency_code' => $this->firstString($payload, [
                'customerpayment.currency_code', 'data.customerpayment.currency_code',
                'payment.currency_code', 'data.payment.currency_code',
                'invoice.currency_code', 'data.invoice.currency_code',
                'currency_code', 'currency',
            ]),
            'payment_status' => strtolower($this->firstString($payload, [
                'customerpayment.payment_status', 'data.customerpayment.payment_status',
                'payment.payment_status', 'data.payment.payment_status',
                'payment.status', 'data.payment.status',
                'invoice_payment.status', 'data.invoice_payment.status',
                'invoice.status', 'data.invoice.status',
                'payment_status', 'status',
            ]) ?: ''),
            'paid_at' => $this->firstString($payload, [
                'payment.date', 'data.payment.date',
                'customerpayment.payment_time', 'data.customerpayment.payment_time',
                'customerpayment.paid_time', 'data.customerpayment.paid_time',
                'payment.payment_time', 'data.payment.payment_time',
                'payment.paid_time', 'data.payment.paid_time',
                'invoice_payment.payment_time', 'data.invoice_payment.payment_time',
                'paid_at', 'payment_time',
            ]),
            'transaction_type' => is_array($paymentInvoice)
                ? $this->firstString(['invoice' => $paymentInvoice], ['invoice.transaction_type'])
                : null,
            'addon_code' => $this->firstString($payload, [
                'addon.addon_code', 'data.addon.addon_code',
                'addons.0.addon_code', 'data.addons.0.addon_code',
                'subscription.addons.0.addon_code', 'data.subscription.addons.0.addon_code',
                'customerpayment.addon_code', 'data.customerpayment.addon_code',
            ]),
            'hostedpage_id' => $this->firstString($payload, [
                'payment.invoices.0.hosted_page_id', 'data.payment.invoices.0.hosted_page_id',
                'hostedpage.hostedpage_id', 'data.hostedpage.hostedpage_id',
                'customerpayment.hostedpage_id', 'data.customerpayment.hostedpage_id',
                'payment.hostedpage_id', 'data.payment.hostedpage_id',
                'hostedpage_id',
            ]),
        ];
    }

    private function findPendingCircleSubscription(array $identifiers): ?CircleSubscription
    {
        $referenceId = (string) ($identifiers['reference_id'] ?? '');
        $hostedPageId = (string) ($identifiers['hostedpage_id'] ?? '');
        $subscriptionId = (string) ($identifiers['subscription_id'] ?? '');
        $invoiceId = (string) ($identifiers['invoice_id'] ?? '');
        $customerId = (string) ($identifiers['customer_id'] ?? '');
        $addonCode = (string) ($identifiers['addon_code'] ?? '');

        if ($referenceId !== '') {
            $byReference = CircleSubscription::query()
                ->where('status', 'pending')
                ->latest('created_at')
                ->get()
                ->first(function (CircleSubscription $subscription) use ($referenceId) {
                    return $referenceId === (string) data_get($subscription->raw_checkout_response, 'reference_id')
                        || $referenceId === (string) data_get($subscription->raw_checkout_response, 'hostedpage.reference_id')
                        || $referenceId === (string) data_get($subscription->raw_checkout_response, 'hostedpage.data.reference_id');
                });

            if ($byReference) {
                return $byReference;
            }
        }

        if ($hostedPageId !== '') {
            $byHostedPage = CircleSubscription::query()
                ->where('zoho_hosted_page_id', $hostedPageId)
                ->latest('created_at')
                ->first();

            if ($byHostedPage) {
                return $byHostedPage;
            }
        }

        if ($subscriptionId !== '') {
            $bySubscription = CircleSubscription::query()
                ->where('zoho_subscription_id', $subscriptionId)
                ->latest('created_at')
                ->first();

            if ($bySubscription) {
                return $bySubscription;
            }
        }

        if ($invoiceId !== '') {
            $byInvoice = CircleSubscription::query()
                ->latest('created_at')
                ->get()
                ->first(function (CircleSubscription $subscription) use ($invoiceId) {
                    return $invoiceId === (string) data_get($subscription->raw_webhook_payload, 'invoice_id')
                        || $invoiceId === (string) data_get($subscription->raw_webhook_payload, 'invoice.invoice_id')
                        || $invoiceId === (string) data_get($subscription->raw_webhook_payload, 'payment.invoices.0.invoice_id')
                        || $invoiceId === (string) data_get($subscription->raw_checkout_response, 'invoice.invoice_id');
                });

            if ($byInvoice) {
                return $byInvoice;
            }
        }

        $user = null;
        if ($customerId !== '') {
            $user = User::query()->where('zoho_customer_id', $customerId)->first();
        }

        if ($user) {
            $pendingCandidates = CircleSubscription::query()
                ->where('user_id', $user->id)
                ->where('status', 'pending')
                ->when($addonCode !== '', fn ($query) => $query->where('zoho_addon_code', $addonCode))
                ->latest('created_at')
                ->get();

            if ($pendingCandidates->isNotEmpty()) {
                $byPendingJoinRequest = $pendingCandidates->first(function (CircleSubscription $candidate) use ($user) {
                    return CircleJoinRequest::query()
                        ->where('user_id', $user->id)
                        ->where('circle_id', $candidate->circle_id)
                        ->where('status', CircleJoinRequest::STATUS_PENDING_CIRCLE_FEE)
                        ->exists();
                });

                if ($byPendingJoinRequest) {
                    return $byPendingJoinRequest;
                }

                return $pendingCandidates->first();
            }

            if ($addonCode !== '') {
                $byUserAndAddon = CircleSubscription::query()
                    ->where('user_id', $user->id)
                    ->where('zoho_addon_code', $addonCode)
                    ->latest('created_at')
                    ->first();

                if ($byUserAndAddon) {
                    return $byUserAndAddon;
                }
            }
        }

        return null;
    }

    private function finalizePaidCircleJoin(
        User $user,
        CircleSubscription $subscription,
        Carbon $paidAt,
        Carbon $startedAt,
        Carbon $expiresAt
    ): void {
        $this->paidCircleMembershipFinalizer->finalize($user, $subscription, $paidAt, $startedAt, $expiresAt);
        $this->circleJoinRequestPaymentSyncService->markRequestPaid($user, (string) $subscription->circle_id, $paidAt);
    }

    private function firstString(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = data_get($payload, $key);

            if ($value === null) {
                continue;
            }

            $value = is_string($value) ? trim($value) : (string) $value;

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function parseDateTimeValue(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function isValidWebhook(Request $request): bool
    {
        $expected = (string) config('services.zoho.webhook_token', env('ZOHO_WEBHOOK_TOKEN', ''));

        if ($expected === '') {
            Log::warning('Zoho webhook token missing from configuration');

            return false;
        }

        $incoming = (string) ($request->header('X-Webhook-Token')
            ?? $request->header('X-Zoho-Webhook-Signature')
            ?? $request->bearerToken()
            ?? $request->query('token')
            ?? $request->input('token')
            ?? '');

        $isValid = $incoming !== '' && hash_equals($expected, $incoming);

        Log::info('Zoho webhook authentication evaluated', [
            'token_present' => $incoming !== '',
            'valid' => $isValid,
        ]);

        return $isValid;
    }

    private function maskEmail(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) {
            return null;
        }

        [$name, $domain] = explode('@', $email, 2);

        return substr($name, 0, 1) . '***@' . $domain;
    }
}
