<?php

namespace App\Services\Firebase;

use App\Models\UserPushToken;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class FcmService
{
    public function sendToToken(string $token, string $title, string $body, array $data = [], ?string $imageUrl = null, array $context = []): void
    {
        try {
            $projectId = (string) config('firebase.project_id');

            if ($projectId === '') {
                throw new RuntimeException('Firebase project id is not configured.');
            }

            $accessToken = $this->getAccessToken();
            $endpoint = sprintf('https://fcm.googleapis.com/v1/projects/%s/messages:send', $projectId);

            $resolvedImageUrl = $imageUrl;

            if ($resolvedImageUrl === null) {
                $candidateImageUrl = $data['image_url'] ?? null;
                if (is_string($candidateImageUrl) && $candidateImageUrl !== '') {
                    $resolvedImageUrl = $candidateImageUrl;
                }
            }

            if ($resolvedImageUrl !== null) {
                $data['image_url'] = $resolvedImageUrl;
            }

            $notification = [
                'title' => $title,
                'body' => $body,
            ];

            $androidNotification = [];

            if ($resolvedImageUrl !== null) {
                $notification['image'] = $resolvedImageUrl;
                $androidNotification['image'] = $resolvedImageUrl;
            }

            $androidNotification = array_merge([
                'sound' => 'default',
            ], $androidNotification);

            $payload = [
                'message' => [
                    'token' => $token,
                    'notification' => $notification,
                    'data' => $this->normalizeData($data),
                    'apns' => [
                        'headers' => [
                            'apns-priority' => '10',
                        ],
                        'payload' => [
                            'aps' => [
                                'alert' => [
                                    'title' => $title,
                                    'body' => $body,
                                ],
                                'sound' => 'default',
                                'badge' => 1,
                            ],
                        ],
                    ],
                    'android' => [
                        'priority' => 'high',
                        'notification' => $androidNotification,
                    ],
                ],
            ];

            Log::info('Sending FCM request', [
                'token_prefix' => substr($token, 0, 20) . '...',
                'title' => $title,
                'has_image' => $resolvedImageUrl !== null,
                'user_id' => $context['user_id'] ?? null,
                'platform' => $context['platform'] ?? null,
                'device_type' => $context['device_type'] ?? ($context['platform'] ?? null),
                'notification_type' => $context['notification_type'] ?? ($data['notification_type'] ?? null),
            ]);

            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->post($endpoint, $payload);

            if ($response->successful()) {
                return;
            }

            if ($this->isInvalidTokenResponse($response->json())) {
                UserPushToken::where('token', $token)->delete();

                Log::warning('FCM token removed after invalid token response', [
                    'token_prefix' => substr($token, 0, 20) . '...',
                    'user_id' => $context['user_id'] ?? null,
                    'platform' => $context['platform'] ?? null,
                'device_type' => $context['device_type'] ?? ($context['platform'] ?? null),
                    'notification_type' => $context['notification_type'] ?? ($data['notification_type'] ?? null),
                ]);

                return;
            }

            Log::error('FCM send failed', [
                'token_prefix' => substr($token, 0, 20) . '...',
                'user_id' => $context['user_id'] ?? null,
                'platform' => $context['platform'] ?? null,
                'device_type' => $context['device_type'] ?? ($context['platform'] ?? null),
                'notification_type' => $context['notification_type'] ?? ($data['notification_type'] ?? null),
                'firebase_error' => $response->body(),
            ]);

            throw new RuntimeException('FCM send failed: ' . $response->body());
        } catch (Throwable $throwable) {
            report($throwable);
            throw $throwable;
        }
    }

    private function getAccessToken(): string
    {
        return Cache::remember('firebase.fcm.access_token', now()->addMinutes(50), function (): string {
            $credentialsPath = (string) config('firebase.credentials');

            if ($credentialsPath === '' || ! is_file($credentialsPath)) {
                throw new RuntimeException('Firebase credentials file is not available.');
            }

            $credentials = json_decode((string) file_get_contents($credentialsPath), true);

            if (! is_array($credentials)) {
                throw new RuntimeException('Firebase credentials are invalid JSON.');
            }

            $clientEmail = (string) ($credentials['client_email'] ?? '');
            $privateKey = (string) ($credentials['private_key'] ?? '');
            $tokenUri = (string) ($credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token');

            if ($clientEmail === '' || $privateKey === '') {
                throw new RuntimeException('Firebase credentials are incomplete.');
            }

            $jwt = $this->buildJwt($clientEmail, $privateKey, $tokenUri);

            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if (! $response->successful()) {
                throw new RuntimeException('Unable to fetch Firebase OAuth token.');
            }

            $accessToken = (string) $response->json('access_token');

            if ($accessToken === '') {
                throw new RuntimeException('Firebase OAuth token missing in response.');
            }

            return $accessToken;
        });
    }

    private function buildJwt(string $clientEmail, string $privateKey, string $audience): string
    {
        $now = now()->timestamp;

        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT',
        ];

        $payload = [
            'iss' => $clientEmail,
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => $audience,
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $encodedHeader = $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $encodedPayload = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));

        $signatureInput = $encodedHeader . '.' . $encodedPayload;

        $signature = '';
        $signed = openssl_sign($signatureInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        if (! $signed) {
            throw new RuntimeException('Unable to sign Firebase JWT.');
        }

        return $signatureInput . '.' . $this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function normalizeData(array $data): array
    {
        $normalized = [];

        foreach ($data as $key => $value) {
            $normalized[(string) $key] = is_scalar($value) || $value === null
                ? (string) $value
                : (json_encode($value) ?: '');
        }

        return $normalized;
    }

    private function isInvalidTokenResponse(mixed $response): bool
    {
        if (! is_array($response)) {
            return false;
        }

        $errorCode = Arr::get($response, 'error.details.0.errorCode');
        $message = strtolower((string) Arr::get($response, 'error.message', ''));

        return in_array($errorCode, ['UNREGISTERED', 'INVALID_ARGUMENT'], true)
            || str_contains($message, 'registration token is not a valid fcm registration token')
            || str_contains($message, 'requested entity was not found');
    }
}
