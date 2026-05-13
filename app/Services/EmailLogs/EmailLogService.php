<?php

namespace App\Services\EmailLogs;

use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class EmailLogService
{
    private const SENSITIVE_KEYS = [
        'password',
        'token',
        'access_token',
        'refresh_token',
        'secret',
    ];

    public function logSent(array $data): ?EmailLog
    {
        return $this->persist(array_merge($data, [
            'status' => Arr::get($data, 'status', 'sent'),
            'sent_at' => Arr::get($data, 'sent_at', now()),
            'created_at' => Arr::get($data, 'created_at', now()),
        ]));
    }

    public function logFailed(array $data, Throwable|string $error): ?EmailLog
    {
        $message = $error instanceof Throwable ? $error->getMessage() : (string) $error;

        return $this->persist(array_merge($data, [
            'status' => 'failed',
            'error_message' => Str::limit($message, 5000, ''),
            'sent_at' => Arr::get($data, 'sent_at', now()),
            'created_at' => Arr::get($data, 'created_at', now()),
        ]));
    }

    public function logMailableSent(Mailable $mailable, array $data): ?EmailLog
    {
        $payload = Arr::get($data, 'payload', []);
        if (! is_array($payload)) {
            $payload = [];
        }

        $payload['mailable_class'] = get_class($mailable);

        return $this->logSent(array_merge($data, [
            'template_key' => Arr::get($data, 'template_key', Str::snake(class_basename($mailable))),
            'subject' => Arr::get($data, 'subject', $this->extractSubject($mailable)),
            'body_html' => Arr::get($data, 'body_html', $this->renderMailableSafely($mailable)),
            'payload' => $payload,
        ]));
    }

    public function logMailableFailed(Mailable $mailable, array $data, Throwable|string $error): ?EmailLog
    {
        $payload = Arr::get($data, 'payload', []);
        if (! is_array($payload)) {
            $payload = [];
        }

        $payload['mailable_class'] = get_class($mailable);

        return $this->logFailed(array_merge($data, [
            'template_key' => Arr::get($data, 'template_key', Str::snake(class_basename($mailable))),
            'subject' => Arr::get($data, 'subject', $this->extractSubject($mailable)),
            'body_html' => Arr::get($data, 'body_html', $this->renderMailableSafely($mailable)),
            'payload' => $payload,
        ]), $error);
    }

    private function persist(array $data): ?EmailLog
    {
        try {
            $toEmail = trim((string) Arr::get($data, 'to_email', ''));
            if ($toEmail === '') {
                return null;
            }

            $payload = Arr::get($data, 'payload');
            if (is_array($payload)) {
                $payload = $this->sanitizePayload($payload);
            }

            $record = [
                'id' => Arr::get($data, 'id', (string) Str::uuid()),
                'user_id' => Arr::get($data, 'user_id') ?: $this->resolveUserId($toEmail),
                'to_email' => $toEmail,
                'to_name' => Arr::get($data, 'to_name'),
                'template_key' => Arr::get($data, 'template_key'),
                'subject' => Arr::get($data, 'subject'),
                'source_module' => Arr::get($data, 'source_module'),
                'related_type' => Arr::get($data, 'related_type'),
                'related_id' => $this->stringValue(Arr::get($data, 'related_id')),
                'source_type' => Arr::get($data, 'source_type'),
                'source_id' => $this->stringValue(Arr::get($data, 'source_id')),
                'source_event' => Arr::get($data, 'source_event'),
                'status' => Arr::get($data, 'status', 'sent'),
                'body_html' => Arr::get($data, 'body_html'),
                'payload' => is_array($payload) ? $payload : null,
                'error_message' => Arr::get($data, 'error_message'),
                'sent_at' => Arr::get($data, 'sent_at', now()),
                'created_at' => Arr::get($data, 'created_at', now()),
            ];

            return EmailLog::query()->create($record);
        } catch (Throwable $exception) {
            Log::warning('Email logging failed', [
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function renderMailableSafely(Mailable $mailable): ?string
    {
        try {
            return $mailable->render();
        } catch (Throwable) {
            return null;
        }
    }

    private function extractSubject(Mailable $mailable): ?string
    {
        if (property_exists($mailable, 'subjectLine') && filled($mailable->subjectLine)) {
            return (string) $mailable->subjectLine;
        }

        if (property_exists($mailable, 'subject') && filled($mailable->subject)) {
            return (string) $mailable->subject;
        }

        return null;
    }

    private function resolveUserId(string $email): ?string
    {
        if ($email === '') {
            return null;
        }

        return User::query()
            ->whereRaw('LOWER(email) = ?', [Str::lower($email)])
            ->value('id');
    }

    private function sanitizePayload(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            $normalizedKey = Str::lower((string) $key);

            if ($this->isSensitiveKey($normalizedKey)) {
                $sanitized[$key] = '***';
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizePayload($value);
                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    private function isSensitiveKey(string $key): bool
    {
        foreach (self::SENSITIVE_KEYS as $sensitiveKey) {
            if ($key === $sensitiveKey || Str::contains($key, $sensitiveKey)) {
                return true;
            }
        }

        return false;
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $stringValue = trim((string) $value);

        return $stringValue === '' ? null : $stringValue;
    }
}
