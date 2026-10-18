<?php

declare(strict_types=1);

namespace App\Modules\Sites\Actions;

use App\Core\Activity\ActivityLogger;
use App\Core\Crud\RecordWriter;
use App\Core\Exceptions\ApiException;
use App\Modules\Sites\Models\SiteFaqCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The admin FAQ screen's categories (/faq-categories): the marketing site's
 * FAQ groups, the same rows the Website Builder's FAQ section edits.
 *
 * @extends RecordWriter<SiteFaqCategory>
 */
final class FaqCategoryWriter extends RecordWriter
{
    public function __construct(ActivityLogger $activity, private readonly Sites $sites)
    {
        parent::__construct($activity);
    }

    protected function model(): string
    {
        return SiteFaqCategory::class;
    }

    protected function module(): string
    {
        return 'faq_categories';
    }

    protected function label(): string
    {
        return 'FAQ category';
    }

    protected function columns(): array
    {
        return ['name', 'description', 'icon', 'color', 'sort_order', 'is_active'];
    }

    protected function query(): Builder
    {
        return SiteFaqCategory::query()->where('site_id', $this->sites->marketing()->id);
    }

    protected function prepare(array $values, ?Model $existing): array
    {
        if ($existing === null) {
            $values['site_id'] = $this->sites->marketing()->id;
        }
        $this->sites->changed($this->sites->marketing());

        return $values;
    }

    /** v1: one name per company, one category per position. */
    protected function assertUnique(array $values, ?int $exceptId): void
    {
        $name = trim((string) ($values['name'] ?? ''));
        if ($name !== '' && $this->taken($this->query(), ['name' => $name], $exceptId)) {
            throw ApiException::badRequest("A category with the name \"{$name}\" already exists.");
        }
        $position = (int) ($values['sort_order'] ?? 0);
        if ($position > 0 && $this->taken($this->query(), ['sort_order' => $position], $exceptId)) {
            throw ApiException::badRequest("Sort order {$position} is already taken for categories.");
        }
    }
}
