<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use Illuminate\Validation\Rules\Password;

/**
 * The one admin password rule: 8+ characters, upper and lower case, a number,
 * a symbol, no spaces — what the admin employee form already demanded.
 * v1's backend checked none of it, and its auth screens disagreed (6 vs 8).
 */
final class AdminPassword
{
    /** @return list<mixed> */
    public static function rules(bool $required = true): array
    {
        return [
            $required ? 'required' : 'sometimes',
            'string',
            'max:100',
            'not_regex:/\s/',
            Password::min(8)->mixedCase()->numbers()->symbols(),
        ];
    }

    /** @return array<string, string> */
    public static function messages(string $field = 'password'): array
    {
        return [$field.'.not_regex' => 'Password must not contain spaces'];
    }
}
