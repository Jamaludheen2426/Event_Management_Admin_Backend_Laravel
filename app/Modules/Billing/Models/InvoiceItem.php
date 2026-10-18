<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Core\Database\Model;
use Illuminate\Support\Carbon;

/**
 * One line of an invoice.
 *
 * @property int $id
 * @property int $invoice_id
 * @property string $item_type
 * @property string $description
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property string $quantity
 * @property string $unit_price
 * @property string $amount
 */
final class InvoiceItem extends Model
{
    protected $fillable = ['invoice_id', 'item_type', 'description', 'period_start', 'period_end', 'quantity', 'unit_price', 'amount', 'sort_order'];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }
}
