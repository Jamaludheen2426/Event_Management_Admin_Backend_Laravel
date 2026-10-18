<?php

declare(strict_types=1);

namespace App\Modules\Sites\Actions;

use App\Core\Activity\ActivityLogger;
use App\Core\Crud\RecordWriter;
use App\Core\Exceptions\ApiException;
use App\Core\Support\Flag;
use App\Modules\Sites\Models\SiteFaq;
use App\Modules\Sites\Models\SiteFaqCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The admin FAQ screen (/faqs): the marketing site's FAQs.
 *
 * @extends RecordWriter<SiteFaq>
 */
final class FaqWriter extends RecordWriter
{
    public function __construct(ActivityLogger $activity, private readonly Sites $sites)
    {
        parent::__construct($activity);
    }

    protected function model(): string
    {
        return SiteFaq::class;
    }

    protected function module(): string
    {
        return 'faqs';
    }

    protected function label(): string
    {
        return 'FAQ';
    }

    protected function columns(): array
    {
        return ['faq_category_id', 'question', 'answer', 'tags', 'is_featured', 'sort_order', 'is_active'];
    }

    protected function query(): Builder
    {
        return SiteFaq::query()->where('site_id', $this->sites->marketing()->id);
    }

    protected function nameOf(Model $model): string
    {
        return mb_substr((string) $model->getAttribute('question'), 0, 60);
    }

    protected function prepare(array $values, ?Model $existing): array
    {
        $site = $this->sites->marketing();
        if ($existing === null) {
            $values['site_id'] = $site->id;
        }
        if (array_key_exists('faq_category_id', $values)) {
            $id = Flag::id($values['faq_category_id']);
            if ($id === null || ! SiteFaqCategory::query()->where('site_id', $site->id)->whereKey($id)->exists()) {
                throw ApiException::badRequest('FAQ category not found');
            }
            $values['faq_category_id'] = $id;
        } elseif ($existing === null) {
            throw ApiException::badRequest('FAQ category is required');
        }
        $this->sites->changed($site);

        return $values;
    }

    /** v1: one FAQ per position within a category. */
    protected function assertUnique(array $values, ?int $exceptId): void
    {
        $position = (int) ($values['sort_order'] ?? 0);
        if ($position > 0 && $this->taken($this->query(), ['faq_category_id' => $values['faq_category_id'] ?? null, 'sort_order' => $position], $exceptId)) {
            throw ApiException::badRequest("Sort order {$position} is already taken in this category.");
        }
    }
}
