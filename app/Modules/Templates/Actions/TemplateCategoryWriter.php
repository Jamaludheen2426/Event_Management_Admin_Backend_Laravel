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
 * Template styles (v1 /template-categories). Deleting one leaves its frame
 * styles in place, uncategorised, and says how many.
 *
 * @extends RecordWriter<TemplateCategory>
 */
final class TemplateCategoryWriter extends RecordWriter
{
    protected function model(): string
    {
        return TemplateCategory::class;
    }

    protected function module(): string
    {
        return 'template_categories';
    }

    protected function label(): string
    {
        return 'Category';
    }

    protected function columns(): array
    {
        return ['name', 'slug', 'sort_order', 'is_active'];
    }

    protected function prepare(array $values, ?Model $existing): array
    {
        /** @var TemplateCategory|null $existing */
        if ($existing === null || array_key_exists('name', $values)) {
            $values['name'] = mb_substr(trim((string) ($values['name'] ?? '')), 0, 150);
            if ($values['name'] === '') {
                throw ApiException::badRequest('Category name is required.');
            }
        }
        if (array_key_exists('sort_order', $values)) {
            $values['sort_order'] = Flag::clamp($values['sort_order'], 0, 999999, 0);
        }
        if ($existing === null) {
            $values['slug'] = $this->uniqueSlug((string) (($values['slug'] ?? null) ?: $values['name']), 'slug', null, 'category');
        } elseif (array_key_exists('slug', $values)) {
            $values['slug'] = $this->uniqueSlug((string) (($values['slug'] ?? null) ?: ($values['name'] ?? $existing->name)), 'slug', $existing->id, 'category');
        }

        return $values;
    }

    /** @return int frame styles left without a category */
    public function deleteCounting(TemplateCategory $category, ?Model $actor): int
    {
        $orphaned = FrameStyle::query()->where('template_category_id', $category->id)->count();
        FrameStyle::query()->where('template_category_id', $category->id)->update(['template_category_id' => null]);
        $this->delete($category, $actor);

        return $orphaned;
    }
}
