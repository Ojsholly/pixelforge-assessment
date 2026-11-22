<?php

use App\Support\PromptSanitizer;

it('preserves valid unicode prompts and pixelforge test keywords', function (): void {
    $prompt = '  [slow] A café in Tokyo at dusk ✨  ';

    expect(PromptSanitizer::sanitize($prompt))->toBe('[slow] A café in Tokyo at dusk ✨');
});

it('strips ascii control characters while keeping newlines and tabs', function (): void {
    $prompt = "Hello\x00\x07\x1F\n\tWorld\r\n";

    expect(PromptSanitizer::sanitize($prompt))->toBe("Hello\n\tWorld");
});

it('rejects empty, over-length, or invalid utf-8 prompts', function (string $invalid): void {
    expect(fn () => PromptSanitizer::sanitize($invalid))->toThrow(InvalidArgumentException::class);
})->with([
    'whitespace only' => ["   \n\t  "],
    'control chars only' => ["\x00\x01\x02"],
    'over 4000 chars' => [str_repeat('a', 4001)],
    'invalid utf8' => ["\xFF\xFE"],
]);
