<?php

namespace App\Data\Pixelforge;

final readonly class ProviderUsageData
{
    public function __construct(
        public int $inputTextTokens,
        public int $inputImageTokens,
        public int $outputTokens,
        public int $reasoningTokens,
        public int $totalTokens,
    ) {}

    /**
     * @param  array<string, int|string>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            inputTextTokens: (int) $payload['input_text_tokens'],
            inputImageTokens: (int) $payload['input_image_tokens'],
            outputTokens: (int) $payload['output_tokens'],
            reasoningTokens: (int) $payload['reasoning_tokens'],
            totalTokens: (int) $payload['total_tokens'],
        );
    }

    /**
     * @return array{
     *     input_text_tokens: int,
     *     input_image_tokens: int,
     *     output_tokens: int,
     *     reasoning_tokens: int,
     *     total_tokens: int
     * }
     */
    public function toArray(): array
    {
        return [
            'input_text_tokens' => $this->inputTextTokens,
            'input_image_tokens' => $this->inputImageTokens,
            'output_tokens' => $this->outputTokens,
            'reasoning_tokens' => $this->reasoningTokens,
            'total_tokens' => $this->totalTokens,
        ];
    }
}
