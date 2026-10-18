<?php

declare(strict_types=1);

namespace App\Core\Auth;

use App\Core\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Six-digit password reset codes, hashed, valid 10 minutes, at most 5 wrong
 * guesses. One code per (scope, email); a new request replaces the old code.
 *
 * Messages are v1's ("Invalid OTP", "OTP has expired. Please request a new one.").
 */
final class ResetCodes
{
    public const MINUTES = 10;

    private const MAX_ATTEMPTS = 5;

    /** @return string the plain code, to send by email */
    public function issue(string $scope, string $email): string
    {
        $code = (string) random_int(100000, 999999);
        DB::table('reset_codes')->updateOrInsert(
            ['scope' => $scope, 'email' => $this->key($email)],
            ['code_hash' => Hash::make($code), 'attempts' => 0, 'created_at' => now()],
        );

        return $code;
    }

    /** Throws when the code is wrong, expired or guessed too often. */
    public function check(string $scope, string $email, string $code): void
    {
        $row = DB::table('reset_codes')->where('scope', $scope)->where('email', $this->key($email))->first();
        if ($row === null) {
            throw ApiException::badRequest('Invalid OTP');
        }
        if ((int) $row->attempts >= self::MAX_ATTEMPTS) {
            throw ApiException::badRequest('Too many incorrect codes. Please request a new one.');
        }
        if (! Hash::check($code, (string) $row->code_hash)) {
            DB::table('reset_codes')->where('scope', $scope)->where('email', $this->key($email))->increment('attempts');
            throw ApiException::badRequest('Invalid OTP');
        }
        if (now()->subMinutes(self::MINUTES)->greaterThan($row->created_at)) {
            throw ApiException::badRequest('OTP has expired. Please request a new one.');
        }
    }

    public function forget(string $scope, string $email): void
    {
        DB::table('reset_codes')->where('scope', $scope)->where('email', $this->key($email))->delete();
    }

    private function key(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
