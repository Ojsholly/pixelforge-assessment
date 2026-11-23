---
paths:
    - 'tests/**'
---

# Tests

## Fake all outbound HTTP; shared helpers in Pest.php

Every outbound HTTP call must be faked per-test (Http::fake); a preventStrayRequests guard scoped to Feature tests in Pest.php throws on unfaked calls. Shared test helpers (seedPricingCacheAndKey) are defined in tests/Pest.php, not duplicated per file. Assert Inertia pages via assertInertia() callbacks.
