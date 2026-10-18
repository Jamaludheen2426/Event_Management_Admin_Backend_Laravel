<?php

declare(strict_types=1);

namespace App\Modules\Platform\Actions\Reference;

use App\Core\Crud\RecordWriter;
use App\Core\Exceptions\ApiException;
use App\Modules\Platform\Models\Currency;
use Illuminate\Database\Eloquent\Model;

/** @extends RecordWriter<Currency> */
final class CurrencyWriter extends RecordWriter
{
    protected function model(): string
    {
        return Currency::class;
    }

    protected function module(): string
    {
        return 'currencies';
    }

    protected function label(): string
    {
        return 'Currency';
    }

    protected function columns(): array
    {
        return [
            'name', 'code', 'symbol', 'exchange_rate', 'decimal_places', 'decimal_separator', 'thousand_separator',
            'symbol_position', 'space_between', 'is_default', 'is_active',
        ];
    }

    protected function assertUnique(array $values, ?int $exceptId): void
    {
        if (isset($values['code']) && $this->taken(Currency::query(), ['code' => $values['code']], $exceptId)) {
            throw ApiException::conflict('Currency with this code already exists');
        }
    }

    protected function beforeDelete(Model $model): void
    {
        /** @var Currency $model */
        if ($model->is_default) {
            throw ApiException::badRequest('Cannot delete default currency');
        }
    }
}
