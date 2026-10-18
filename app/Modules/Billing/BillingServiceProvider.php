<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Support\ModuleServiceProvider;
use App\Modules\Billing\Support\UsageMeters;

/**
 * Billing: clients' subscriptions to platform plans, invoices, the money
 * ledger, payment methods, "contact sales", and the admin Payments screen.
 */
final class BillingServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        // Events registers its counters here (events, guests, storage).
        $this->app->singleton(UsageMeters::class);
    }
}
