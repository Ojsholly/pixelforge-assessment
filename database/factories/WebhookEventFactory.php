<?php

namespace Database\Factories;

use App\Enums\WebhookEventType;
use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookEvent>
 */
class WebhookEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $eventId = 'evt_'.Str::ulid();
        $generationId = 'gen_'.Str::ulid();

        return [
            'provider_event_id' => $eventId,
            'event_type' => WebhookEventType::GenerationSucceeded->value,
            'provider_generation_id' => $generationId,
            'delivery_attempt' => 1,
            'payload' => [
                'id' => $eventId,
                'object' => 'event',
                'type' => WebhookEventType::GenerationSucceeded->value,
                'data' => [
                    'object' => [
                        'id' => $generationId,
                        'status' => 'succeeded',
                    ],
                ],
            ],
            'processed_at' => null,
        ];
    }
}
