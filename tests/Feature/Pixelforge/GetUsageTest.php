<?php

use App\Contracts\ImageGenerationProvider;
use App\Data\Pixelforge\UsageReportData;
use App\Exceptions\ProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function (): void {
    seedPricingCacheAndKey();
});

function usageResponse(array $overrides = []): array
{
    return array_merge([
        'object' => 'usage',
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-02',
        'data' => [
            [
                'date' => '2026-10-01',
                'generations' => 1,
                'input_text_tokens' => 10,
                'input_image_tokens' => 0,
                'output_tokens' => 100,
                'reasoning_tokens' => 50,
                'cost_usd' => '0.080000000',
            ],
        ],
        'total' => [
            'generations' => 1,
            'input_text_tokens' => 10,
            'input_image_tokens' => 0,
            'output_tokens' => 100,
            'reasoning_tokens' => 50,
            'cost_usd' => '0.080000000',
        ],
    ], $overrides);
}

it('fetches GET /v1/usage with the requested UTC range', function (): void {
    Http::fake([
        'https://pixelforge.promptedit.dev/v1/usage*' => Http::response(usageResponse()),
    ]);

    $usage = app(ImageGenerationProvider::class)->getUsage('2026-10-01', '2026-10-02');

    expect($usage)->toBeInstanceOf(UsageReportData::class)
        ->and($usage->startDate)->toBe('2026-10-01')
        ->and($usage->totalGenerations)->toBe(1)
        ->and($usage->totalCostUsd)->toBe('0.080000000')
        ->and($usage->totalCostNanodollars)->toBe(80_000_000)
        ->and($usage->days)->toHaveCount(1)
        ->and($usage->days[0]->costNanodollars)->toBe(80_000_000);

    Http::assertSent(fn ($request): bool => Str::startsWith($request->url(), 'https://pixelforge.promptedit.dev/v1/usage')
        && $request['start_date'] === '2026-10-01'
        && $request['end_date'] === '2026-10-02');
});

it('accepts an explicitly empty usage window', function (): void {
    Http::fake([
        'https://pixelforge.promptedit.dev/v1/usage*' => Http::response(usageResponse([
            'data' => [],
            'total' => ['generations' => 0, 'cost_usd' => '0.000000000'],
        ])),
    ]);

    $usage = app(ImageGenerationProvider::class)->getUsage('2026-10-01', '2026-10-02');

    expect($usage->days)->toBe([])
        ->and($usage->totalCostNanodollars)->toBe(0);
});

it('rejects incomplete usage payloads as malformed instead of zero billing', function (array $payload): void {
    Http::fake([
        'https://pixelforge.promptedit.dev/v1/usage*' => Http::response($payload),
    ]);

    expect(fn () => app(ImageGenerationProvider::class)->getUsage('2026-10-01', '2026-10-02'))
        ->toThrow(ProviderException::class);
})->with([
    'missing data' => [fn (): array => usageResponse(['data' => null])],
    'missing total' => [fn (): array => usageResponse(['total' => null])],
    'total missing cost_usd' => [fn (): array => usageResponse(['total' => ['generations' => 1]])],
    'non-array day entry' => [fn (): array => usageResponse(['data' => ['not-an-object']])],
    'day missing cost' => [fn (): array => usageResponse(['data' => [['date' => '2026-10-01']]])],
]);

it('maps provider errors to ProviderException', function (): void {
    Http::fake([
        'https://pixelforge.promptedit.dev/v1/usage*' => Http::response([
            'error' => ['type' => 'authentication_error', 'code' => 'invalid_api_key', 'message' => 'Invalid API key.'],
        ], 401),
    ]);

    expect(fn () => app(ImageGenerationProvider::class)->getUsage('2026-10-01', '2026-10-02'))
        ->toThrow(ProviderException::class, 'Invalid API key.');
});

it('throws ProviderException on connection failure', function (): void {
    Http::fake([
        'https://pixelforge.promptedit.dev/v1/usage*' => fn () => throw new ConnectionException('Connection refused'),
    ]);

    expect(fn () => app(ImageGenerationProvider::class)->getUsage('2026-10-01', '2026-10-02'))
        ->toThrow(ProviderException::class);
});
