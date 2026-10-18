<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Limits every query on a vendor-layer model to the current vendor.
 * Throws (through CurrentVendor::id) when no vendor is resolved.
 */
final class VendorScope implements Scope
{
    /** @param Builder<Model> $builder */
    public function apply(Builder $builder, Model $model): void
    {
        if (Tenancy::isBypassed()) {
            return;
        }

        $builder->where($model->qualifyColumn('vendor_id'), app(CurrentVendor::class)->id());
    }
}
