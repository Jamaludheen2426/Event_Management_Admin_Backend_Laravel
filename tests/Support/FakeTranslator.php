<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Contracts\MachineTranslator;
use App\Core\Exceptions\TranslationQuotaExceeded;

/** "[ta] Save" for every call; can be told to fail like a spent quota. */
final class FakeTranslator implements MachineTranslator
{
    public int $calls = 0;

    public function __construct(private readonly bool $quotaSpent = false) {}

    public function translate(string $text, string $from, string $to): string
    {
        $this->calls++;
        if ($this->quotaSpent) {
            throw new TranslationQuotaExceeded('Daily translation quota exceeded');
        }

        return $from === $to ? $text : "[{$to}] {$text}";
    }
}
