<?php

namespace App\Services;

use App\Mail\CollaborationCompletedMail;
use App\Mail\CollaborationCreatedMail;
use App\Models\CollaborationPost;
use App\Models\EmailLog;
use App\Models\Notification;
use App\Models\User;
use App\Services\EmailLogs\EmailLogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CollaborationNotificationService
{
    private const SOURCE_TYPE = 'collaboration_post';
    private const CREATED_NOTIFICATION_ALL = 'created_notification_all';
    private const CREATED_NOTIFICATION_SELF = 'created_notification_self';
    private const COMPLETED_NOTIFICATION_ALL = 'completed_notification_all';
    private const CREATED_EMAIL_CREATOR = 'created_email_creator';
    private const COMPLETED_EMAIL_RELATED = 'completed_email_related';

    public function __construct(private readonly EmailLogService $emailLogService)
    {
    }

    public function sendCreatedNotificationsAndEmail(CollaborationPost $collaboration): void
    {
        $collaboration->loadMissing(['user', 'collaborationType']);
        $creatorName = $this->userName($collaboration->user);

        Log::info('Collaboration created notification sending started', [
            'collaboration_id' => (string) $collaboration->id,
            'user_id' => (string) $collaboration->user_id,
        ]);

        try {
            $this->insertNotificationsForActiveUsers(
                $collaboration,
                self::CREATED_NOTIFICATION_ALL,
                'New Collaboration Opportunity',
                "{$creatorName} posted a new collaboration: {$collaboration->title}",
                [
                    'type' => 'collaboration_created',
                    'collaboration_id' => (string) $collaboration->id,
                    'user_id' => (string) $collaboration->user_id,
                ]
            );

            if ($collaboration->user) {
                $this->insertNotificationIfMissing(
                    (string) $collaboration->user_id,
                    $collaboration,
                    self::CREATED_NOTIFICATION_SELF,
                    'Collaboration Posted',
                    "Your collaboration '{$collaboration->title}' has been posted successfully.",
                    [
                        'type' => 'my_collaboration_created',
                        'collaboration_id' => (string) $collaboration->id,
                    ]
                );
            }
        } catch (Throwable $exception) {
            Log::error('Collaboration notification failed', [
                'collaboration_id' => (string) $collaboration->id,
                'source_event' => self::CREATED_NOTIFICATION_ALL,
                'error' => $exception->getMessage(),
            ]);
        }

        Log::info('Collaboration created email sending started', [
            'collaboration_id' => (string) $collaboration->id,
            'user_id' => (string) $collaboration->user_id,
        ]);

        try {
            if ($collaboration->user) {
                $this->sendEmailIfMissing(
                    $collaboration->user,
                    $collaboration,
                    self::CREATED_EMAIL_CREATOR,
                    new CollaborationCreatedMail($collaboration, $collaboration->user),
                    'collaboration_created',
                    'Your collaboration has been posted successfully'
                );
            }
        } catch (Throwable $exception) {
            Log::error('Collaboration email failed', [
                'collaboration_id' => (string) $collaboration->id,
                'source_event' => self::CREATED_EMAIL_CREATOR,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function sendCompletedNotificationsAndEmails(CollaborationPost $collaboration): void
    {
        $collaboration->loadMissing(['user', 'collaborationType']);
        $creatorName = $this->userName($collaboration->user);

        Log::info('Collaboration completed notification sending started', [
            'collaboration_id' => (string) $collaboration->id,
            'user_id' => (string) $collaboration->user_id,
        ]);

        try {
            $this->insertNotificationsForActiveUsers(
                $collaboration,
                self::COMPLETED_NOTIFICATION_ALL,
                'Collaboration Completed',
                "{$creatorName} completed collaboration: {$collaboration->title}",
                [
                    'type' => 'collaboration_completed',
                    'collaboration_id' => (string) $collaboration->id,
                    'user_id' => (string) $collaboration->user_id,
                ]
            );
        } catch (Throwable $exception) {
            Log::error('Collaboration notification failed', [
                'collaboration_id' => (string) $collaboration->id,
                'source_event' => self::COMPLETED_NOTIFICATION_ALL,
                'error' => $exception->getMessage(),
            ]);
        }

        Log::info('Collaboration completed email sending started', [
            'collaboration_id' => (string) $collaboration->id,
            'user_id' => (string) $collaboration->user_id,
        ]);

        try {
            foreach ($this->completedEmailRecipients($collaboration) as $recipient) {
                $this->sendEmailIfMissing(
                    $recipient,
                    $collaboration,
                    self::COMPLETED_EMAIL_RELATED,
                    new CollaborationCompletedMail($collaboration, $recipient),
                    'collaboration_completed',
                    'Collaboration completed: ' . $collaboration->title
                );
            }
        } catch (Throwable $exception) {
            Log::error('Collaboration email failed', [
                'collaboration_id' => (string) $collaboration->id,
                'source_event' => self::COMPLETED_EMAIL_RELATED,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function insertNotificationsForActiveUsers(CollaborationPost $collaboration, string $sourceEvent, string $title, string $message, array $data): void
    {
        $this->activeUsersQuery()
            ->select('id')
            ->chunkById(500, function (Collection $users) use ($collaboration, $sourceEvent, $title, $message, $data): void {
                $userIds = $users->pluck('id')->map(fn ($id): string => (string) $id)->values();
                if ($userIds->isEmpty()) {
                    return;
                }

                $existingUserIds = Notification::query()
                    ->whereIn('user_id', $userIds->all())
                    ->where('source_type', self::SOURCE_TYPE)
                    ->where('source_id', $collaboration->id)
                    ->where('source_event', $sourceEvent)
                    ->pluck('user_id')
                    ->map(fn ($id): string => (string) $id);

                $now = now();
                $rows = $userIds
                    ->diff($existingUserIds)
                    ->map(function (string $userId) use ($collaboration, $sourceEvent, $title, $message, $data, $now): array {
                        $row = $this->notificationRow($userId, $collaboration, $sourceEvent, $title, $message, $data, $now);
                        $row['payload'] = json_encode($row['payload']);

                        return $row;
                    })
                    ->values()
                    ->all();

                if ($rows !== []) {
                    DB::table('notifications')->insert($rows);
                }
            });
    }

    private function insertNotificationIfMissing(string $userId, CollaborationPost $collaboration, string $sourceEvent, string $title, string $message, array $data): void
    {
        $exists = Notification::query()
            ->where('user_id', $userId)
            ->where('source_type', self::SOURCE_TYPE)
            ->where('source_id', $collaboration->id)
            ->where('source_event', $sourceEvent)
            ->exists();

        if ($exists) {
            return;
        }

        Notification::query()->create($this->notificationRow($userId, $collaboration, $sourceEvent, $title, $message, $data, now()));
    }

    private function notificationRow(string $userId, CollaborationPost $collaboration, string $sourceEvent, string $title, string $message, array $data, mixed $createdAt): array
    {
        return [
            'user_id' => $userId,
            'type' => 'activity_update',
            'title' => $title,
            'message' => $message,
            'payload' => [
                'notification_type' => $data['type'] ?? $sourceEvent,
                'title' => $title,
                'body' => $message,
                'to_user_id' => $userId,
                'data' => $data,
                'notifiable_type' => CollaborationPost::class,
                'notifiable_id' => (string) $collaboration->id,
            ],
            'source_type' => self::SOURCE_TYPE,
            'source_id' => (string) $collaboration->id,
            'source_event' => $sourceEvent,
            'is_read' => false,
            'created_at' => $createdAt,
            'read_at' => null,
        ];
    }

    private function sendEmailIfMissing(User $recipient, CollaborationPost $collaboration, string $sourceEvent, Mailable $mailable, string $templateKey, string $subject): void
    {
        $email = trim((string) $recipient->email);
        if ($email === '') {
            return;
        }

        if ($this->emailAlreadyLogged($email, $collaboration, $sourceEvent)) {
            return;
        }

        $logData = [
            'user_id' => $recipient->id,
            'to_email' => $email,
            'to_name' => $this->userName($recipient),
            'template_key' => $templateKey,
            'subject' => $subject,
            'source_module' => 'Collaborations',
            'related_type' => CollaborationPost::class,
            'related_id' => (string) $collaboration->id,
            'source_type' => self::SOURCE_TYPE,
            'source_id' => (string) $collaboration->id,
            'source_event' => $sourceEvent,
            'payload' => [
                'collaboration_id' => (string) $collaboration->id,
                'collaboration_title' => $collaboration->title,
                'source_event' => $sourceEvent,
            ],
        ];

        try {
            Mail::to($email)->send($mailable);
            $this->emailLogService->logMailableSent($mailable, $logData);
        } catch (Throwable $exception) {
            $this->emailLogService->logMailableFailed($mailable, $logData, $exception);

            Log::error('Collaboration email send failed', [
                'collaboration_id' => (string) $collaboration->id,
                'to_email' => $email,
                'source_event' => $sourceEvent,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function emailAlreadyLogged(string $email, CollaborationPost $collaboration, string $sourceEvent): bool
    {
        return EmailLog::query()
            ->whereRaw('LOWER(to_email) = ?', [strtolower($email)])
            ->where('source_type', self::SOURCE_TYPE)
            ->where('source_id', $collaboration->id)
            ->where('source_event', $sourceEvent)
            ->where('status', 'sent')
            ->exists();
    }

    private function completedEmailRecipients(CollaborationPost $collaboration): Collection
    {
        $userIds = collect([(string) $collaboration->user_id]);

        if (Schema::hasTable('collaboration_post_interests')) {
            $interestColumns = collect(['user_id', 'from_user_id', 'to_user_id'])
                ->filter(fn (string $column): bool => Schema::hasColumn('collaboration_post_interests', $column));

            foreach ($interestColumns as $column) {
                $userIds = $userIds->merge(DB::table('collaboration_post_interests')
                    ->where('post_id', $collaboration->id)
                    ->whereNotNull($column)
                    ->pluck($column));
            }
        }

        if (Schema::hasTable('collaboration_post_meeting_requests')) {
            $meetingColumns = collect(['user_id', 'from_user_id', 'to_user_id'])
                ->filter(fn (string $column): bool => Schema::hasColumn('collaboration_post_meeting_requests', $column));

            foreach ($meetingColumns as $column) {
                $userIds = $userIds->merge(DB::table('collaboration_post_meeting_requests')
                    ->where('post_id', $collaboration->id)
                    ->whereNotNull($column)
                    ->pluck($column));
            }
        }

        return User::query()
            ->whereIn('id', $userIds->filter()->map(fn ($id): string => (string) $id)->unique()->values()->all())
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->get()
            ->unique(fn (User $user): string => strtolower((string) $user->email))
            ->values();
    }

    private function activeUsersQuery(): Builder
    {
        $query = User::query();

        if (Schema::hasColumn('users', 'status')) {
            $query->where('status', 'active');
        }

        if (Schema::hasColumn('users', 'membership_status')) {
            $query->whereNotIn('membership_status', ['visitor', 'suspended']);
        }

        if (Schema::hasColumn('users', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return $query;
    }

    private function userName(?User $user): string
    {
        if (! $user) {
            return 'A peer';
        }

        $name = $user->display_name ?: trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));

        return $name !== '' ? $name : 'A peer';
    }
}
