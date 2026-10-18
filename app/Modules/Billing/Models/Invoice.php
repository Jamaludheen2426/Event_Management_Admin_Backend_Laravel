<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Core\Database\Model;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Clients\Models\Client;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An invoice for one subscription term.
 *
 * @property int $id
 * @property string $invoice_number
 * @property int $client_id
 * @property int|null $client_subscription_id
 * @property int|null $plan_id
 * @property string $status
 * @property Carbon $issue_date
 * @property Carbon|null $due_date
 * @property Carbon|null $paid_at
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property string $currency_code
 * @property string $subtotal
 * @property string $discount_amount
 * @property string $tax_rate
 * @property string $tax_amount
 * @property list<array{label: string, rate: float, amount: float}>|null $tax_breakdown
 * @property bool $tax_inclusive
 * @property string $total
 * @property string $amount_paid
 * @property string $amount_due
 * @property string|null $billing_name
 * @property string|null $billing_email
 * @property string|null $billing_address
 * @property string|null $billing_gstin
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property-read Plan|null $plan
 */
final class Invoice extends Model
{
    protected $fillable = [
        'invoice_number', 'client_id', 'client_subscription_id', 'plan_id', 'status', 'issue_date', 'due_date',
        'paid_at', 'period_start', 'period_end', 'currency_code', 'subtotal', 'discount_amount', 'tax_rate',
        'tax_amount', 'tax_breakdown', 'tax_inclusive', 'total', 'amount_paid', 'amount_due', 'billing_name',
        'billing_email', 'billing_address', 'billing_gstin', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'paid_at' => 'datetime',
            'period_start' => 'date',
            'period_end' => 'date',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'tax_breakdown' => 'array',
            'tax_inclusive' => 'boolean',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'amount_due' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class)->withoutGlobalScopes()->withTrashed();
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withoutGlobalScopes()->withTrashed();
    }

    /** @return HasMany<InvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort_order');
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class)->orderBy('occurred_at')->orderBy('id');
    }
}
