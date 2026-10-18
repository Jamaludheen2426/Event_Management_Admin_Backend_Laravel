<?php

declare(strict_types=1);

namespace App\Modules\Clients\Support;

use App\Core\Exceptions\ApiException;

/**
 * The two client password rules v1 had, each kept on the paths that used it,
 * because the screens that send them validate the same way:
 *
 *  - website(): the client portal, the app and admin screens (8+ characters,
 *    a number, an upper-case letter);
 *  - vendor(): the vendor and staff portals, the vendor's public sign-up and
 *    the vendor-client portal (exactly 8 characters, upper, lower, number,
 *    symbol, no spaces — the vendor-side policy).
 */
final class ClientPassword
{
    public static function website(mixed $password): string
    {
        $value = is_scalar($password) ? (string) $password : '';
        if (mb_strlen($value) < 8) {
            throw ApiException::badRequest('Password must be at least 8 characters.');
        }
        if (! preg_match('/\d/', $value)) {
            throw ApiException::badRequest('Password must include a number.');
        }
        if (! preg_match('/[A-Z]/', $value)) {
            throw ApiException::badRequest('Password must include an uppercase letter.');
        }

        return $value;
    }

    public static function vendor(mixed $password): string
    {
        $value = is_scalar($password) ? (string) $password : '';
        $error = match (true) {
            $value === '' => 'Password is required',
            (bool) preg_match('/\s/', $value) => 'Password must not contain spaces',
            mb_strlen($value) !== 8 => 'Password must contain exactly 8 characters',
            ! preg_match('/[A-Z]/', $value) => 'Password must include at least 1 uppercase letter',
            ! preg_match('/[a-z]/', $value) => 'Password must include at least 1 lowercase letter',
            ! preg_match('/[0-9]/', $value) => 'Password must include at least 1 number',
            ! preg_match('/[^A-Za-z0-9]/', $value) => 'Password must include at least 1 special character',
            default => null,
        };
        if ($error !== null) {
            throw ApiException::badRequest($error);
        }

        return $value;
    }
}
