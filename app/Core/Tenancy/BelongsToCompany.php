<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

use Illuminate\Database\Eloquent\Model;

/**
 * For platform-layer models (admin users, roles, catalogue, settings, vendors).
 *
 *  - every query is filtered to the current company (CompanyScope);
 *  - a new row gets the current company's id unless one was set explicitly.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(static function (Model $model): void {
            if ($model->getAttribute('company_id') === null) {
                $model->setAttribute('company_id', app(CurrentCompany::class)->id());
            }
        });
    }
}
