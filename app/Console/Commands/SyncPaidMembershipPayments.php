<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\User;
use App\Services\Membership\MembershipUpgradeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SyncPaidMembershipPayments extends Command
{
    protected $signature = 'membership:sync-paid-payments {--dry-run : Show matching rows without updating users} {--limit=500 : Maximum payments to scan}';

    protected $description = 'Upgrade users to Only Unity Peer for already-paid local Zoho membership payments.';

    public function handle(MembershipUpgradeService $membershipUpgradeService): int
    {
        if (! Schema::hasTable('payments') || ! Schema::hasColumn('payments', 'user_id') || ! Schema::hasColumn('payments', 'status')) {
            $this->warn('payments table, payments.user_id, or payments.status is missing; nothing to sync.');
            return self::SUCCESS;
        }

        $query = Payment::query()
            ->whereIn('status', ['paid', 'success', 'completed', 'payment_success', 'captured']);

        if (Schema::hasColumn('payments', 'provider')) {
            $query->where(function ($providerQuery) {
                $providerQuery->where('provider', 'zoho')->orWhereNull('provider');
            });
        }

        if (Schema::hasColumn('payments', 'category')) {
            $query->where(function ($categoryQuery) {
                $categoryQuery->whereNull('category')
                    ->orWhere('category', 'membership')
                    ->orWhere('category', 'subscription');
            });
        }

        $updated = 0;
        $scanned = 0;
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));

        $query->with('user')->latest('created_at')->limit($limit)->get()->each(function (Payment $payment) use (&$updated, &$scanned, $dryRun, $membershipUpgradeService): void {
            $scanned++;
            $user = $payment->user;

            if (! $user || ! in_array((string) $user->membership_status, [User::STATUS_FREE, User::STATUS_FREE_TRIAL, 'FREE_PEER', 'Free Peer'], true)) {
                return;
            }

            if ($dryRun) {
                $this->line("Would sync payment {$payment->id} for user {$user->id}");
                return;
            }

            $membershipUpgradeService->markAsOnlyUnityPeerAfterPayment($user, $payment);
            $updated++;

            Log::info('Membership paid payment synced', [
                'payment_id' => (string) $payment->id,
                'user_id' => (string) $user->id,
            ]);
        });

        $this->info($dryRun ? "Dry run completed. Scanned {$scanned} payments." : "Synced {$updated} paid membership payments after scanning {$scanned} payments.");

        return self::SUCCESS;
    }
}
