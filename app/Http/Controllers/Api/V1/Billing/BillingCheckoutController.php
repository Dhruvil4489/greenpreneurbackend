<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\User;
use App\Models\UserMembership;
use App\Services\Billing\MembershipSyncService;
use App\Services\Membership\MembershipUpgradeService;
use App\Services\Membership\MembershipWelcomeEmailService;
use App\Support\Zoho\ZohoBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class BillingCheckoutController extends Controller
{
    public function __construct(
        private readonly ZohoBillingService $zohoBillingService,
        private readonly MembershipSyncService $membershipSyncService,
        private readonly MembershipWelcomeEmailService $membershipWelcomeEmailService,
        private readonly MembershipUpgradeService $membershipUpgradeService,
    ) {
    }

    public function checkout(Request $request)
    {
        $validated = $request->validate([
            'plan_code' => ['required', 'string', 'max:120'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $result = $this->zohoBillingService->createHostedPageForSubscription($user, $validated['plan_code']);

            $hostedPageId = (string) data_get($result, 'hostedpage_id', '');
            $checkoutUrl = (string) data_get($result, 'checkout_url', '');

            try {
                $this->recordPendingZohoPayment($user, $validated['plan_code'], $hostedPageId);
            } catch (Throwable $throwable) {
                Log::warning('ZOHO_CHECKOUT_PENDING_RECORD_FAILED', [
                    'user_id' => $user->id,
                    'hostedpage_id' => $hostedPageId,
                    'message' => $throwable->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Hosted checkout URL created successfully.',
                'data' => [
                    'hostedpage_id' => $hostedPageId,
                    'checkout_url' => $checkoutUrl,
                ],
            ]);
        } catch (ValidationException $validationException) {
            return response()->json([
                'success' => false,
                'message' => collect($validationException->errors())->flatten()->first() ?? 'Validation failed',
                'data' => [
                    'errors' => $validationException->errors(),
                ],
            ], 422);
        } catch (Throwable $throwable) {
            Log::error('Zoho checkout creation failed', [
                'user_id' => $user->id,
                'message' => 'Failed to generate checkout URL.',
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate checkout URL.',
                'data' => [],
            ], 500);
        }
    }


    public function syncHostedPage(Request $request, string $hostedpageId)
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $hostedPageResponse = $this->zohoBillingService->getHostedPage($hostedpageId);
            $updated = $this->zohoBillingService->syncMembershipFromHostedPage($user, $hostedPageResponse);
            $user->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Hosted page membership sync completed.',
                'data' => [
                    'handled' => $updated,
                    'zoho_customer_id' => $user->zoho_customer_id,
                    'zoho_subscription_id' => $user->zoho_subscription_id,
                    'zoho_plan_code' => $user->zoho_plan_code,
                    'zoho_last_invoice_id' => $user->zoho_last_invoice_id,
                    'membership_starts_at' => $user->membership_starts_at,
                    'membership_ends_at' => $user->membership_ends_at,
                    'last_payment_at' => $user->last_payment_at,
                ],
            ]);
        } catch (Throwable $throwable) {
            Log::error('Zoho hosted page sync failed', [
                'user_id' => $user->id,
                'hostedpage_id' => $hostedpageId,
                'message' => $throwable->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Hosted page sync failed.',
                'data' => [],
            ], 500);
        }
    }

    public function status(Request $request, string $hostedpage_id)
    {
        try {
            $paymentQuery = Payment::query()
                ->whereNotNull('zoho_hostedpage_id')
                ->where('zoho_hostedpage_id', $hostedpage_id);

            if (Schema::hasColumn('payments', 'provider')) {
                $paymentQuery->where(function ($query) {
                    $query->where('provider', 'zoho')
                        ->orWhereNull('provider');
                });
            }

            $payment = $paymentQuery
                ->latest('created_at')
                ->first();

            $user = null;

            if ($payment) {
                $user = User::query()->where('id', $payment->user_id)->first();
            }

            if (! $user) {
                /** @var User|null $authUser */
                $authUser = $request->user();
                $user = $authUser;
            }

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found for hosted page status.',
                    'data' => [
                        'hostedpage_id' => $hostedpage_id,
                    ],
                ], 404);
            }

            $zohoResponse = $this->zohoBillingService->getHostedPage($hostedpage_id);

            $hostedPage = $zohoResponse['hostedpage'] ?? [];

            $hostedPageStatus =
                data_get($hostedPage, 'status')
                ?? data_get($hostedPage, 'hostedpage_status')
                ?? data_get($zohoResponse, 'status')
                ?? null;

            $subscriptionBlock =
                data_get($hostedPage, 'subscription')
                ?? data_get($hostedPage, 'subscriptions.0')
                ?? [];

            $subscriptionId =
                data_get($subscriptionBlock, 'subscription_id')
                ?? data_get($hostedPage, 'subscription_id')
                ?? data_get($hostedPage, 'data.subscription.subscription_id')
                ?? null;

            $invoiceId =
                data_get($hostedPage, 'invoice.invoice_id')
                ?? data_get($hostedPage, 'invoice_id')
                ?? null;

            $planCode =
                data_get($hostedPage, 'subscription.plan.plan_code')
                ?? data_get($hostedPage, 'plan.plan_code')
                ?? data_get($hostedPage, 'plan_code')
                ?? data_get($hostedPage, 'subscription.plan_code')
                ?? $payment?->zoho_plan_code;

            $termStart =
                data_get($subscriptionBlock, 'current_term_starts_at')
                ?? data_get($subscriptionBlock, 'created_time')
                ?? now()->toDateTimeString();

            $termEnd =
                data_get($subscriptionBlock, 'current_term_ends_at')
                ?? data_get($subscriptionBlock, 'expires_at')
                ?? null;

            if (strtolower((string) $hostedPageStatus) === 'success' && $subscriptionId === null) {
                $customerId = $user->zoho_customer_id ?: data_get($hostedPage, 'customer_id');

                if ($customerId) {
                    $subscriptionList = $this->zohoBillingService->listSubscriptionsByCustomer((string) $customerId);
                    $latestSubscription = data_get($subscriptionList, 'subscriptions.0', []);

                    if (is_array($latestSubscription) && $latestSubscription !== []) {
                        $subscriptionId = data_get($latestSubscription, 'subscription_id');
                        $planCode = $planCode
                            ?? data_get($latestSubscription, 'plan.plan_code')
                            ?? data_get($latestSubscription, 'plan_code');
                        $termStart = data_get($latestSubscription, 'current_term_starts_at')
                            ?? data_get($latestSubscription, 'created_time')
                            ?? $termStart;
                        $termEnd = data_get($latestSubscription, 'current_term_ends_at')
                            ?? data_get($latestSubscription, 'expires_at')
                            ?? $termEnd;
                        $subscriptionBlock = $latestSubscription;
                    }
                }
            }

            Log::info('Zoho checkout status parsed', [
                'hostedpage_id' => $hostedpage_id,
                'user_id' => $user->id,
                'hostedpage_status' => $hostedPageStatus,
                'subscription_id' => $subscriptionId,
                'plan_code' => $planCode,
                'term_start' => $termStart,
                'term_end' => $termEnd,
            ]);

            if (! $subscriptionId) {
                return response()->json([
                    'success' => true,
                    'message' => 'Payment pending finalization',
                    'data' => [
                        'hostedpage_id' => $hostedpage_id,
                        'hostedpage_status' => $hostedPageStatus,
                        'has_subscription' => false,
                    ],
                ]);
            }

            $normalizedStatus = strtolower((string) $hostedPageStatus);
            $isCompleted = in_array($normalizedStatus, ['paid', 'success', 'completed', 'active', 'payment_success'], true);

            if (! $isCompleted) {
                return response()->json([
                    'success' => true,
                    'message' => 'Payment pending finalization',
                    'data' => [
                        'hostedpage_id' => $hostedpage_id,
                        'hostedpage_status' => $hostedPageStatus,
                        'has_subscription' => true,
                    ],
                ]);
            }

            if (! $termEnd) {
                $termEnd = (string) (strtolower((string) $planCode) === '01'
                    ? now()->copy()->addYear()->toDateTimeString()
                    : now()->copy()->addYear()->toDateTimeString());
            }

            $freshUser = DB::transaction(function () use ($user, $payment, $subscriptionBlock, $subscriptionId, $planCode, $termStart, $termEnd, $invoiceId) {
                $syncedUser = $this->membershipSyncService->syncUserMembershipFromZoho($user, [
                    'subscription' => array_merge($subscriptionBlock, [
                        'subscription_id' => $subscriptionId,
                        'plan_code' => $planCode,
                        'current_term_starts_at' => $termStart,
                        'current_term_ends_at' => $termEnd,
                    ]),
                    'invoice' => ['invoice_id' => $invoiceId],
                ]);

                if ($payment) {
                    $payment->forceFill([
                        'status' => 'paid',
                        'paid_at' => now(),
                        'zoho_plan_code' => $planCode,
                    ])->save();

                    $this->syncUserMembershipRow($syncedUser, $payment, $termStart, $termEnd);
                }

                return $syncedUser;
            });

            $this->membershipWelcomeEmailService->sendIfEligible($freshUser);

            return response()->json([
                'success' => true,
                'message' => 'Membership payment completed successfully.',
                'data' => array_merge($this->membershipUpgradeService->membershipResponseData($freshUser), [
                    'zoho_subscription_id' => $freshUser?->zoho_subscription_id,
                    'zoho_last_invoice_id' => $freshUser?->zoho_last_invoice_id,
                    'zoho_plan_code' => $freshUser?->zoho_plan_code,
                    'hostedpage_status' => $hostedPageStatus,
                ]),
            ]);
        } catch (Throwable $throwable) {
            Log::error('Zoho checkout status sync failed', [
                'hostedpage_id' => $hostedpage_id,
                'message' => $throwable->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $throwable->getMessage(),
                'data' => [],
            ], 500);
        }
    }

    private function recordPendingZohoPayment(User $user, string $planCode, string $hostedpageId): void
    {
        $paymentQuery = Payment::query()
            ->whereNotNull('zoho_hostedpage_id')
            ->where('zoho_hostedpage_id', $hostedpageId);

        if (Schema::hasColumn('payments', 'provider')) {
            $paymentQuery->where(function ($query) {
                $query->where('provider', 'zoho')
                    ->orWhereNull('provider');
            });
        }

        $payment = $paymentQuery->first();

        if (! $payment) {
            $payment = new Payment();
            $payment->id = (string) Str::uuid();
        }

        $payload = [
            'user_id' => $user->id,
            'zoho_plan_code' => $planCode,
            'zoho_hostedpage_id' => $hostedpageId,
            'status' => 'pending',
        ];

        if (Schema::hasColumn('payments', 'provider')) {
            $payload['provider'] = 'zoho';
        }

        foreach ([
            'metadata' => ['source' => 'membership_payment', 'user_id' => (string) $user->id, 'plan_code' => $planCode, 'zoho_hostedpage_id' => $hostedpageId],
            'description' => 'membership_payment | user_id=' . $user->id . ' | plan=' . $planCode,
        ] as $column => $value) {
            if (Schema::hasColumn('payments', $column)) {
                $payload[$column] = $value;
            }
        }

        $payment->forceFill($payload);

        $payment->save();

        Log::info('Membership payment link created', [
            'payment_id' => (string) $payment->id,
            'user_id' => (string) $user->id,
            'plan_code' => $planCode,
            'zoho_hostedpage_id' => $hostedpageId,
        ]);
    }

    private function syncUserMembershipRow(User $user, Payment $payment, mixed $startsAt, mixed $endsAt): void
    {
        if (! Schema::hasTable('user_memberships')) {
            return;
        }

        $existing = UserMembership::query()
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->first();

        if ($existing) {
            $existing->forceFill([
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => 'active',
                'payment_id' => $payment->id,
            ])->save();

            return;
        }

        try {
            UserMembership::query()->create([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'membership_plan_id' => null,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => 'active',
                'payment_id' => $payment->id,
            ]);
        } catch (Throwable $throwable) {
            Log::warning('Unable to create user_memberships row during Zoho sync', [
                'user_id' => $user->id,
                'payment_id' => $payment->id,
                'error' => $throwable->getMessage(),
            ]);
        }
    }
}

/*
| Postman Smoke Steps
| 1) GET /api/v1/zoho/plans
| 2) POST /api/v1/billing/checkout {"plan_code":"01"}
| 3) Open checkout_url and complete payment
| 4) GET /api/v1/billing/checkout/{hostedpage_id}/status to finalize update
| 5) Webhook can also update automatically.
*/
