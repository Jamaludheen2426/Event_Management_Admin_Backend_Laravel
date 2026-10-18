<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/** The four fields v1 let an admin change about themselves; anything else is ignored. */
final class UpdateProfileRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'full_name' => ['sometimes', 'required', 'string', 'max:200'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'avatar' => ['sometimes', 'nullable', 'string'],
            'timezone' => ['sometimes', 'nullable', 'timezone:all'],
        ];
    }
}
