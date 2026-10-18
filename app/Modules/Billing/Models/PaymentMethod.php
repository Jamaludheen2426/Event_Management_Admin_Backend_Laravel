<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Core\Database\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * How a client pays: a provider's saved card (a token, never the card), or a
 * manual record (UPI ID, bank + last 4 digits, cash).
 *
 * @property int $id
 * @property int $client_id
 * @property string $gateway
 * @property string|null $gateway_customer_id
 * @property string|null $gateway_payment_method_id
 * @property string $method_type
 * @property string|null $brand
 * @property string|null $last4
 * @property int|null $exp_month
 * @property int|null $exp_year
 * @property string|null $holder_name
 * @property string|null $upi_id
 * @property string|null $bank_name
 * @property string|null $account_last4
 * @property string|null $ifsc
 * @property bool $is_default
 * @property bool $is_verified
 * @property string $status
 * @property Carbon|null $created_at
 */
final class PaymentMethod extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'client_id', 'gateway', 'gateway_customer_id', 'gateway_payment_method_id', 'method_type', 'brand', 'last4',
        'exp_month', 'exp_year', 'holder_name', 'upi_id', 'bank_name', 'account_last4', 'ifsc', 'is_default',
        'is_verified', 'status',
    ];

    protected $hidden = ['gateway_customer_id', 'gateway_payment_method_id'];

    protected function casts(): array
    {
        return [
            'exp_month' => 'integer',
            'exp_year' => 'integer',
            'is_default' => 'boolean',
            'is_verified' => 'boolean',
        ];
    }

    /** A card past its expiry month (compared in UTC, so every server agrees). */
    public function isExpired(): bool
    {
        if ($this->exp_month === null || $this->exp_year === null) {
            return false;
        }

        return ! Carbon::now('UTC')->lt(Carbon::create($this->exp_year, $this->exp_month, 1, 0, 0, 0, 'UTC')?->addMonth());
    }

    /** The one wording of a method, also snapshotted onto payments. */
    public function label(): string
    {
        return match ($this->method_type) {
            'cash' => 'Cash',
            'upi' => 'UPI · '.$this->upi_id,
            'bank_transfer' => ($this->bank_name ?? 'Bank account').($this->account_last4 !== null ? ' ending in '.$this->account_last4 : ''),
            default => $this->last4 !== null
                ? (ucfirst((string) $this->brand) ?: 'Card').' ending in '.$this->last4
                : (ucfirst($this->method_type) ?: 'Payment method'),
        };
    }
}
