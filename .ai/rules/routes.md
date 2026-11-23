---
paths:
  - 'routes/**'
---

# Routes

## Inline throttle with per-route keys
Rate limits are route-level ->middleware(throttle:N,M,<route_key>) with a distinct key per route. Named RateLimiter::for() definitions live in FortifyServiceProvider for auth flows only.
