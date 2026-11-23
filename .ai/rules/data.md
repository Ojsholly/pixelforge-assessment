---
paths:
    - 'app/Data/**'
---

# Data

## Provider payloads are readonly DTOs

External/provider payloads are final readonly classes — inbound payloads hydrate via static fromArray() factories (ProviderGenerationData), outbound serialization exposes toArray() where needed (CostEstimateData). Do not pass raw provider arrays into the domain.
