<?php

namespace App\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;

final class Money
{
    public const int NANODOLLARS_PER_USD = 1_000_000_000;

    public const int NANODOLLARS_PER_CENT = 10_000_000;

    public const int DEFAULT_MARKUP_PERCENT = 25;

    /**
     * Parse an exact USD decimal string (up to 9 decimal places) into integer nanodollars.
     * Never uses floating-point arithmetic.
     */
    public static function fromUsdString(string $usd): int
    {
        $trimmed = trim($usd);

        if ($trimmed === '' || ! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $trimmed, $matches)) {
            throw new InvalidArgumentException("Invalid USD amount string: [{$usd}].");
        }

        $negative = $matches[1] === '-';
        $whole = (int) $matches[2];
        $fracRaw = $matches[3] ?? '';

        if (Str::length($fracRaw) > 9) {
            throw new InvalidArgumentException("USD amount exceeds 9 decimal places (nanodollars): [{$usd}].");
        }

        $fracPadded = Str::padRight($fracRaw, 9, '0');
        $nanodollars = ($whole * self::NANODOLLARS_PER_USD) + (int) $fracPadded;

        return $negative ? -$nanodollars : $nanodollars;
    }

    /**
     * Format integer nanodollars as an exact 9-decimal USD string (matching Pixelforge API).
     */
    public static function toUsdString(int $nanodollars): string
    {
        $negative = $nanodollars < 0;
        $abs = abs($nanodollars);

        $whole = intdiv($abs, self::NANODOLLARS_PER_USD);
        $frac = Str::padLeft((string) ($abs % self::NANODOLLARS_PER_USD), 9, '0');

        return ($negative ? '-' : '')."{$whole}.{$frac}";
    }

    /**
     * Format integer nanodollars for human-readable UI display (e.g. "$0.0461", "$5.00").
     * Truncates beyond $maxDecimals rather than rounding.
     */
    public static function formatDisplay(int $nanodollars, int $minDecimals = 2, int $maxDecimals = 6): string
    {
        $negative = $nanodollars < 0;
        $exact = ltrim(self::toUsdString(abs($nanodollars)), '+');
        [$whole, $frac] = explode('.', $exact, 2);

        $trimmedFrac = rtrim(substr($frac, 0, $maxDecimals), '0');

        if (Str::length($trimmedFrac) < $minDecimals) {
            $trimmedFrac = Str::padRight($trimmedFrac, $minDecimals, '0');
        }

        $formattedWhole = number_format((int) $whole);
        $prefix = $negative ? '-$' : '$';

        return $trimmedFrac === '' ? "{$prefix}{$formattedWhole}" : "{$prefix}{$formattedWhole}.{$trimmedFrac}";
    }

    /**
     * Apply percentage markup using exact half-up integer division.
     * Safe from int64 overflow for amounts up to ~$73,000,000 USD at 25% markup.
     */
    public static function applyMarkup(int $nanodollars, int $markupPercent = self::DEFAULT_MARKUP_PERCENT): int
    {
        if ($nanodollars < 0) {
            throw new InvalidArgumentException('Cannot apply markup to a negative amount.');
        }

        if ($markupPercent <= -100) {
            throw new InvalidArgumentException('Markup percent must be greater than -100.');
        }

        $multiplier = 100 + $markupPercent;

        return intdiv(($nanodollars * $multiplier) + 50, 100);
    }
}
