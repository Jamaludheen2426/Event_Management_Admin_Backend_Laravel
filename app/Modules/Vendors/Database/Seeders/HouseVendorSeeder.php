<?php

declare(strict_types=1);

namespace App\Modules\Vendors\Database\Seeders;

use App\Core\Tenancy\Tenancy;
use App\Modules\Platform\Models\Company;
use App\Modules\Vendors\Enums\VendorStatus;
use App\Modules\Vendors\Models\Vendor;
use Illuminate\Database\Seeder;

/**
 * Guarantees the house company and the house vendor exist on a fresh database.
 *
 * Ids come from config (tenancy.house_company_id / house_vendor_id, both 1).
 * On a real install `php artisan legacy:import` later overwrites these rows with
 * the production data under the same ids ("RA EVENTS" on production).
 * Safe to run any number of times.
 */
final class HouseVendorSeeder extends Seeder
{
    public function run(): void
    {
        Tenancy::bypass(function (): void {
            $companyId = (int) config('tenancy.house_company_id');
            $vendorId = Vendor::houseId();

            Company::query()->withTrashed()->find($companyId)
                ?? Company::query()->forceCreate([
                    'id' => $companyId,
                    'name' => (string) config('app.name'),
                    'slug' => 'company-'.$companyId,
                    'is_active' => true,
                ]);

            Vendor::query()->withTrashed()->find($vendorId)
                ?? Vendor::query()->forceCreate([
                    'id' => $vendorId,
                    'company_id' => $companyId,
                    'company_name' => 'House Vendor',
                    'status' => VendorStatus::Active,
                ]);
        });
    }
}
