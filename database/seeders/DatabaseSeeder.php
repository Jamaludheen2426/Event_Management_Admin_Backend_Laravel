<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Identity\Database\Seeders\AdminAccessSeeder;
use App\Modules\Identity\Database\Seeders\PermissionCatalogueSeeder;
use App\Modules\Vendors\Database\Seeders\HouseVendorSeeder;
use App\Modules\Vendors\Database\Seeders\VendorPermissionSeeder;
use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            HouseVendorSeeder::class,
            PermissionCatalogueSeeder::class,
            AdminAccessSeeder::class,
            VendorPermissionSeeder::class,
        ]);
    }
}
