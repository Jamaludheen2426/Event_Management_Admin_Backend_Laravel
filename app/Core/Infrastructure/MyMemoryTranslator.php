<?php

declare(strict_types=1);

namespace App\Core\Infrastructure;

use App\Core\Contracts\MachineTranslator;
use App\Core\Exceptions\TranslationQuotaExceeded;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * MyMemory (https://mymemory.translated.net), ported from the Node API's
 * autoTranslate.service.js with its two hard-won fixes:
 *
 *  - Quota failover. The quota is per e-mail address (MYMEMORY_EMAIL may list
 *    several, comma separated). A spent address is parked until the next UTC day
 *    and the next one is tried. When the quota is spent MyMemory still answers
 *    HTTP 200 and echoes the source text, so the warning text is checked BEFORE
 *    the success branch — otherwise the echo is saved as a "translation".
 *  - Mojibake repair. Some corpus entries are UTF-8 stored as Latin-1
 *    ("à®ªà®¿à®°" for "பிர"). Repaired when every byte survived, rejected when not.
 */
final class MyMemoryTranslator implements MachineTranslator
{
    private const ENDPOINT = 'https://api.mymemory.translated.net/get';

    private const QUOTA_PATTERN = '/QUOTA|ALL AVAILABLE FREE TRANSLATIONS|MYMEMORY WARNING/i';

    private const MOJIBAKE_PATTERN = '/[\x{00C2}-\x{00F4}][\x{0080}-\x{00BF}]/u';

    /** @param list<string> $emails */
    public function __construct(private readonly array $emails = []) {}

    public function translate(string $text, string $from, string $to): string
    {
        if ($from === $to || trim($text) === '') {
            return $text;
        }

        $candidates = $this->availableEmails();
        $lastQuotaError = null;

        foreach ($candidates as $email) {
            try {
                return $this->request($text, $from, $to, $email);
            } catch (TranslationQuotaExceeded $e) {
                $lastQuotaError = $e;
                if ($email === '') {
                    break;
                }
                $this->markExhausted($email);
            }
        }

        throw $lastQuotaError ?? new TranslationQuotaExceeded('Daily translation quota exceeded');
    }

    private function request(string $text, string $from, string $to, string $email): string
    {
        $query = array_filter(['q' => $text, 'langpair' => "{$from}|{$to}", 'de' => $email]);
        $response = Http::timeout(10)->get(self::ENDPOINT, $query);

        if ($response->status() === 429 || (int) $response->json('responseStatus') === 429) {
            throw new TranslationQuotaExceeded('Rate limit exceeded');
        }

        $details = $response->json('responseDetails');
        if (is_string($details) && preg_match(self::QUOTA_PATTERN, $details) === 1) {
            throw new TranslationQuotaExceeded('Daily quota exceeded');
        }

        if ((int) $response->json('responseStatus') !== 200) {
            throw new RuntimeException('Translation failed: '.($response->json('responseStatus') ?? $response->status()));
        }

        $translated = self::repairMojibake((string) $response->json('responseData.translatedText', ''));
        if ($translated === null) {
            throw new RuntimeException('Translation service returned corrupted text');
        }

        return $translated;
    }

    /** Fixed text, the text unchanged when it is fine, or null when bytes were lost. */
    public static function repairMojibake(string $text): ?string
    {
        if (preg_match(self::MOJIBAKE_PATTERN, $text) !== 1) {
            return $text;
        }

        $repaired = mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8');

        return mb_check_encoding($repaired, 'UTF-8') && ! str_contains($repaired, "\u{FFFD}") ? $repaired : null;
    }

    /** @return list<string> */
    private function availableEmails(): array
    {
        if ($this->emails === []) {
            return [''];
        }

        $usable = array_values(array_filter($this->emails, fn (string $email): bool => ! Cache::has($this->exhaustedKey($email))));

        return $usable !== [] ? $usable : [$this->emails[array_key_last($this->emails)]];
    }

    private function markExhausted(string $email): void
    {
        // Until the next UTC midnight, when MyMemory resets the allowance.
        Cache::put($this->exhaustedKey($email), true, now('UTC')->addDay()->startOfDay());
    }

    private function exhaustedKey(string $email): string
    {
        return 'mymemory:exhausted:'.now('UTC')->format('Y-m-d').':'.hash('sha256', $email);
    }
}
