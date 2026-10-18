<?php

declare(strict_types=1);

namespace App\Modules\Templates\Actions;

use App\Core\Crud\RecordWriter;
use App\Core\Exceptions\ApiException;
use App\Core\Support\Flag;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Templates\Models\Decoration;
use App\Modules\Templates\Models\EventTemplate;
use App\Modules\Templates\Models\FrameStyle;
use App\Modules\Templates\Models\TemplateCategory;
use Illuminate\Database\Eloquent\Model;

/**
 * Event templates (v1 /event-templates): the five-step design wizard.
 * Every value is normalised the way v1's pickWritable() did, so a template
 * the old API accepted is stored the same here.
 *
 * decoration_ids and plan_ids arrive as v1 arrays and are kept in pivots
 * (decorations in their given order).
 *
 * @extends RecordWriter<EventTemplate>
 */
final class EventTemplateWriter extends RecordWriter
{
    private const STYLES = ['classic', 'floral', 'royal', 'minimal', 'modern', 'traditional'];

    private const LAYOUT_STYLES = ['classic', 'modern', 'elegant', 'minimal', 'traditional'];

    private const POSITIONS = ['top-left', 'top', 'top-right', 'left', 'center', 'right', 'bottom-left', 'bottom', 'bottom-right'];

    private const DIRECTIONS = ['top', 'top-right', 'right', 'bottom-right', 'bottom', 'bottom-left', 'left', 'top-left'];

    protected function model(): string
    {
        return EventTemplate::class;
    }

    protected function module(): string
    {
        return 'event_templates';
    }

    protected function label(): string
    {
        return 'Template';
    }

    protected function columns(): array
    {
        return [
            'name', 'code', 'event_category_id', 'template_category_id', 'style', 'tags', 'description',
            'layout_style', 'background_type', 'background_color', 'secondary_color', 'background_image',
            'gradient_from', 'gradient_to', 'gradient_type', 'gradient_direction', 'image_shape', 'corner_radius',
            'image_position', 'image_scale', 'gradient_via', 'background_position', 'image_size', 'overlay_enabled',
            'overlay_color', 'artwork_style', 'overlay_opacity', 'orientation', 'dimension', 'primary_font',
            'secondary_font', 'border_style', 'frame_style_id', 'primary_font_size', 'secondary_font_size',
            'frame_color', 'decoration_color', 'components', 'component_order', 'permissions', 'status',
            'is_active', 'is_featured', 'available_for', 'plan_availability', 'sort_order', 'show_on_homepage', 'thumbnail',
        ];
    }

    protected function defaults(): array
    {
        return [
            'components' => array_fill_keys(EventTemplate::COMPONENT_KEYS, 1),
            'permissions' => array_fill_keys(EventTemplate::PERMISSION_KEYS, 1),
            'component_order' => EventTemplate::COMPONENT_KEYS,
        ];
    }

