<?php

namespace App\Services;

use App\Models\MembershipPlan;
use App\Models\Payment;
use App\Models\User;
use App\Services\Membership\MembershipUpgradeService;
use Illuminate\Support\Str;

class MembershipService
{
    public function __construct(private readonly MembershipUpgradeService $membershipUpgradeService)
    {
    }

    public function calculateAmounts(MembershipPlan $plan): array
    {
        $baseAmount = (float) $plan->price;
        $gstPercent = (float) $plan->gst_percent;
        $gstAmount = round($baseAmount * ($gstPercent / 100), 2);
        $totalAmount = round($baseAmount + $gstAmount, 2);

        return [
            'base_amount' => $baseAmount,
            'gst_percent' => $gstPercent,
            'gst_amount' => $gstAmount,
            'total_amount' => $totalAmount,
        ];
    }

    public function resolveMembershipStatus(MembershipPlan $plan): string
    {
        $slug = strtolower((string) $plan->slug);

        if (Str::startsWith($slug, 'unity_peer')) {
            return 'unity_peer';
        }

        return match ($slug) {
            'circle_peer' => 'circle_peer',
            'multi_circle_peer' => 'multi_circle_peer',
            'charter_peer' => 'charter_peer',
            'free_peer' => 'free_peer',
            default => 'free_peer',
        };
    }

    /**
     * TODO: Recompute membership status from paid circle membership counts.
     * This is intentionally left as a placeholder for future circle billing logic.
     */
    public function recomputeStatusFromCircles(User $user): void
    {
        // TODO: Implement membership_status resolution based on circle participation rules.
    }

    public function activateMembership(User $user, MembershipPlan $plan, Payment $payment): User
    {
        $now = now();
        $endsAt = null;

        if ((int) $plan->duration_months > 0) {
            $endsAt = $now->copy()->addMonths((int) $plan->duration_months);
        } elseif ((int) $plan->duration_days > 0) {
            $endsAt = $now->copy()->addDays((int) $plan->duration_days);
        }

        return $this->membershipUpgradeService->markAsOnlyUnityPeerAfterPayment($user, [
            'payment_id' => $payment->id,
            'membership_plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'plan' => $plan->slug,
            'amount' => $plan->price,
            'total_amount' => $payment->total_amount,
            'duration_months' => $plan->duration_months ?: null,
            'membership_starts_at' => $now,
            'membership_ends_at' => $endsAt,
            'paid_at' => $payment->paid_at ?? $now,
        ]);
    }
}
