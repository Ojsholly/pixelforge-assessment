---
paths:
  - 'app/Services/**'
---

# Services

## Domain services and provider adapters
Domain logic lives in services with descriptive methods (WalletService::purchase, PricingCalculator::estimate). External integrations sit behind an interface in app/Contracts with the adapter in app/Services/<Vendor> (ImageGenerationProvider -> Services/Pixelforge/PixelforgeProvider). Flows call services directly — there is no event/listener layer.
