<?php

declare(strict_types=1);

namespace App\Modules\Vendors\Database\Factories;

use App\Modules\Vendors\Models\Vendor;
use App\Modules\Vendors\Models\VendorUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VendorUser> */
final class VendorUserFactory extends Factory
{
    protected $model = VendorUser::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'vendor_id' => Vendor::factory(),
            'is_owner' => false,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'mobile' => '9'.fake()->numerify('#########'),
            'password' => 'Pass#12a',
            'login_access' => true,
            'is_active' => true,
            'work_status' => 'active',
        ];
    }

    public function owner(): static
    {
        return $this->state(['is_owner' => true]);
    }
}
