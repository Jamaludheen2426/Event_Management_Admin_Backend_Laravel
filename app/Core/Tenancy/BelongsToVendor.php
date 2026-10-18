<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

use Illuminate\Database\Eloquent\Model;

/**
 * For vendor-layer aggregate roots (clients, events, sites, vendor users, ...).
 *
 *  - every query is filtered to the current vendor (VendorScope);
 *  - a new row gets the current vendor's id unless one was set explicitly.
 *
 * Child rows (participants, gallery items, ...) do NOT use this trait: they
 * carry only their parent's id and are reached through the parent.
 *
 * The model defines its own vendor() relation, so Core never imports the
 * Vendors module.
 */
trait BelongsToVendor
{
    public static function bootBelongsToVendor(): void
    {
        static::addGlobalScope(new VendorScope);

        static::creating(static function (Model $model): void {
            if ($model->getAttribute('vendor_id') === null) {
                $model->setAttribute('vendor_id', app(CurrentVendor::class)->id());
            }
        });
    }
}
