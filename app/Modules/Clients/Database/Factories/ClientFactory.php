<?php

declare(strict_types=1);

namespace App\Modules\Clients\Database\Factories;

use App\Modules\Clients\Models\Client;
use App\Modules\Vendors\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Client> */
final class ClientFactory extends Factory
{
    protected $model = Client::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'vendor_id' => Vendor::houseId(),
            'source' => 'website',
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'dial_code' => '+91',
            'mobile' => '9'.fake()->unique()->numerify('#########'),
            'password' => 'Pass#12a',
            'login_access' => true,
            'is_active' => 1,
        ];
    }
}
