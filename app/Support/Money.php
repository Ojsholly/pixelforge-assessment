<?php

namespace App\Support;

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

        if (strlen($fracRaw) > 9) {
            throw new InvalidArgumentException("USD amount exceeds 9 decimal places (nanodollars): [{$usd}].");
        }

        $fracPadded = str_pad($fracRaw, 9, '0', STR_PAD_RIGHT);
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
        $frac = str_pad((string) ($abs % self::NANODOLLARS_PER_USD), 9, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '')."{$whole}.{$frac}";
    }

    /**
     * Format integer nanodollars for human-readable UI display (e.g. "$0.0461", "$5.00").
     */
    public static function formatDisplay(int $nanodollars, int $minDecimals = 2, int $maxDecimals = 6): string
    {
        $negative = $nanodollars < 0;
        $exact = ltrim(self::toUsdString(abs($nanodollars)), '+');
        [$whole, $frac] = explode('.', $exact, 2);

        $trimmedFrac = rtrim(substr($frac, 0, $maxDecimals), '0');

        if (strlen($trimmedFrac) < $minDecimals) {
            $trimmedFrac = str_pad($trimmedFrac, $minDecimals, '0', STR_PAD_RIGHT);
        }

        $formattedWhole = number_format((int) $whole);
        $prefix = $negative ? '-$' : '$';

        return $trimmedFrac === '' ? "{$prefix}{$formattedWhole}" : "{$prefix}{$formattedWhole}.{$trimmedFrac}";
    }

    /**
     * Apply percentage markup using exact half-up integer division.
     */
    public static function applyMarkup(int $nanodollars, int $markupPercent = self::DEFAULT_MARKUP_PERCENT): int
    {
        if ($nanodollars < 0) {
            throw new InvalidArgumentException('Cannot apply markup to a negative amount.');
        }

        $multiplier = 100 + $markupPercent;

        return intdiv(($nanodollars * $multiplier) + 50, 100);
    }
}
