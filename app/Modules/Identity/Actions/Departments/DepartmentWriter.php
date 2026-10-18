<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions\Departments;

use App\Core\Activity\ActivityLogger;
use App\Core\Exceptions\ApiException;
use App\Modules\Identity\Models\Department;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/** Admin departments of the current company (v1 /departments). */
final class DepartmentWriter
{
    public function __construct(private readonly ActivityLogger $activity) {}

    /** @param array{name: string, description?: string|null, is_active?: int|bool|string|null} $input */
    public function create(array $input): Department
    {
        $name = $this->name($input['name']);
        $this->assertNameFree($name);

        $department = Department::query()->create([
            'name' => $name,
            'description' => $input['description'] ?? null,
            'is_active' => (int) ($input['is_active'] ?? 1) === 1,
        ]);

        $this->activity->log('departments', 'create', "Created department: {$name}", $department);

        return $department;
    }

    /** @param array<string, mixed> $input */
    public function update(Department $department, array $input): Department
    {
        if (array_key_exists('name', $input)) {
            $name = $this->name((string) $input['name']);
            $this->assertNameFree($name, $department->id);
            $department->name = $name;
        }
        if (array_key_exists('description', $input)) {
            $department->setAttribute('description', $input['description']);
        }
        if (array_key_exists('is_active', $input)) {
            $department->is_active = (int) $input['is_active'] === 1;
        }
        $department->save();

        $this->activity->log('departments', 'update', "Updated department: {$department->name}", $department, [], $input);

        return $department;
    }

    public function delete(Department $department): void
    {
        DB::transaction(function () use ($department): void {
            // Employees keep working; they just lose the department.
            User::query()->where('department_id', $department->id)->update(['department_id' => null]);
            $department->delete();
        });

        $this->activity->log('departments', 'delete', "Deleted department: {$department->name}", $department);
    }

    private function name(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw ApiException::badRequest('Department name is required');
        }

        return $name;
    }

    private function assertNameFree(string $name, ?int $exceptId = null): void
    {
        $taken = Department::query()->where('name', $name)
            ->when($exceptId !== null, static fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();

        if ($taken) {
            throw ApiException::conflict('A department with this name already exists');
        }
    }
}
