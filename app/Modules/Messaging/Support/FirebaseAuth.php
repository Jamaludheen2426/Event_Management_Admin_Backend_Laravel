<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Support;

use App\Modules\Messaging\Models\PushConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Google's service-account OAuth flow for FCM v1: a JWT signed with the
 * account's private key is exchanged for a one-hour access token. No Firebase
 * SDK needed (v1 did the same with jsonwebtoken + axios).
 */
final class FirebaseAuth
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /**
     * Whether Google accepts the credentials (v1 performHandshake).
     *
     * @return array{success: bool, error: string|null}
     */
    public function handshake(?string $clientEmail, ?string $privateKey): array
    {
        if ($clientEmail === null || $clientEmail === '' || $privateKey === null || $privateKey === '') {
            return ['success' => false, 'error' => 'Client email or private key is missing.'];
        }
        try {
            $this->exchange($clientEmail, $privateKey, 8);

            return ['success' => true, 'error' => null];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /** A cached access token, renewed a minute before it expires. */
    public function accessToken(PushConfig $config): string
    {
        $key = "fcm-token:{$config->id}:".($config->updated_at->timestamp ?? 0);
        $cached = Cache::get($key);
        if (is_string($cached)) {
            return $cached;
        }
        if ($config->client_email === null || $config->private_key === null) {
            throw new RuntimeException('The active Firebase config has no service account credentials.');
        }
        [$token, $expiresIn] = $this->exchange($config->client_email, $config->private_key, 10);
        Cache::put($key, $token, max(60, $expiresIn - 60));

        return $token;
    }

    /** @return array{0: string, 1: int} */
    private function exchange(string $clientEmail, string $privateKey, int $timeout): array
    {
        $response = Http::asForm()->timeout($timeout)->post(self::TOKEN_URL, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $this->assertion($clientEmail, $privateKey),
        ]);
        if (! $response->successful()) {
            throw new RuntimeException((string) ($response->json('error_description') ?? $response->json('error') ?? 'HTTP '.$response->status()));
        }
        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Token response did not include access_token.');
        }

        return [$token, (int) ($response->json('expires_in') ?? 3600)];
    }

    private function assertion(string $clientEmail, string $privateKey): string
    {
        $now = time();
        $segments = [
            $this->base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->base64Url((string) json_encode(['iss' => $clientEmail, 'scope' => self::SCOPE, 'aud' => self::TOKEN_URL, 'exp' => $now + 3600, 'iat' => $now])),
        ];
        $key = openssl_pkey_get_private($privateKey);
        if ($key === false || ! openssl_sign(implode('.', $segments), $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('The private key could not be read.');
        }
        $segments[] = $this->base64Url($signature);

        return implode('.', $segments);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
