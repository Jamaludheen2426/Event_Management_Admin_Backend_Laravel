<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * Mobile numbers as v1 compared them: digits only, and a number with a country
 * prefix also matches its last ten digits ("919876543210" = "9876543210").
 */
final class Phone
{
    public static function digits(mixed $value): string
    {
        return (string) preg_replace('/\D/', '', is_scalar($value) ? (string) $value : '');
    }

    /** @return list<string> the stored forms that count as this number */
    public static function candidates(mixed $value): array
    {
        $digits = self::digits($value);

        return $digits === '' ? [] : array_values(array_unique([$digits, substr($digits, -10)]));
    }

    /**
     * Narrow a query to rows whose $column is this number, in either form:
     * "9876543210" finds "919876543210" and the other way round.
     *
     * @template TBuilder of \Illuminate\Contracts\Database\Query\Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function whereMatches($query, string $column, mixed $value)
    {
        $digits = self::digits($value);
        $last10 = substr($digits, -10);

        return $query->where(static function ($q) use ($column, $digits, $last10): void {
            $q->whereIn($column, $digits === '' ? ['__none__'] : self::candidates($digits));
            if (strlen($last10) === 10) {
                $q->orWhere($column, 'like', '%'.$last10);
            }
        });
    }

    public static function isPlausible(string $digits): bool
    {
        $length = strlen($digits);

        return $length >= 7 && $length <= 15;
    }
}
