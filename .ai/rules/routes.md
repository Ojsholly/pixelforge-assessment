---
paths:
    - 'routes/**'
---

# Routes

## Inline throttle with per-route keys

Rate limits are route-level ->middleware(throttle:N,M,<route_key>); application routes carry a distinct key per route (the starter-kit settings throttle is a bare exception). Named RateLimiter::for() definitions live in FortifyServiceProvider for auth flows only.
