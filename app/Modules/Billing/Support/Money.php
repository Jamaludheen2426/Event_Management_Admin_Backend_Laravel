<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

/**
 * Billing arithmetic, as v1 did it: amounts rounded to paise, GST added on top
 * (not included) and split into CGST + SGST halves, totals in words for INR
 * ("One Thousand One Hundred Eighty Rupees Only").
 */
final class Money
{
    private const ONES = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven',
        'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];

    private const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    public static function round(float|int|string|null $value): float
    {
        return round((float) $value, 2);
    }

    /**
     * The price of a plan with tax on top (plan cards, subscription amount).
     *
     * @return array{subtotal: float, tax_rate: float, tax_amount: float, total: float, tax_inclusive: false}
     */
    public static function withTax(float|string $price, float|string|null $taxRate): array
    {
        $base = self::round($price);
        $rate = (float) ($taxRate ?? 0);
        $tax = self::round($base * $rate / 100);

        return ['subtotal' => $base, 'tax_rate' => $rate, 'tax_amount' => $tax, 'total' => self::round($base + $tax), 'tax_inclusive' => false];
    }

    /**
     * Invoice totals.
     *
     * @return array{subtotal: float, discount_amount: float, tax_rate: float, tax_amount: float, tax_breakdown: list<array{label: string, rate: float, amount: float}>, tax_inclusive: bool, total: float}
     */
    public static function invoiceTotals(float|string $subtotal, float|string $discount = 0, float|string|null $taxRate = 18): array
    {
        $sub = self::round($subtotal);
        $disc = self::round($discount);
        $taxable = self::round($sub - $disc);
        $rate = (float) ($taxRate ?? 0);
        $tax = self::round($taxable * $rate / 100);
        $half = self::round($tax / 2);

        return [
            'subtotal' => $sub,
            'discount_amount' => $disc,
            'tax_rate' => $rate,
            'tax_amount' => $tax,
            'tax_breakdown' => $tax > 0 ? [
                ['label' => 'CGST', 'rate' => self::round($rate / 2), 'amount' => $half],
                ['label' => 'SGST', 'rate' => self::round($rate / 2), 'amount' => self::round($tax - $half)],
            ] : [],
            'tax_inclusive' => false,
            'total' => self::round($taxable + $tax),
        ];
    }

    /** INR amounts in Indian words (lakh, crore); null for other currencies. */
    public static function inWords(float|string $value, string $currency = 'INR'): ?string
    {
        if (mb_strtoupper($currency) !== 'INR') {
            return null;
        }
        $amount = self::round(abs((float) $value));
        $whole = (int) floor($amount);
        $paise = (int) round(($amount - $whole) * 100);
        if ($whole === 0 && $paise === 0) {
            return 'Zero Rupees Only';
        }

        $parts = [];
        foreach ([
            'Crore' => intdiv($whole, 10000000),
            'Lakh' => intdiv($whole % 10000000, 100000),
            'Thousand' => intdiv($whole % 100000, 1000),
        ] as $label => $n) {
            if ($n > 0) {
                $parts[] = self::underThousand($n).' '.$label;
            }
        }
        $rest = $whole % 1000;
        if ($rest > 0) {
            $parts[] = self::underThousand($rest);
        }

        $rupees = $parts !== [] ? implode(' ', $parts).' '.($whole === 1 ? 'Rupee' : 'Rupees') : '';
        $paiseText = $paise > 0 ? self::underThousand($paise).' '.($paise === 1 ? 'Paisa' : 'Paise') : '';

        return $rupees !== '' && $paiseText !== '' ? "{$rupees} and {$paiseText} Only" : ($rupees ?: $paiseText).' Only';
    }

    private static function underThousand(int $n): string
    {
        return match (true) {
            $n === 0 => '',
            $n < 20 => self::ONES[$n],
            $n < 100 => self::TENS[intdiv($n, 10)].($n % 10 ? ' '.self::ONES[$n % 10] : ''),
            default => self::ONES[intdiv($n, 100)].' Hundred'.($n % 100 ? ' '.self::underThousand($n % 100) : ''),
        };
    }
}
