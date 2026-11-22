<?php

use App\Support\Money;

it('parses exact USD decimal strings into nanodollars without floats', function (string $usd, int $expected): void {
    expect(Money::fromUsdString($usd))->toBe($expected);
})->with([
    'zero' => ['0', 0],
    'zero with 9 decimals' => ['0.000000000', 0],
    'one nanodollar' => ['0.000000001', 1],
    'one token at $5/1M' => ['0.000005000', 5_000],
    'pixelforge example cost' => ['0.046125000', 46_125_000],
    'worked example max' => ['0.373875000', 373_875_000],
    'whole dollar' => ['5', 5_000_000_000],
    'two decimals' => ['12.50', 12_500_000_000],
    'negative' => ['-1.25', -1_250_000_000],
]);

it('rejects invalid or over-precision USD strings', function (string $invalid): void {
    expect(fn () => Money::fromUsdString($invalid))->toThrow(InvalidArgumentException::class);
})->with([
    'empty' => [''],
    'letters' => ['abc'],
    '10 decimals' => ['0.0000000001'],
]);

it('formats nanodollars to exact 9-decimal USD strings and display strings', function (): void {
    expect(Money::toUsdString(46_125_000))->toBe('0.046125000')
        ->and(Money::toUsdString(0))->toBe('0.000000000')
        ->and(Money::toUsdString(-5_000_000_000))->toBe('-5.000000000')
        ->and(Money::formatDisplay(5_000_000_000))->toBe('$5.00')
        ->and(Money::formatDisplay(46_125_000))->toBe('$0.046125')
        ->and(Money::formatDisplay(0))->toBe('$0.00');
});

it('applies 25% markup using exact half-up integer division', function (): void {
    // Exact cases
    expect(Money::applyMarkup(373_875_000))->toBe(467_343_750)
        ->and(Money::applyMarkup(5_000))->toBe(6_250)
        ->and(Money::applyMarkup(0))->toBe(0);

    // Half-up rounding cases: 2 * 125 = 250 -> +50 = 300 / 100 = 3 (2.5 rounds up to 3)
    expect(Money::applyMarkup(2))->toBe(3)
        // 1 * 125 = 125 -> +50 = 175 / 100 = 1 (1.25 rounds down to 1)
        ->and(Money::applyMarkup(1))->toBe(1);
});
