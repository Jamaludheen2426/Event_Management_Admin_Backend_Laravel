<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests\Auth;

use App\Modules\Identity\Support\AdminPassword;
use Illuminate\Foundation\Http\FormRequest;

/** Also used (without `password`) by verify-reset-otp. */
final class ResetPasswordRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'email' => ['required', 'email'],
            'otp' => ['required', 'string', 'size:6'],
        ];

        if ($this->routeIs('admin.auth.reset-password')) {
            $rules['password'] = AdminPassword::rules();
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'otp.required' => 'Invalid OTP',
            'otp.size' => 'Invalid OTP',
            ...AdminPassword::messages(),
        ];
    }

    protected function prepareForValidation(): void
    {
        // The OTP may arrive as a number from some forms.
        if (is_int($this->input('otp'))) {
            $this->merge(['otp' => (string) $this->input('otp')]);
        }
    }
}
