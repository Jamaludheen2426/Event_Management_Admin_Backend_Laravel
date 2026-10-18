<?php

declare(strict_types=1);

namespace App\Modules\Templates\Models;

use App\Core\Database\Model;
use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Identity\Models\Concerns\HasAuthors;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Border / frame artwork (the uploaded file IS the frame).
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property int|null $template_category_id
 * @property string|null $file_url
 * @property list<string>|null $supported_layouts
 * @property string $status
 * @property int $is_active
 */
final class FrameStyle extends Model
{
    use BelongsToCompany;
    use HasAuthors;
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'name', 'template_category_id', 'file_url', 'file_name', 'supported_layouts', 'status',
        'sort_order', 'is_active', 'approval_status', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['supported_layouts' => 'array'];
    }

    /** @return BelongsTo<TemplateCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(TemplateCategory::class, 'template_category_id');
    }
}
