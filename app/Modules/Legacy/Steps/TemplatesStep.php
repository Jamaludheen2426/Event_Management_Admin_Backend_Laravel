<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Steps;

use App\Modules\Legacy\Support\Import;
use Illuminate\Support\Facades\DB;

/**
 * Event template catalogue: template categories, decorations, frame styles,
 * fonts and the templates themselves. A template's decoration_ids and
 * plan_ids (JSON) become the ordered decoration list and the plan list.
 */
final class TemplatesStep implements Step
{
    public function name(): string
    {
        return 'templates';
    }

    public function run(Import $import): void
    {
        $i = $import;
        $rows = $i->rows('template_categories');
        $i->put('template_categories', 'template_categories', array_map(static fn (array $r): array => [
            'id' => $r['id'], 'name' => $r['name'], 'slug' => $r['slug'], 'sort_order' => (int) $r['sort_order'], ...CatalogStep::base($r),
        ], $rows), count($rows));
        $categories = DB::table('template_categories')->pluck('id')->flip()->all();

        $rows = $i->rows('decorations');
        $i->put('decorations', 'decorations', array_map(static fn (array $r): array => [
            'id' => $r['id'], 'name' => $r['name'], 'type' => $r['type'], 'file_url' => $r['file_url'], 'file_name' => $r['file_name'],
            'file_format' => $r['file_format'], 'file_size' => $r['file_size'], 'sort_order' => (int) $r['sort_order'], ...CatalogStep::base($r),
        ], $rows), count($rows));
        $decorations = DB::table('decorations')->pluck('id')->flip()->all();

        $rows = $i->rows('frame_styles');
        $i->put('frame_styles', 'frame_styles', array_map(static fn (array $r): array => [
            'id' => $r['id'], 'name' => $r['name'], 'template_category_id' => isset($categories[(int) $r['template_category_id']]) ? $r['template_category_id'] : null,
            'file_url' => $r['file_url'], 'file_name' => $r['file_name'], 'supported_layouts' => Import::json($r['supported_layouts']), 'status' => $r['status'],
            'sort_order' => (int) $r['sort_order'], ...CatalogStep::base($r),
        ], $rows), count($rows));
        $frames = DB::table('frame_styles')->pluck('id')->flip()->all();

        $rows = $i->rows('template_fonts');
        $i->put('template_fonts', 'template_fonts', array_map(static fn (array $r): array => [
            'id' => $r['id'], 'company_id' => $r['company_id'] ?? 1, 'name' => $r['name'], 'source' => $r['source'], 'file_url' => $r['file_url'],
            'file_name' => $r['file_name'], 'file_format' => $r['file_format'], 'file_size' => $r['file_size'], 'link_url' => $r['link_url'],
            'is_active' => Import::bit($r['is_active']), 'created_by' => $r['created_by'], 'updated_by' => $r['updated_by'],
            'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']), 'deleted_at' => Import::at($r['deleted_at']),
        ], $rows), count($rows));

        $eventCategories = DB::table('event_categories')->pluck('id')->flip()->all();
        $plans = DB::table('plans')->pluck('id')->flip()->all();
        $rows = $i->rows('event_templates');
        $copy = [
            'name', 'code', 'style', 'description', 'layout_style', 'background_type', 'background_color', 'secondary_color', 'background_image',
            'gradient_from', 'gradient_to', 'gradient_via', 'image_position', 'image_scale', 'background_position', 'image_size', 'overlay_color',
            'artwork_style', 'gradient_type', 'gradient_direction', 'image_shape', 'corner_radius', 'overlay_opacity', 'orientation', 'dimension',
            'primary_font', 'secondary_font', 'primary_font_size', 'secondary_font_size', 'border_style', 'frame_color', 'decoration_color',
            'status', 'plan_availability', 'thumbnail',
        ];
        $i->put('event_templates', 'event_templates', array_map(static fn (array $r): array => [
            'id' => $r['id'], ...array_intersect_key($r, array_flip($copy)),
            'event_category_id' => isset($eventCategories[(int) $r['event_category_id']]) ? $r['event_category_id'] : null,
            'template_category_id' => isset($categories[(int) $r['template_category_id']]) ? $r['template_category_id'] : null,
            'frame_style_id' => isset($frames[(int) $r['frame_style_id']]) ? $r['frame_style_id'] : null,
            'tags' => Import::json($r['tags']), 'components' => Import::json($r['components']), 'component_order' => Import::json($r['component_order']),
            'permissions' => Import::json($r['permissions']), 'available_for' => Import::json($r['available_for']),
            'overlay_enabled' => Import::bit($r['overlay_enabled'], 0), 'is_featured' => Import::bit($r['is_featured'], 0),
            'show_on_homepage' => Import::bit($r['show_on_homepage'], 0), 'sort_order' => (int) $r['sort_order'], ...CatalogStep::base($r),
        ], $rows), count($rows));

        $ornaments = [];
        $planLinks = [];
        $read = 0;
        foreach ($rows as $r) {
            foreach (array_values(array_unique(array_map('intval', Import::list($r['decoration_ids'])))) as $position => $decoration) {
                $read++;
                if (isset($decorations[$decoration])) {
                    $ornaments[] = ['event_template_id' => $r['id'], 'decoration_id' => $decoration, 'position' => $position];
                }
            }
            foreach (array_unique(array_map('intval', Import::list($r['plan_ids']))) as $plan) {
                if (isset($plans[$plan])) {
                    $planLinks[] = ['event_template_id' => $r['id'], 'plan_id' => $plan];
                }
            }
        }
        $i->link('event_templates.decoration_ids', 'event_template_decoration', $ornaments, $read);
        $i->link('event_templates.plan_ids', 'event_template_plan', $planLinks, count($planLinks));
    }
}
