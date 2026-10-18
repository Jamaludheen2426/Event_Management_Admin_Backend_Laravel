<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Core\Crud\RecordWriter;
use App\Core\Exceptions\ApiException;
use App\Core\Support\Flag;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Models\PlanBadge;
use Illuminate\Database\Eloquent\Model;

/**
 * Plan badges (v1 /plan-badges). apply_to = all clears the plan list;
 * selected keeps exactly the given plan_ids.
 *
 * @extends RecordWriter<PlanBadge>
 */
final class PlanBadgeWriter extends RecordWriter
{
    public const STYLES = ['default', 'rounded', 'pill', 'outline', 'soft', 'dashed'];

    public const RECOMMENDED = [
        ['text' => 'Most Popular', 'style' => 'soft', 'color' => '#16A34A'],
        ['text' => 'Best Value', 'style' => 'outline', 'color' => '#2563EB'],
        ['text' => 'Recommended', 'style' => 'default', 'color' => '#6E22FE'],
        ['text' => 'New', 'style' => 'pill', 'color' => '#F97316'],
        ['text' => 'Limited Time', 'style' => 'dashed', 'color' => '#E11D48'],
        ['text' => 'Premium', 'style' => 'rounded', 'color' => '#0D9488'],
    ];

    protected function model(): string
    {
        return PlanBadge::class;
    }

    protected function module(): string
    {
        return 'plan_badges';
    }

    protected function label(): string
    {
        return 'Badge';
    }

    protected function columns(): array
    {
        return ['text', 'style', 'color', 'apply_to', 'is_active', 'sort_order'];
    }

    protected function nameOf(Model $model): string
    {
        return (string) $model->getAttribute('text');
    }

    protected function prepare(array $values, ?Model $existing): array
    {
        if ($existing === null || array_key_exists('text', $values)) {
            $values['text'] = trim((string) ($values['text'] ?? ''));
            if ($values['text'] === '') {
                throw ApiException::badRequest('Badge text is required');
            }
            if (mb_strlen($values['text']) > 25) {
                throw ApiException::badRequest('Badge text must be 25 characters or fewer.');
            }
        }
        if (array_key_exists('style', $values) && ! in_array($values['style'], self::STYLES, true)) {
            throw ApiException::badRequest('Badge style must be one of: '.implode(', ', self::STYLES).'.');
        }
        if (array_key_exists('apply_to', $values) && ! in_array($values['apply_to'], ['all', 'selected'], true)) {
            throw ApiException::badRequest('Apply To must be "all" or "selected".');
        }

        return $values;
    }

    protected function afterSave(Model $model, array $input, bool $created): void
    {
        /** @var PlanBadge $model */
        if ($model->apply_to === 'all') {
            $model->plans()->detach();

            return;
        }
        if (! array_key_exists('plan_ids', $input) || ! is_array($input['plan_ids'])) {
            return;
        }
        $ids = array_values(array_unique(array_filter(array_map(Flag::id(...), $input['plan_ids']))));
        $model->plans()->sync(Plan::query()->whereKey($ids)->pluck('id')->all());
    }
}