    protected function prepare(array $v, ?Model $existing): array
    {
        /** @var EventTemplate|null $existing */
        $creating = $existing === null;

        if ($creating || array_key_exists('name', $v)) {
            $v['name'] = mb_substr(trim((string) ($v['name'] ?? '')), 0, 200);
            if ($v['name'] === '') {
                throw ApiException::badRequest('Template name is required.');
            }
        }
        if (array_key_exists('event_category_id', $v)) {
            $v['event_category_id'] = Flag::id($v['event_category_id']);
        }
        if ($creating && empty($v['event_category_id'])) {
            throw ApiException::badRequest('Event category is required.');
        }
        if (array_key_exists('description', $v)) {
            $v['description'] = mb_substr(trim((string) $v['description']), 0, 500) ?: null;
        }
        if (array_key_exists('tags', $v)) {
            $v['tags'] = self::strings($v['tags'], 20, 40);
        }

        $map = [
            'style' => [self::STYLES, 'classic'],
            'layout_style' => [self::LAYOUT_STYLES, 'classic'],
            'background_type' => [['color', 'image', 'gradient', 'custom'], 'color'],
            'gradient_type' => [['linear', 'radial'], 'linear'],
            'gradient_direction' => [self::DIRECTIONS, 'bottom'],
            'image_shape' => [['rectangle', 'square', 'circle', 'heart', 'arch'], 'rectangle'],
            'image_position' => [self::POSITIONS, 'center'],
            'background_position' => [self::POSITIONS, 'center'],
            'image_scale' => [['cover', 'contain', 'fill', 'auto'], 'cover'],
            'orientation' => [['portrait', 'landscape'], 'portrait'],
            'status' => [['draft', 'published'], 'draft'],
            'plan_availability' => [['all', 'selected', 'trial'], 'all'],
        ];
        foreach ($map as $key => [$allowed, $fallback]) {
            if (array_key_exists($key, $v)) {
                $v[$key] = Flag::oneOf($v[$key], $allowed, $fallback);
            }
        }
        foreach (['background_color', 'secondary_color', 'gradient_from', 'gradient_to', 'gradient_via', 'overlay_color', 'frame_color', 'decoration_color'] as $key) {
            if (array_key_exists($key, $v)) {
                $v[$key] = self::hex($v[$key]);
            }
        }
        $ranges = [
            'overlay_opacity' => [0, 100, 0], 'corner_radius' => [0, 100, 0], 'image_size' => [10, 400, 100],
            'primary_font_size' => [60, 160, 100], 'secondary_font_size' => [60, 160, 100], 'sort_order' => [0, 999999, 0],
        ];
        foreach ($ranges as $key => [$min, $max, $fallback]) {
            if (array_key_exists($key, $v)) {
                $v[$key] = Flag::clamp($v[$key], $min, $max, $fallback);
            }
        }
        foreach (['overlay_enabled' => 0, 'is_featured' => 0, 'show_on_homepage' => 0] as $key => $fallback) {
            if (array_key_exists($key, $v)) {
                $v[$key] = Flag::bit($v[$key], $fallback);
            }
        }
        if (array_key_exists('artwork_style', $v)) {
            $v['artwork_style'] = Flag::oneOf($v['artwork_style'], ['full-bleed', 'patterned', 'illustration', 'textured'], null);
        }
        foreach (['background_image', 'thumbnail', 'dimension', 'primary_font', 'secondary_font', 'border_style'] as $key) {
            if (array_key_exists($key, $v)) {
                $v[$key] = trim((string) $v[$key]) ?: null;
            }
        }
        if (array_key_exists('components', $v)) {
            $v['components'] = self::flags($v['components'], EventTemplate::COMPONENT_KEYS);
        }
        if (array_key_exists('permissions', $v)) {
            $v['permissions'] = self::flags($v['permissions'], EventTemplate::PERMISSION_KEYS);
        }
        if (array_key_exists('component_order', $v)) {
            $v['component_order'] = self::order($v['component_order']);
        }
        if (array_key_exists('available_for', $v)) {
            $picked = [];
            foreach (self::strings($v['available_for'], 4, 20) as $audience) {
                $audience = strtolower($audience);
                $picked = [...$picked, ...($audience === 'both' ? EventTemplate::AUDIENCES : [$audience])];
            }
            $v['available_for'] = array_values(array_unique(array_intersect($picked, EventTemplate::AUDIENCES)));
        }

        if (array_key_exists('template_category_id', $v) || array_key_exists('style', $v)) {
            $v = $this->styleAndCategory($v);
        }
        if (array_key_exists('frame_style_id', $v)) {
            $v['frame_style_id'] = Flag::id($v['frame_style_id']);
            $this->assertFrame($v['frame_style_id']);
        }

        if ($creating) {
            $v['code'] = $this->uniqueSlug((string) (($v['code'] ?? null) ?: $v['name']), 'code', null, 'template');
        } elseif (array_key_exists('code', $v)) {
            $v['code'] = $this->uniqueSlug((string) (($v['code'] ?? null) ?: ($v['name'] ?? $existing->name)), 'code', $existing->id, 'template');
        }

        return $v;
    }

    protected function afterSave(Model $model, array $input, bool $created): void
    {
        /** @var EventTemplate $model */
        if (array_key_exists('decoration_ids', $input)) {
            $ids = self::ids($input['decoration_ids']);
            $live = Decoration::query()->whereKey($ids)->where('is_active', 1)->pluck('id')->all();
            $sync = [];
            foreach (array_values(array_intersect($ids, $live)) as $position => $id) {
                $sync[$id] = ['position' => $position];
            }
            $model->decorations()->sync($sync);
        }

        $availability = $model->plan_availability;
        if ($availability !== 'selected') {
            $model->plans()->detach();
        } elseif (array_key_exists('plan_ids', $input)) {
            $ids = Plan::query()->whereKey(self::ids($input['plan_ids']))->pluck('id')->all();
            if ($ids === []) {
                // v1: "selected" with no plans means all plans.
                $model->forceFill(['plan_availability' => 'all'])->save();
            }
            $model->plans()->sync($ids);
        }
    }

