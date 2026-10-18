<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Create (POST) / update (PUT) of an admin role, v1 keys (`name` = display name). */
final class RoleRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:100'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'level' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:65535'],
            'is_active' => ['sometimes', 'in:0,1'],
        ];
    }
}
