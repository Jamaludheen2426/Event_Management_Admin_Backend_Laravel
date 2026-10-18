<?php

declare(strict_types=1);

namespace App\Modules\Vendors\Actions;

use App\Core\Exceptions\ApiException;
use App\Core\Support\Flag;
use App\Modules\Vendors\Models\VendorDepartment;
use App\Modules\Vendors\Models\VendorUser;
use Illuminate\Support\Facades\DB;

/** A vendor's departments (v1 /vendors/departments). Names unique per vendor. */
final class VendorDepartmentWriter
{
    public function find(int $id): VendorDepartment
    {
        return VendorDepartment::query()->find($id) ?? throw ApiException::notFound('Department not found');
    }

    /** @param array<string, mixed> $input */
    public function create(array $input): VendorDepartment
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw ApiException::badRequest('Department name is required');
        }
        $this->assertFree($name);

        return VendorDepartment::query()->create([
            'name' => $name,
            'description' => trim((string) ($input['description'] ?? '')) ?: null,
            'is_active' => true,
        ]);
    }

    /** @param array<string, mixed> $input */
    public function update(VendorDepartment $department, array $input): VendorDepartment
    {
        if (isset($input['name']) && trim((string) $input['name']) !== $department->name) {
            $name = trim((string) $input['name']);
            if ($name === '') {
                throw ApiException::badRequest('Department name is required');
            }
            $this->assertFree($name, $department->id);
            $department->name = $name;
        }
        if (array_key_exists('description', $input)) {
            $department->setAttribute('description', trim((string) $input['description']) ?: null);
        }
        if (array_key_exists('is_active', $input)) {
            $department->is_active = Flag::bit($input['is_active'], 1) === 1;
        }
        $department->save();

        return $department;
    }

    public function delete(VendorDepartment $department): void
    {
        DB::transaction(static function () use ($department): void {
            VendorUser::query()->where('department_id', $department->id)->update(['department_id' => null]);
            $department->delete();
        });
    }

    private function assertFree(string $name, ?int $exceptId = null): void
    {
        $taken = VendorDepartment::query()->where('name', $name)
            ->when($exceptId !== null, static fn ($q) => $q->whereKeyNot($exceptId))->exists();
        if ($taken) {
            throw ApiException::conflict('A department with this name already exists');
        }
    }
}
