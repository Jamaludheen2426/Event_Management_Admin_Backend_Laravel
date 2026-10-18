<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Core\Database\Model;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Clients\Models\Client;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A client's subscription to a platform plan. The client row's plan_id mirrors
 * the plan this subscription currently grants (entitlement), as in v1.
 *
 * @property int $id
 * @property int $client_id
 * @property int|null $plan_id
 * @property string $status
 * @property string $billing_cycle
 * @property string $price
 * @property string $currency_code
 * @property string $tax_rate
 * @property Carbon|null $started_at
 * @property Carbon|null $current_period_start
 * @property Carbon|null $current_period_end
 * @property Carbon|null $trial_ends_at
 * @property bool $cancel_at_period_end
 * @property Carbon|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property string|null $cancellation_comments
 * @property int|null $pending_plan_id
 * @property Carbon|null $pending_effective_at
 * @property-read Plan|null $plan
 * @property-read Plan|null $pendingPlan
 */
final class ClientSubscription extends Model
{
    protected $fillable = [
        'client_id', 'plan_id', 'status', 'billing_cycle', 'price', 'currency_code', 'tax_rate', 'started_at',
        'current_period_start', 'current_period_end', 'trial_ends_at', 'cancel_at_period_end', 'cancelled_at',
        'cancellation_reason', 'cancellation_comments', 'pending_plan_id', 'pending_effective_at',
    ];

    /** The column defaults, so a new subscription answers them without a reload. */
    protected $attributes = [
        'status' => 'active',
        'billing_cycle' => 'monthly',
        'price' => '0.00',
        'currency_code' => 'INR',
        'tax_rate' => '18.00',
        'cancel_at_period_end' => false,
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'started_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'trial_ends_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'cancelled_at' => 'datetime',
            'pending_effective_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class)->withoutGlobalScopes()->withTrashed();
    }

    /** @return BelongsTo<Plan, $this> */
    public function pendingPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'pending_plan_id')->withoutGlobalScopes()->withTrashed();
    }
}
