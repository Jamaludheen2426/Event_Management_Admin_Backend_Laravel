<?php

declare(strict_types=1);

namespace App\Modules\Vendors\Support;

use App\Core\Exceptions\ApiException;

/**
 * The vendor, staff and client password rule: EXACTLY 8 characters, with an
 * upper-case letter, a lower-case letter, a number and a special character,
 * no spaces. The three frontends enforce the same rule; these are v1's words
 * (utils/vendorValidation.js).
 */
final class VendorPassword
{
    public static function assertValid(mixed $password): void
    {
        if (! is_string($password) || trim($password) === '') {
            throw ApiException::badRequest('Password is required');
        }
        if (preg_match('/\s/', $password)) {
            throw ApiException::badRequest('Password must not contain spaces');
        }
        if (mb_strlen($password) < 8) {
            throw ApiException::badRequest('Password must be at least 8 characters');
        }
        if (mb_strlen($password) > 8) {
            throw ApiException::badRequest('Password must not exceed 8 characters');
        }
        if (! preg_match('/[A-Z]/', $password)) {
            throw ApiException::badRequest('Password must include at least 1 uppercase letter');
        }
        if (! preg_match('/[a-z]/', $password)) {
            throw ApiException::badRequest('Password must include at least 1 lowercase letter');
        }
        if (! preg_match('/[0-9]/', $password)) {
            throw ApiException::badRequest('Password must include at least 1 number');
        }
        if (! preg_match('/[^A-Za-z0-9\s]/', $password)) {
            throw ApiException::badRequest('Password must include at least 1 special character');
        }
    }
}
