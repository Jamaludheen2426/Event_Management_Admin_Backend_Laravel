<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Core\Database\Model;
use App\Core\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The "Most Popular" ribbon on plan cards. apply_to = selected limits it to
 * the plans in plan_badge_plan.
 *
 * @property int $id
 * @property int $company_id
 * @property string $text
 * @property string $apply_to
 * @property int $is_active
 */
final class PlanBadge extends Model
{
    use BelongsToCompany;
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'text', 'style', 'color', 'apply_to', 'is_active', 'sort_order',
        'approval_status', 'created_by', 'updated_by',
    ];

    /** @return BelongsToMany<Plan, $this> */
    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'plan_badge_plan');
    }
}
