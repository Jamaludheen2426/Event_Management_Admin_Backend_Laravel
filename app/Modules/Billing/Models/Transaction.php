<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Core\Database\Model;
use App\Modules\Clients\Models\Client;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of a client's money ledger: an invoice raised (+), a payment (−),
 * a refund (+), an adjustment.
 *
 * @property int $id
 * @property int $client_id
 * @property int|null $client_subscription_id
 * @property int|null $invoice_id
 * @property string $type
 * @property string $status
 * @property string|null $description
 * @property string $amount
 * @property string $currency_code
 * @property string|null $reference
 * @property string|null $gateway
 * @property string|null $gateway_transaction_id
 * @property int|null $payment_method_id
 * @property string|null $method_brand
 * @property string|null $method_last4
 * @property string|null $method_label
 * @property int|null $recorded_by
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Invoice|null $invoice
 * @property-read Client $client
 */
final class Transaction extends Model
{
    protected $fillable = [
        'client_id', 'client_subscription_id', 'invoice_id', 'type', 'status', 'description', 'amount',
        'currency_code', 'reference', 'gateway', 'gateway_transaction_id', 'payment_method_id', 'method_brand',
        'method_last4', 'method_label', 'recorded_by', 'occurred_at',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'occurred_at' => 'datetime'];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withoutGlobalScopes()->withTrashed();
    }
}
