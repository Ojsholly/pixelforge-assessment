---
paths:
    - 'app/Http/Controllers/**'
---

# Controllers

## Index queries use pipeline filters

List endpoints compose queries through the UsesPipeline trait: the controller returns filters() of handle(Builder, Closure) classes scoped under app/Filters/<Domain>. Sorting goes through Filters\Sort (whitelisted columns, expressions map for computed orderings) — never trust raw sort params.

## Authorize via policies and Gate::authorize

Model authorization lives in app/Policies. Controllers call Gate::authorize with the ability name and model instance; do not use the can middleware or per-user can() checks at call sites.
