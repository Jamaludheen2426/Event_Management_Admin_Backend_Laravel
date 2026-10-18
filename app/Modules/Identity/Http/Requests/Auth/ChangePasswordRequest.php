<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests\Auth;

use App\Modules\Identity\Support\AdminPassword;
use Illuminate\Foundation\Http\FormRequest;

final class ChangePasswordRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'new_password' => AdminPassword::rules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Current and new password are required',
            'new_password.required' => 'Current and new password are required',
            ...AdminPassword::messages('new_password'),
        ];
    }
}
