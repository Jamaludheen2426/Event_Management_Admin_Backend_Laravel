<?php

declare(strict_types=1);

namespace App\Modules\Platform\Database\Factories;

use App\Modules\Platform\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Company> */
final class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'email' => fake()->unique()->companyEmail(),
            'is_active' => true,
        ];
    }
}
