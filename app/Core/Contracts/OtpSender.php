<?php

declare(strict_types=1);

namespace App\Core\Contracts;

/**
 * Sends a one-time sign-in code to a phone (v1: MSG91 WhatsApp + SMS).
 *
 * The default driver only logs, and answers reason "not_configured"; the real
 * MSG91 driver arrives with the Messaging module. Tests bind a fake.
 *
 * Returns ['delivered' => bool, 'reason' => string|null]. reason is one of
 * "disabled", "not_configured" or a provider error.
 */
interface OtpSender
{
    /** @return array{delivered: bool, reason: string|null} */
    public function send(string $dialCode, string $mobile, string $code, string $purpose): array;
}
