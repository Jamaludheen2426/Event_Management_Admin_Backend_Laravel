<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Core\Database\Model;
use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Identity\Models\Concerns\HasAuthors;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What a client buys (v1 subscription_plans): price, billing cycle, limits
 * (NULL = unlimited) and the event menus it grants.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string $plan_code
 * @property string $price
 * @property int $trial_days
 * @property int $is_active
 */
final class Plan extends Model
{
    use BelongsToCompany;
    use HasAuthors;
    use SoftDeletes;

    public const LIMITS = [
        'max_events' => 'Max Events',
        'max_guests_per_event' => 'Max Guests',
        'max_rsvp_per_event' => 'Max RSVP per Event',
        'max_photos' => 'Max Images',
        'max_videos' => 'Max Videos',
    ];

    protected $fillable = [
        'company_id', 'name', 'plan_code', 'plan_type_id', 'billing_cycle', 'short_description', 'event_category_id',
        'max_events', 'max_guests_per_event', 'max_rsvp_per_event', 'max_photos', 'max_videos', 'storage_limit',
        'storage_unit', 'currency_code', 'price', 'trial_days', 'is_visible', 'is_active', 'sort_order', 'plan_badge_id',
        'deactivation_reason', 'deactivation_comments', 'deactivated_at', 'deactivated_by',
        'deletion_reason', 'deletion_comments', 'deleted_by', 'approval_status', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'deactivated_at' => 'datetime'];
    }

    /** @return BelongsTo<PlanType, $this> */
    public function planType(): BelongsTo
    {
        return $this->belongsTo(PlanType::class);
    }

    /** @return BelongsTo<EventCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(EventCategory::class, 'event_category_id');
    }

    /** @return BelongsTo<PlanBadge, $this> */
    public function planBadge(): BelongsTo
    {
        return $this->belongsTo(PlanBadge::class);
    }

    /**
     * Granted menus in display order.
     *
     * @return HasMany<PlanMenu, $this>
     */
    public function planMenus(): HasMany
    {
        return $this->hasMany(PlanMenu::class)->orderBy('sort_order');
    }

    /** @return BelongsTo<User, $this> */
    public function deactivator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deactivated_by')->withTrashed()->withoutGlobalScopes();
    }

    /** @return BelongsTo<User, $this> */
    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by')->withTrashed()->withoutGlobalScopes();
    }
}
