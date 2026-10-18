<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use App\Core\Database\Model;
use App\Modules\Platform\Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The platform owner — the top tenant. Today there is one row.
 *
 * Not tenant-scoped itself: it IS the tenant.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property bool $is_active
 */
final class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'domain', 'logo', 'favicon', 'email', 'phone', 'address',
        'timezone', 'max_users', 'is_active', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'max_users' => 'integer',
        ];
    }

    protected static function newFactory(): CompanyFactory
    {
        return CompanyFactory::new();
    }
}
