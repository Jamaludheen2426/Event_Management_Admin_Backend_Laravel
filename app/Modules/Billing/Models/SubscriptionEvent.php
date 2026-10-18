<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Core\Database\Model;
use Illuminate\Support\Carbon;

/**
 * Something that happened to a subscription (created, change scheduled,
 * renewed, cancelled...). Shown in the billing history as "setup" rows.
 *
 * @property int $id
 * @property int $client_subscription_id
 * @property int $client_id
 * @property string $type
 * @property int|null $from_plan_id
 * @property int|null $to_plan_id
 * @property string|null $description
 * @property string|null $amount
 * @property string|null $currency_code
 * @property string $actor
 * @property Carbon $occurred_at
 */
final class SubscriptionEvent extends Model
{
    protected $fillable = [
        'client_subscription_id', 'client_id', 'type', 'from_plan_id', 'to_plan_id', 'description', 'amount',
        'currency_code', 'actor', 'actor_id', 'occurred_at',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'occurred_at' => 'datetime'];
    }
}