    public function setFeatured(EventTemplate $template, mixed $featured, ?Model $actor): EventTemplate
    {
        return $this->update($template, ['is_featured' => Flag::bit($featured)], $actor);
    }

    /** "(Copy)", fresh code, back to draft, not featured, same decorations and plans. */
    public function duplicate(EventTemplate $source, ?Model $actor): EventTemplate
    {
        $copy = $source->only($this->columns());
        $copy['name'] = $source->name.' (Copy)';
        unset($copy['code']);
        $copy['status'] = 'draft';
        $copy['is_featured'] = 0;
        $copy['decoration_ids'] = $source->decorations()->pluck('decorations.id')->all();
        $copy['plan_ids'] = $source->plans()->pluck('plans.id')->all();

        return $this->create($copy, $actor);
    }

    /**
     * The chosen style category decides `style` (its slug); a bare style slug
     * finds its category (v1 syncStyleAndCategory).
     *
     * @param  array<string, mixed>  $v
     * @return array<string, mixed>
     */
    private function styleAndCategory(array $v): array
    {
        if (array_key_exists('template_category_id', $v)) {
            $v['template_category_id'] = Flag::id($v['template_category_id']);
        }
        if (! empty($v['template_category_id'])) {
            $category = TemplateCategory::query()->find($v['template_category_id'])
                ?? throw ApiException::badRequest('Selected template style does not exist.');
            $v['style'] = $category->slug;

            return $v;
        }
        if (! empty($v['style'])) {
            $category = TemplateCategory::query()->where('slug', $v['style'])->first();
            if ($category !== null) {
                $v['template_category_id'] = $category->id;
            }
        }

        return $v;
    }

    private function assertFrame(?int $id): void
    {
        if ($id === null) {
            return;
        }
        $frame = FrameStyle::query()->find($id) ?? throw ApiException::badRequest('Selected frame style does not exist.');
        if ($frame->status !== 'published' || (int) $frame->is_active !== 1) {
            throw ApiException::badRequest('That frame style is not available. Publish and activate it first.');
        }
    }

    private static function hex(mixed $raw): ?string
    {
        $value = trim(is_scalar($raw) ? (string) $raw : '');

        return preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value) ? strtoupper($value) : null;
    }

    /** @return list<string> */
    private static function strings(mixed $raw, int $max, int $maxLength): array
    {
        $list = is_array($raw) ? $raw : ($raw === null ? [] : explode(',', (string) $raw));
        $out = [];
        foreach ($list as $item) {
            $value = mb_substr(trim(is_scalar($item) ? (string) $item : ''), 0, $maxLength);
            if ($value !== '' && ! in_array($value, $out, true)) {
                $out[] = $value;
            }
            if (count($out) >= $max) {
                break;
            }
        }

        return $out;
    }

    /** @return list<int> unique positive ids, in order */
    public static function ids(mixed $raw): array
    {
        $list = is_array($raw) ? $raw : ($raw === null ? [] : explode(',', (string) $raw));

        return array_values(array_unique(array_filter(array_map(Flag::id(...), $list))));
    }

    /**
     * A {key: 0|1} map over the known keys. A list means "these are on".
     *
     * @param  list<string>  $keys
     * @return array<string, int>
     */
    public static function flags(mixed $raw, array $keys): array
    {
        $isList = is_array($raw) && array_is_list($raw);
        $source = $isList ? array_fill_keys(array_map('strval', $raw), 1) : (is_array($raw) ? $raw : []);
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = array_key_exists($key, $source) ? Flag::bit($source[$key], 1) : ($isList ? 0 : 1);
        }

        return $out;
    }

    /**
     * Known component keys in the given order, missing ones appended.
     *
     * @return list<string>
     */
    public static function order(mixed $raw): array
    {
        $seen = [];
        foreach (is_array($raw) ? $raw : [] as $key) {
            $key = trim(is_scalar($key) ? (string) $key : '');
            if (in_array($key, EventTemplate::COMPONENT_KEYS, true) && ! in_array($key, $seen, true)) {
                $seen[] = $key;
            }
        }

        return [...$seen, ...array_values(array_diff(EventTemplate::COMPONENT_KEYS, $seen))];
    }
}
