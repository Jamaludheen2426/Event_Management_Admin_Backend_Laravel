<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use App\Core\Database\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A currency (global reference data). The format columns drive the admin
 * currency form's preview.
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string|null $symbol
 * @property bool $is_default
 * @property bool $is_active
 * @property string $approval_status
 */
final class Currency extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'code', 'symbol', 'exchange_rate', 'decimal_places', 'decimal_separator', 'thousand_separator',
        'symbol_position', 'space_between', 'is_default', 'is_active', 'approval_status', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'exchange_rate' => 'float',
            'decimal_places' => 'integer',
            'space_between' => 'boolean',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
