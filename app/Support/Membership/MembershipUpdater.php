<?php

namespace App\Support\Membership;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class MembershipUpdater
{
    private const ALLOWED_MEMBERSHIP_STATUSES = [
        'visitor',
        'member',
        'premium',
        'charter',
        'suspended',
        'free_peer',
        'only_unity_peer',
        'Only Unity Peer',
        'Circle Peer',
        'Multi Circle Peer',
        'Charter Peer',
        'Industry Advisor',
        'Charter Investor',
        'Circle Founder',
        'Circle Director',
        'Board Advisor',
    ];

    public function applyPaidMembership(User $user, array $attributes = []): bool
    {
        $membershipEndsAt = $attributes['membership_ends_at'] ?? $attributes['membership_expiry'] ?? null;
        $table = (new User())->getTable();

        $fields = [];

        foreach ([
            'zoho_customer_id' => $attributes['zoho_customer_id'] ?? null,
            'zoho_subscription_id' => $attributes['zoho_subscription_id'] ?? null,
            'zoho_plan_code' => $attributes['zoho_plan_code'] ?? null,
            'zoho_last_invoice_id' => $attributes['zoho_last_invoice_id'] ?? null,
            'membership_starts_at' => $attributes['membership_starts_at'] ?? null,
            'membership_ends_at' => $membershipEndsAt,
            'membership_expiry' => $membershipEndsAt,
            'membership_start_date' => $attributes['membership_start_date'] ?? $this->dateOnly($attributes['membership_starts_at'] ?? null),
            'membership_end_date' => $attributes['membership_end_date'] ?? $this->dateOnly($membershipEndsAt),
            'last_payment_at' => $attributes['last_payment_at'] ?? now(),
            'membership_approved_at' => $attributes['membership_approved_at'] ?? now(),
            'membership_approved_by' => $attributes['membership_approved_by'] ?? null,
        ] as $column => $value) {
            if (! is_null($value) && Schema::hasColumn($table, $column)) {
                $fields[$column] = $value;
            }
        }

        $membershipColumn = $this->resolveMembershipColumn();

        if ($membershipColumn !== null) {
            $planCode = (string) ($attributes['zoho_plan_code'] ?? $user->zoho_plan_code ?? '');
            $resolvedMembershipStatus = $this->resolveMembershipStatusFromPlanCode($planCode);
            $membershipStatus = $this->sanitizeMembershipStatus($resolvedMembershipStatus, $user, $planCode);

            Log::info('Updating membership', [
                'user_id' => $user->id,
                'plan_code' => $planCode,
                'membership_status' => $membershipStatus,
            ]);

            $currentValue = (string) ($user->getAttribute($membershipColumn) ?? '');

            if ($currentValue !== $membershipStatus) {
                $fields[$membershipColumn] = $membershipStatus;
            }
        } else {
            Log::warning('Membership column not found for Zoho membership update', [
                'user_id' => $user->id,
            ]);
        }

        if ($fields === []) {
            return false;
        }

        $user->forceFill($fields);
        $user->save();

        return true;
    }

    private function resolveMembershipStatusFromPlanCode(string $planCode): string
    {
        return match (strtolower(trim($planCode))) {
            '01', '012', 'unity_peer', 'only_unity_peer', 'only unity peer' => 'only_unity_peer',
            '013', 'circle_peer' => 'Circle Peer',
            '014', 'multi_circle_peer' => 'Multi Circle Peer',
            '015', 'charter_peer' => 'Charter Peer',
            default => 'Only Unity Peer',
        };
    }

    private function sanitizeMembershipStatus(string $membershipStatus, User $user, string $planCode): string
    {
        if (in_array($membershipStatus, self::ALLOWED_MEMBERSHIP_STATUSES, true)) {
            return $membershipStatus;
        }

        Log::error('Invalid membership status resolved for Zoho update, applying fallback', [
            'user_id' => $user->id,
            'plan_code' => $planCode,
            'resolved_membership_status' => $membershipStatus,
            'fallback_membership_status' => 'free_peer',
        ]);

        return 'free_peer';
    }


    private function dateOnly(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveMembershipColumn(): ?string
    {
        $table = (new User())->getTable();

        foreach (['membership_status', 'membership_type', 'membership'] as $candidate) {
            if (Schema::hasColumn($table, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
