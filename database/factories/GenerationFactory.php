<?php

namespace Database\Factories;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Models\Generation;
use App\Models\User;
use App\States\Generation\Failed;
use App\States\Generation\Processing;
use App\States\Generation\Queued;
use App\States\Generation\Succeeded;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider_id' => 'gen_'.Str::ulid(),
            'idempotency_key' => (string) Str::uuid(),
            'status' => Queued::class,
            'prompt' => fake()->sentence(10),
            'size' => ImageSize::Square,
            'quality' => ImageQuality::Medium,
            'reference_images' => [],
            'rates_snapshot' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'estimated_tokens' => [
                'input_text_min' => 10,
                'input_text_max' => 14,
                'input_image' => 0,
                'output' => 1100,
                'reasoning_min' => 200,
                'reasoning_max' => 2000,
            ],
            'estimated_min_user_cost' => 58_187_500,
            'estimated_max_user_cost' => 86_337_500,
            'reserved_amount' => 0,
            'actual_usage' => null,
            'actual_provider_cost' => null,
            'actual_user_cost' => null,
            'image_url' => null,
            'image_url_expires_at' => null,
            'error_code' => null,
            'error_message' => null,
            'provider_created_at' => now(),
            'started_at' => null,
            'completed_at' => null,
        ];
    }

    public function processing(): static
    {
        return $this->state(fn (): array => [
            'status' => Processing::class,
            'started_at' => now(),
        ]);
    }

    public function succeeded(): static
    {
        return $this->state(fn (): array => [
            'status' => Succeeded::class,
            'started_at' => now()->subSeconds(10),
            'completed_at' => now(),
            'actual_usage' => [
                'input_text_tokens' => 12,
                'input_image_tokens' => 0,
                'output_tokens' => 1100,
                'reasoning_tokens' => 1000,
                'total_tokens' => 2112,
            ],
            'actual_provider_cost' => 56_560_000,
            'actual_user_cost' => 70_700_000,
            'image_url' => 'https://pixelforge.promptedit.dev/images/example.png',
            'image_url_expires_at' => now()->addDay(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => Failed::class,
            'started_at' => now()->subSeconds(5),
            'completed_at' => now(),
            'actual_provider_cost' => 0,
            'actual_user_cost' => 0,
            'error_code' => 'generation_failed',
            'error_message' => 'Generation failed during processing.',
        ]);
    }
}
