---
paths:
    - 'app/Policies/**'
---

# Policies

## Authorize via policies and Gate::authorize

Model authorization lives in app/Policies. Controllers call Gate::authorize with the ability name and model instance; do not use the can middleware or per-user can() checks at call sites.
