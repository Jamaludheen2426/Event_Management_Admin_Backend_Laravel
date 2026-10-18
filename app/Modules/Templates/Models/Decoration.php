<?php

declare(strict_types=1);

namespace App\Modules\Templates\Models;

use App\Core\Database\Model;
use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Identity\Models\Concerns\HasAuthors;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A decoration image placed on invitations (corner, divider, ornament...).
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string $type
 * @property string|null $file_url
 * @property int|null $file_size
 * @property int $is_active
 */
final class Decoration extends Model
{
    use BelongsToCompany;
    use HasAuthors;
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'name', 'type', 'file_url', 'file_name', 'file_format', 'file_size',
        'sort_order', 'is_active', 'approval_status', 'created_by', 'updated_by',
    ];
}
