<?php

declare(strict_types=1);

namespace App\Modules\Identity\Database\Factories;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
final class UserFactory extends Factory
{
    protected $model = User::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'Secret#1',
            'login_access' => true,
            'is_active' => true,
            'approval_status' => 'approved',
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
