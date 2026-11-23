---
paths:
  - 'app/Contracts/**'
---

# Contracts

## Domain services and provider adapters
External integrations sit behind an interface in app/Contracts with the adapter in app/Services/<Vendor> (ImageGenerationProvider -> Services/Pixelforge/PixelforgeProvider). Domain logic lives in services with descriptive methods.
