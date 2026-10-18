<?php

declare(strict_types=1);

namespace App\Core\Support;

use DateTimeInterface;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * The encrypted payload an event's QR code carries. Byte-compatible with the
 * Node API's utils/eventQr.js, so invitations already printed keep working.
 *
 *   EVQ<version>.<iv>.<authTag>.<ciphertext>      all three parts base64url
 *
 * AES-256-GCM: confidentiality plus an authentication tag, so a tampered code
 * fails to decrypt instead of decrypting into another event id. The key is the
 * 32 bytes Node derived with scryptSync(secret, 'event-qr-v1', 32), stored
 * base64-encoded in EVENT_QR_KEY (PHP has no built-in scrypt).
 *
 * The code names the event and when it was issued, nothing else; a scan reads
 * the live event. Older codes may carry a snapshot (cid, nm, sd, ...) and still
 * decrypt; expand() reads both shapes.
 */
final class EventQr
{
    public const VERSION = 1;

    private const CIPHER = 'aes-256-gcm';

    private const IV_BYTES = 12;

    private const TAG_BYTES = 16;

    public function __construct(#[SensitiveParameter] private readonly string $key)
    {
        if (strlen($key) !== 32) {
            throw new InvalidArgumentException('The event QR key must be exactly 32 bytes.');
        }
    }

    public static function fromConfig(): self
    {
        $encoded = (string) config('services.event_qr.key');
        $key = base64_decode($encoded, true);

        if ($encoded === '' || $key === false) {
            throw new InvalidArgumentException('EVENT_QR_KEY is not set or is not valid base64.');
        }

        return new self($key);
    }

    /** @param array<string, mixed> $payload */
    public function encrypt(array $payload): string
    {
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $ciphertext = openssl_encrypt($json, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_BYTES);

        if ($ciphertext === false) {
            throw new InvalidArgumentException('The QR payload could not be encrypted.');
        }

        return 'EVQ'.self::VERSION.'.'.self::b64url($iv).'.'.self::b64url($tag).'.'.self::b64url($ciphertext);
    }

    /**
     * The payload, or null for anything that is not a valid, untampered code of a
     * version this build understands. Callers treat null as "not our code".
     *
     * @return array<string, mixed>|null
     */
    public function decrypt(string $token): ?array
    {
        $token = trim($token);
        if (! str_starts_with($token, 'EVQ')) {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 4) {
            return null;
        }

        $version = substr($parts[0], 3);
        if (! ctype_digit($version) || (int) $version < 1 || (int) $version > self::VERSION) {
            return null;
        }

        $iv = self::unb64url($parts[1]);
        $tag = self::unb64url($parts[2]);
        $ciphertext = self::unb64url($parts[3]);

        if ($iv === null || $tag === null || $ciphertext === null
            || strlen($iv) !== self::IV_BYTES || strlen($tag) !== self::TAG_BYTES) {
            return null;
        }

        $plain = openssl_decrypt($ciphertext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            return null;
        }

        $payload = json_decode($plain, true);

        return is_array($payload) ? $payload : null;
    }

    /** A fresh code for one event: its id and when the code was made. */
    public function issue(int $eventId, ?DateTimeInterface $issuedAt = null): string
    {
        return $this->encrypt([
            'v' => self::VERSION,
            'eid' => $eventId,
            'ts' => ($issuedAt ?? now())->getTimestamp(),
        ]);
    }

    /**
     * The compact payload with readable names, for both payload shapes.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function expand(array $payload): array
    {
        $ts = $payload['ts'] ?? null;

        return [
            'version' => $payload['v'] ?? null,
            'event_id' => $payload['eid'] ?? null,
            'company_id' => $payload['cid'] ?? null,
            'vendor_id' => $payload['vid'] ?? null,
            'website_client_id' => $payload['clid'] ?? null,
            'subscription_plan_id' => $payload['pid'] ?? null,
            'event_category_id' => $payload['cat'] ?? null,
            'name' => $payload['nm'] ?? null,
            'start_date' => $payload['sd'] ?? null,
            'end_date' => $payload['ed'] ?? null,
            'start_time' => $payload['st'] ?? null,
            'end_time' => $payload['et'] ?? null,
            'issued_at' => is_numeric($ts) ? gmdate('Y-m-d\TH:i:s.000\Z', (int) $ts) : null,
        ];
    }

    private static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function unb64url(string $text): ?string
    {
        if ($text === '' || preg_match('/^[A-Za-z0-9_-]+$/', $text) !== 1) {
            return null;
        }

        $decoded = base64_decode(strtr($text, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
