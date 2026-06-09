<?php

namespace App\Services\Membership;

use App\Models\Payment;
use App\Models\User;
use App\Models\UserMembership;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class MembershipUpgradeService
{
    public const ONLY_UNITY_PEER_STATUS = 'only_unity_peer';
    public const ONLY_UNITY_PEER_LABEL = 'Only Unity Peer';

    /**
     * Mark a successfully paid membership purchase as Only Unity Peer without touching coins or circle data.
     */
    public function markAsOnlyUnityPeerAfterPayment(User $user, array|Model|null $paymentOrPlanData = null): User
    {
        return DB::transaction(function () use ($user, $paymentOrPlanData): User {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $data = $this->normalizeData($paymentOrPlanData);
            $payment = $paymentOrPlanData instanceof Payment
                ? Payment::query()->whereKey($paymentOrPlanData->getKey())->lockForUpdate()->first()
                : null;

            if (! $payment && ! empty($data['payment_id']) && Schema::hasTable('payments')) {
                $payment = Payment::query()->whereKey($data['payment_id'])->lockForUpdate()->first();
            }

            $startedAt = $this->parseDate($data['membership_starts_at'] ?? $data['starts_at'] ?? $data['start_date'] ?? null)
                ?? now();
            $expiresAt = $this->parseDate($data['membership_ends_at'] ?? $data['ends_at'] ?? $data['end_date'] ?? null)
                ?? $this->calculateExpiry($startedAt, $data, $payment);

            $userUpdates = $this->filterColumns('users', [
                'membership_status' => self::ONLY_UNITY_PEER_STATUS,
                'membership_starts_at' => $startedAt,
                'membership_ends_at' => $expiresAt,
                'membership_start_date' => $startedAt->toDateString(),
                'membership_end_date' => $expiresAt->toDateString(),
                'membership_expiry' => $expiresAt,
                'membership_approved_at' => $data['membership_approved_at'] ?? now(),
                'membership_approved_by' => $data['membership_approved_by'] ?? null,
                'last_payment_at' => $this->parseDate($data['paid_at'] ?? $data['payment_date'] ?? null) ?? now(),
                'zoho_customer_id' => $data['zoho_customer_id'] ?? null,
                'zoho_subscription_id' => $data['zoho_subscription_id'] ?? null,
                'zoho_plan_code' => $data['zoho_plan_code'] ?? $data['plan_code'] ?? null,
                'zoho_last_invoice_id' => $data['zoho_invoice_id'] ?? $data['invoice_id'] ?? null,
            ]);

            $lockedUser->forceFill($userUpdates)->save();

            if ($payment) {
                $paymentUpdates = $this->filterColumns($payment->getTable(), [
                    'status' => $data['payment_status'] ?? 'paid',
                    'paid_at' => $this->parseDate($data['paid_at'] ?? $data['payment_date'] ?? null) ?? now(),
                    'zoho_payment_id' => $data['zoho_payment_id'] ?? $data['payment_id'] ?? null,
                    'zoho_invoice_id' => $data['zoho_invoice_id'] ?? $data['invoice_id'] ?? null,
                    'zoho_invoice_number' => $data['zoho_invoice_number'] ?? $data['invoice_number'] ?? null,
                    'zoho_subscription_id' => $data['zoho_subscription_id'] ?? $data['subscription_id'] ?? null,
                    'zoho_hostedpage_id' => $data['zoho_hostedpage_id'] ?? $data['hostedpage_id'] ?? $data['hosted_page_id'] ?? null,
                    'zoho_plan_code' => $data['zoho_plan_code'] ?? $data['plan_code'] ?? null,
                ]);

                if ($paymentUpdates !== []) {
                    $payment->forceFill($paymentUpdates)->save();
                }
            }

            if ($payment) {
                $this->syncUserMembershipRow($lockedUser, $payment, $startedAt, $expiresAt, $data);
            }

            Log::info('Membership payment completed', [
                'user_id' => (string) $lockedUser->id,
                'payment_id' => $payment?->getKey(),
                'membership_status' => self::ONLY_UNITY_PEER_STATUS,
                'membership_starts_at' => $startedAt->toDateTimeString(),
                'membership_expires_at' => $expiresAt->toDateTimeString(),
            ]);

            return $lockedUser->fresh();
        });
    }

    public function membershipResponseData(User $user, string $paymentStatus = 'paid'): array
    {
        return [
            'payment_status' => $paymentStatus,
            'membership_status' => $user->membership_status,
            'membership_badge' => strtoupper(str_replace(' ', '_', (string) $user->membership_status)),
            'membership_label' => self::ONLY_UNITY_PEER_LABEL,
            'membership_started_at' => $user->membership_starts_at ?? $user->membership_start_date ?? null,
            'membership_expires_at' => $user->membership_ends_at ?? $user->membership_end_date ?? $user->membership_expiry ?? null,
        ];
    }

    private function calculateExpiry(Carbon $startedAt, array $data, ?Payment $payment): Carbon
    {
        $durationMonths = $this->durationMonths($data, $payment);

        return $startedAt->copy()->addMonths($durationMonths)->endOfDay();
    }

    private function durationMonths(array $data, ?Payment $payment): int
    {
        foreach (['duration_months', 'months'] as $key) {
            if ((int) ($data[$key] ?? 0) > 0) {
                return (int) $data[$key];
            }
        }

        $amount = $this->normalizeAmount($data['amount'] ?? $data['total_amount'] ?? $data['base_amount'] ?? $payment?->total_amount ?? $payment?->base_amount ?? null);

        return match ($amount) {
            3600 => 1,
            18000 => 12,
            25000 => 24,
            100000 => 12,
            default => $this->durationFromPlanText($data),
        };
    }

    private function durationFromPlanText(array $data): int
    {
        $text = strtolower(trim(implode(' ', array_filter([
            $data['plan_name'] ?? null,
            $data['plan'] ?? null,
            $data['zoho_plan_code'] ?? $data['plan_code'] ?? null,
        ], static fn ($value) => is_scalar($value) && trim((string) $value) !== ''))));

        if (str_contains($text, 'leader') || str_contains($text, '2 year') || str_contains($text, '24')) {
            return 24;
        }

        if (str_contains($text, 'starter') || str_contains($text, '1 month') || str_contains($text, 'monthly')) {
            return 1;
        }

        return 12;
    }

    private function normalizeAmount(mixed $amount): ?int
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        $numeric = (float) preg_replace('/[^0-9.]/', '', (string) $amount);
        if ($numeric > 1000000) {
            $numeric = $numeric / 100;
        }

        return (int) round($numeric);
    }

    private function normalizeData(array|Model|null $paymentOrPlanData): array
    {
        if ($paymentOrPlanData instanceof Model) {
            return $paymentOrPlanData->getAttributes();
        }

        return $paymentOrPlanData ?? [];
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if ($value instanceof CarbonInterface) {
            return Carbon::instance($value);
        }

        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function syncUserMembershipRow(User $user, ?Payment $payment, Carbon $startedAt, Carbon $expiresAt, array $data): void
    {
        if (! Schema::hasTable('user_memberships')) {
            return;
        }

        try {
            UserMembership::query()
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->update(['status' => 'expired', 'ends_at' => $startedAt]);

            $existing = $payment
                ? UserMembership::query()->where('payment_id', $payment->id)->first()
                : null;

            if ($existing) {
                $existing->forceFill([
                    'starts_at' => $startedAt,
                    'ends_at' => $expiresAt,
                    'status' => 'active',
                ])->save();

                return;
            }

            UserMembership::query()->create([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'membership_plan_id' => $data['membership_plan_id'] ?? $payment?->membership_plan_id,
                'starts_at' => $startedAt,
                'ends_at' => $expiresAt,
                'status' => 'active',
                'payment_id' => $payment?->id,
            ]);
        } catch (Throwable $throwable) {
            Log::warning('Membership payment user_memberships sync skipped', [
                'user_id' => (string) $user->id,
                'payment_id' => $payment?->id,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    private function filterColumns(string $table, array $values): array
    {
        return collect($values)
            ->filter(fn ($value, string $column) => $value !== null && Schema::hasColumn($table, $column))
            ->all();
    }
}
