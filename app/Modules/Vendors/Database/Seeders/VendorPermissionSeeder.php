<?php

declare(strict_types=1);

namespace App\Modules\Vendors\Database\Seeders;

use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\PermissionGroup;
use App\Modules\Vendors\Support\VendorPermissionCatalogue;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Writes the vendor permission catalogue (guard vendor). Safe to re-run.
 * Groups are shared with the admin catalogue by slug ("roles" holds both
 * admin and vendor permissions, told apart by guard).
 */
final class VendorPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $groups = [];
        foreach (VendorPermissionCatalogue::groups() as $slug => $label) {
            $groups[$slug] = PermissionGroup::query()->firstOrCreate(['slug' => $slug], ['name' => $label]);
        }

        foreach (VendorPermissionCatalogue::permissions() as $name => ['label' => $label, 'group' => $group]) {
            Permission::query()->updateOrCreate(
                ['name' => $name, 'guard_name' => 'vendor'],
                ['label' => $label, 'permission_group_id' => $groups[$group]->id],
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
