<?php

declare(strict_types=1);

namespace App\Modules\Templates\Models;

use App\Core\Database\Model;
use App\Core\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A template style (Floral, Royal...). Groups frame artwork; a template's
 * `style` is its category's slug.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string $slug
 */
final class TemplateCategory extends Model
{
    use BelongsToCompany;
    use SoftDeletes;

    protected $fillable = ['company_id', 'name', 'slug', 'sort_order', 'is_active', 'approval_status', 'created_by', 'updated_by'];

    /** @return HasMany<FrameStyle, $this> */
    public function frameStyles(): HasMany
    {
        return $this->hasMany(FrameStyle::class);
    }
}
