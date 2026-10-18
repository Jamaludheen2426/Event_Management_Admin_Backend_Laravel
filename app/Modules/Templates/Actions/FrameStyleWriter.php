<?php

declare(strict_types=1);

namespace App\Modules\Templates\Actions;

use App\Core\Crud\RecordWriter;
use App\Core\Exceptions\ApiException;
use App\Core\Support\Flag;
use App\Modules\Templates\Models\FrameStyle;
use App\Modules\Templates\Models\TemplateCategory;
use Illuminate\Database\Eloquent\Model;

/**
 * Frame / border artwork (v1 /frame-styles). The file itself is uploaded
 * through /media first; this stores its URL.
 *
 * @extends RecordWriter<FrameStyle>
 */
final class FrameStyleWriter extends RecordWriter
{
    public const LAYOUTS = ['portrait', 'landscape', 'square'];

    protected function model(): string
    {
        return FrameStyle::class;
    }

    protected function module(): string
    {
        return 'frame_styles';
    }

    protected function label(): string
    {
        return 'Frame style';
    }

    protected function columns(): array
    {
        return ['name', 'template_category_id', 'file_url', 'file_name', 'supported_layouts', 'status', 'is_active', 'sort_order'];
    }

    protected function defaults(): array
    {
        return ['supported_layouts' => self::LAYOUTS, 'status' => 'published'];
    }

    protected function prepare(array $values, ?Model $existing): array
    {
        $creating = $existing === null;
        if ($creating || array_key_exists('name', $values)) {
            $values['name'] = mb_substr(trim((string) ($values['name'] ?? '')), 0, 150);
            if ($values['name'] === '') {
                throw ApiException::badRequest('Frame style name is required.');
            }
        }
        if ($creating || array_key_exists('template_category_id', $values)) {
            $values['template_category_id'] = Flag::id($values['template_category_id'] ?? null);
            if ($values['template_category_id'] === null) {
                throw ApiException::badRequest('Category is required.');
            }
            if (! TemplateCategory::query()->whereKey($values['template_category_id'])->exists()) {
                throw ApiException::badRequest('Selected category does not exist.');
            }
        }
        foreach (['file_url', 'file_name'] as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = trim((string) $values[$key]) ?: null;
            }
        }
        if (($creating || array_key_exists('file_url', $values)) && empty($values['file_url'])) {
            throw ApiException::badRequest('Please upload the frame / border file.');
        }
        if (array_key_exists('supported_layouts', $values)) {
            $values['supported_layouts'] = self::layouts($values['supported_layouts']);
        }
        if (array_key_exists('status', $values)) {
            $values['status'] = Flag::oneOf($values['status'], ['draft', 'published'], 'published');
        }
        if (array_key_exists('sort_order', $values)) {
            $values['sort_order'] = Flag::clamp($values['sort_order'], 0, 999999, 0);
        }

        return $values;
    }

    /**
     * Known layouts in canonical order; none known = all.
     *
     * @return list<string>
     */
    public static function layouts(mixed $raw): array
    {
        $list = is_array($raw) ? $raw : explode(',', (string) $raw);
        $picked = array_map(static fn (mixed $v): string => strtolower(trim(is_scalar($v) ? (string) $v : '')), $list);
        $out = array_values(array_intersect(self::LAYOUTS, $picked));

        return $out === [] ? self::LAYOUTS : $out;
    }
}
