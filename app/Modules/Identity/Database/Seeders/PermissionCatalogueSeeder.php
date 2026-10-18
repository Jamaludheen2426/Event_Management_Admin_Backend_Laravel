<?php

declare(strict_types=1);

namespace App\Modules\Identity\Database\Seeders;

use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\PermissionGroup;
use App\Modules\Identity\Support\AdminPermissionCatalogue;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Writes the admin permission catalogue. Safe to re-run: adds what is missing,
 * refreshes labels, never removes a permission (roles may hold it).
 */
final class PermissionCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        $groups = [];
        foreach (AdminPermissionCatalogue::groups() as $slug => ['label' => $label]) {
            $groups[$slug] = PermissionGroup::query()->updateOrCreate(['slug' => $slug], ['name' => $label]);
        }

        foreach (AdminPermissionCatalogue::permissions() as $name => ['label' => $label, 'group' => $group]) {
            Permission::query()->updateOrCreate(
                ['name' => $name, 'guard_name' => 'admin'],
                ['label' => $label, 'permission_group_id' => $groups[$group]->id],
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
