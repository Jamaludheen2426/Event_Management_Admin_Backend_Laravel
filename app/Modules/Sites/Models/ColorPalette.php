<?php

declare(strict_types=1);

namespace App\Modules\Sites\Models;

use App\Core\Database\Model;
use App\Core\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A named set of four colours the admin makes for sites to start from.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string|null $primary_bg_color
 * @property string|null $primary_text_color
 * @property string|null $secondary_text_color
 * @property string|null $paragraph_color
 * @property int $is_active
 */
final class ColorPalette extends Model
{
    use BelongsToCompany;
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'name', 'primary_bg_color', 'primary_text_color', 'secondary_text_color', 'paragraph_color',
        'is_active', 'approval_status', 'created_by', 'updated_by',
    ];

    protected $attributes = ['is_active' => 1];
}
