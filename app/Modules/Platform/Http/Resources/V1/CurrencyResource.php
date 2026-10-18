<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Resources\V1;

use App\Core\Http\Resources\V1Resource;
use App\Modules\Platform\Models\Currency;
use Illuminate\Http\Request;

/**
 * v1 sent exchange_rate as a DECIMAL string ("1.0000").
 *
 * @mixin Currency
 *
 * @property Currency $resource
 */
final class CurrencyResource extends V1Resource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $currency = $this->resource;

        return [
            'id' => $currency->id,
            'name' => $currency->name,
            'code' => $currency->code,
            'symbol' => $currency->symbol,
            'exchange_rate' => number_format((float) $currency->getAttribute('exchange_rate'), 4, '.', ''),
            'decimal_places' => $currency->getAttribute('decimal_places'),
            'decimal_separator' => $currency->getAttribute('decimal_separator'),
            'thousand_separator' => $currency->getAttribute('thousand_separator'),
            'symbol_position' => $currency->getAttribute('symbol_position'),
            'space_between' => $currency->getAttribute('space_between'),
            'is_default' => $currency->is_default,
            'is_active' => $this->legacyActive($currency->is_active, $currency->approval_status),
            'created_by' => $currency->getAttribute('created_by'),
            'updated_by' => $currency->getAttribute('updated_by'),
            'created_at' => $this->isoDate($currency->created_at),
            'updated_at' => $this->isoDate($currency->updated_at),
            ...$this->pendingApprovalField(),
        ];
    }
}
