---
paths:
  - 'app/Data/**'
---

# Data

## Provider payloads are readonly DTOs
External/provider payloads are final readonly classes with a static fromArray() factory and toArray() (ProviderGenerationData, CostEstimateData). Do not pass raw provider arrays into the domain.
