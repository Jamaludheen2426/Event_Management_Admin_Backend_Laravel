<?php

declare(strict_types=1);

namespace App\Modules\Templates\Models;

use App\Core\Database\Model;
use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Catalog\Models\EventCategory;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Identity\Models\Concerns\HasAuthors;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An invitation design clients pick for their event. Events refer to it by
 * `code` (v1 events.theme_id).
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string $code
 * @property string $status
 * @property int $is_active
 * @property int $is_featured
 * @property string $plan_availability
 * @property list<string>|null $tags
 * @property list<string>|null $available_for
 * @property array<string, int>|null $components
 * @property array<string, int>|null $permissions
 * @property list<string>|null $component_order
 */
final class EventTemplate extends Model
{
    use BelongsToCompany;
    use HasAuthors;
    use SoftDeletes;

    public const COMPONENT_KEYS = [
        'event_title', 'host_names', 'date_time', 'venue', 'event_qr_code', 'organizer', 'event_photos',
        'contact_details', 'invitation_message', 'footer_note', 'decoration_elements',
    ];

    public const PERMISSION_KEYS = ['background', 'colors', 'fonts', ...self::COMPONENT_KEYS];

    public const AUDIENCES = ['individual', 'company'];

    protected $fillable = [
        'company_id', 'name', 'code', 'event_category_id', 'template_category_id', 'style', 'tags', 'description',
        'layout_style', 'background_type', 'background_color', 'secondary_color', 'background_image', 'gradient_from',
        'gradient_to', 'gradient_via', 'image_position', 'image_scale', 'background_position', 'image_size',
        'overlay_enabled', 'overlay_color', 'artwork_style', 'gradient_type', 'gradient_direction', 'image_shape',
        'corner_radius', 'overlay_opacity', 'orientation', 'dimension', 'primary_font', 'secondary_font',
        'primary_font_size', 'secondary_font_size', 'border_style', 'frame_style_id', 'frame_color', 'decoration_color',
        'components', 'component_order', 'permissions', 'status', 'is_featured', 'available_for', 'plan_availability',
        'show_on_homepage', 'thumbnail', 'sort_order', 'is_active', 'approval_status', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'components' => 'array',
            'component_order' => 'array',
            'permissions' => 'array',
            'available_for' => 'array',
        ];
    }

    /** @return BelongsTo<EventCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(EventCategory::class, 'event_category_id');
    }

    /** @return BelongsTo<TemplateCategory, $this> */
    public function templateCategory(): BelongsTo
    {
        return $this->belongsTo(TemplateCategory::class);
    }

    /** @return BelongsTo<FrameStyle, $this> */
    public function frameStyle(): BelongsTo
    {
        return $this->belongsTo(FrameStyle::class);
    }

    /**
     * In the order the designer placed them.
     *
     * @return BelongsToMany<Decoration, $this>
     */
    public function decorations(): BelongsToMany
    {
        return $this->belongsToMany(Decoration::class, 'event_template_decoration')
            ->withPivot('position')->orderByPivot('position');
    }

    /**
     * Plans that may use it (plan_availability = selected).
     *
     * @return BelongsToMany<Plan, $this>
     */
    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'event_template_plan');
    }
}
