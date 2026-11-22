<?php

namespace App\Contracts;

use App\Data\Pixelforge\CreateGenerationInput;
use App\Data\Pixelforge\PricingData;
use App\Data\Pixelforge\ProviderGenerationData;
use App\Data\Pixelforge\UsageReportData;

interface ImageGenerationProvider
{
    public function getPricing(): PricingData;

    public function createGeneration(CreateGenerationInput $input): ProviderGenerationData;

    public function getGeneration(string $providerId): ProviderGenerationData;

    public function getUsage(string $startDate, string $endDate): UsageReportData;

    public function verifyWebhookSignature(string $rawPayload, ?string $signatureHeader, int $toleranceSeconds = 300): bool;
}
