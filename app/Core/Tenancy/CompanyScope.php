<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Limits every query on a platform-layer model to the current company.
 * Throws (through CurrentCompany::id) when no company is resolved.
 */
final class CompanyScope implements Scope
{
    /** @param Builder<Model> $builder */
    public function apply(Builder $builder, Model $model): void
    {
        if (Tenancy::isBypassed()) {
            return;
        }

        $builder->where($model->qualifyColumn('company_id'), app(CurrentCompany::class)->id());
    }
}
