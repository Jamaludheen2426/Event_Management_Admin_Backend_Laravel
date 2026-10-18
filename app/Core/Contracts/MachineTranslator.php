<?php

declare(strict_types=1);

namespace App\Core\Contracts;

use App\Core\Exceptions\TranslationQuotaExceeded;

/**
 * Automatic translation of a piece of text. The default driver is MyMemory;
 * tests bind a fake.
 */
interface MachineTranslator
{
    /**
     * @throws TranslationQuotaExceeded when every configured quota is spent
     * @throws \RuntimeException for any other failure
     */
    public function translate(string $text, string $from, string $to): string;
}
