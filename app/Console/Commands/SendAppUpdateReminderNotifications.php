<?php

namespace App\Console\Commands;

use App\Models\AppVersion;
use App\Models\Notification;
use App\Models\UserPushToken;
use App\Services\Firebase\FcmService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendAppUpdateReminderNotifications extends Command
{
    protected $signature = 'app:update-reminder-notifications';

    protected $description = 'Send app update reminder push notifications to users with outdated installed app versions.';

    public function handle(FcmService $fcmService): int
    {
        Log::info('App update reminder command started');
        $this->info('App update reminder command started.');

        $version = $this->latestVersionConfig();

        if (! $version) {
            Log::warning('App update reminder skipped because no active app version was found.');
            $this->warn('No active app version found.');

            return self::SUCCESS;
        }

        $latestVersion = (string) $version['latest_version'];
        $minVersion = (string) $version['min_version'];
        $updateType = (string) $version['update_type'];
        $playStoreUrl = (string) config('app_links.android.store_url', '');
        $appStoreUrl = (string) config('app_links.ios.store_url', '');

        Log::info('Latest app version found for update reminders', [
            'latest_version' => $latestVersion,
            'min_version' => $minVersion,
            'update_type' => $updateType,
        ]);

        $outdatedTokens = UserPushToken::query()
            ->with('user')
            ->whereNotNull('token')
            ->where('token', '!=', '')
            ->whereNotNull('app_version')
            ->where('app_version', '!=', '')
            ->get()
            ->filter(fn (UserPushToken $pushToken): bool => version_compare((string) $pushToken->app_version, $latestVersion, '<'));

        Log::info('Total eligible app update reminder tokens found', [
            'eligible_tokens' => $outdatedTokens->count(),
        ]);

        $sentCount = 0;
        $failedCount = 0;
        $recentlySkippedCount = 0;
        $missingUserSkippedCount = 0;
        $cutoff = now()->subHours(14);
        $isForceUpdate = in_array(strtolower($updateType), ['force', 'mandatory', 'forced'], true);
        $title = $isForceUpdate ? 'Important App Update Required' : 'Update Available 🚀';
        $body = $isForceUpdate
            ? 'Please update Peers Global Unity to continue using the latest features safely.'
            : 'A newer Peers Global Unity app is ready. Update now for smoother networking and latest improvements.';

        foreach ($outdatedTokens as $pushToken) {
            if (! $pushToken->user) {
                $missingUserSkippedCount++;
                continue;
            }

            if ($pushToken->last_update_notification_sent_at?->greaterThan($cutoff)) {
                $recentlySkippedCount++;
                continue;
            }

            $data = [
                'type' => 'app_update',
                'notification_type' => 'app_update',
                'latest_version' => $latestVersion,
                'min_version' => $minVersion,
                'update_type' => $updateType,
                'playstore_url' => $playStoreUrl,
                'appstore_url' => $appStoreUrl,
            ];

            try {
                $fcmService->sendToToken(
                    (string) $pushToken->token,
                    $title,
                    $body,
                    $data,
                    null,
                    [
                        'user_id' => (string) $pushToken->user_id,
                        'platform' => $pushToken->platform,
                        'notification_type' => 'app_update',
                    ],
                );

                Notification::create([
                    'user_id' => $pushToken->user_id,
                    'type' => 'app_update',
                    'payload' => [
                        'notification_type' => 'app_update',
                        'title' => $title,
                        'body' => $body,
                        'to_user_id' => (string) $pushToken->user_id,
                        'data' => $data,
                    ],
                    'is_read' => false,
                    'created_at' => now(),
                    'read_at' => null,
                ]);

                $pushToken->forceFill([
                    'last_update_notification_sent_at' => now(),
                ])->save();

                $sentCount++;
            } catch (Throwable $exception) {
                $failedCount++;

                Log::error('App update reminder failed for token', [
                    'user_id' => (string) $pushToken->user_id,
                    'token_prefix' => substr((string) $pushToken->token, 0, 12) . '...',
                    'error' => $exception->getMessage(),
                ]);

                report($exception);
            }
        }

        Log::info('App update reminder command finished', [
            'eligible_tokens' => $outdatedTokens->count(),
            'skipped_recent_notification' => $recentlySkippedCount,
            'skipped_missing_user' => $missingUserSkippedCount,
            'sent_count' => $sentCount,
            'failed_count' => $failedCount,
        ]);

        $this->info(sprintf(
            'Done. Eligible: %d, sent: %d, failed: %d, skipped recent: %d, skipped missing user: %d.',
            $outdatedTokens->count(),
            $sentCount,
            $failedCount,
            $recentlySkippedCount,
            $missingUserSkippedCount,
        ));

        return $failedCount > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function latestVersionConfig(): ?array
    {
        $version = AppVersion::query()
            ->where('platform', 'android')
            ->where('is_active', true)
            ->first();

        if (! $version) {
            $version = AppVersion::query()
                ->where('platform', 'ios')
                ->where('is_active', true)
                ->first();
        }

        if (! $version) {
            return null;
        }

        return [
            'latest_version' => $version->latest_version,
            'min_version' => $version->min_version,
            'update_type' => $version->update_type,
        ];
    }
}
