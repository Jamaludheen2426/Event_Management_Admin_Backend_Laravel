<?php

declare(strict_types=1);

namespace App\Modules\Vendors\Database\Factories;

use App\Modules\Platform\Models\Company;
use App\Modules\Vendors\Enums\VendorStatus;
use App\Modules\Vendors\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Vendor> */
final class VendorFactory extends Factory
{
    protected $model = Vendor::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'company_name' => fake()->company(),
            'email' => fake()->unique()->companyEmail(),
            'status' => VendorStatus::Active,
        ];
    }
}
