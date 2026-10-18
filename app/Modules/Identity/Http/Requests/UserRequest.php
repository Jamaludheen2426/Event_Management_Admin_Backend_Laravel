<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Support\AdminPassword;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create (POST) and update (PUT) of an admin employee, in v1 keys.
 * On update every field is optional and only sent fields change.
 * `department` (free text) and `confirm_password` are accepted and ignored.
 */
final class UserRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'full_name' => [$required, 'string', 'max:200'],
            'email' => [$required, 'email', 'max:255'],
            'password' => $creating ? AdminPassword::rules() : ['nullable', ...array_slice(AdminPassword::rules(), 1)],
            'role_id' => [$required, 'integer'],
            'username' => ['sometimes', 'nullable', 'string', 'max:100'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'avatar' => ['sometimes', 'nullable', 'string', 'max:500'],
            'dob' => ['sometimes', 'nullable', 'date'],
            'gender' => ['sometimes', 'nullable', 'in:male,female,other'],
            'marital_status' => ['sometimes', 'nullable', 'in:married,unmarried'],
            'country_id' => ['sometimes', 'nullable', 'integer', 'exists:countries,id'],
            'state_id' => ['sometimes', 'nullable', 'integer', 'exists:states,id'],
            'city_id' => ['sometimes', 'nullable', 'integer', 'exists:districts,id'],
            'pincode_id' => ['sometimes', 'nullable', 'integer', 'exists:cities,id'],
            'address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'department_id' => ['sometimes', 'nullable', 'integer'],
            'designation' => ['sometimes', 'nullable', 'string', 'max:200'],
            'doj' => ['sometimes', 'nullable', 'date'],
            'dor' => ['sometimes', 'nullable', 'date'],
            'timezone' => ['sometimes', 'nullable', 'timezone:all'],
            'login_access' => ['sometimes', 'in:0,1'],
            'is_active' => ['sometimes', 'in:0,1,2'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'role_id.required' => 'Please select a role',
            'city_id.exists' => 'Invalid city',
            'pincode_id.exists' => 'Invalid pincode',
            ...AdminPassword::messages(),
        ];
    }
}
