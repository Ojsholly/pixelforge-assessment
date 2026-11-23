---
paths:
  - 'app/Filters/**'
---

# Filters

## Index queries use pipeline filters
List endpoints compose queries through the UsesPipeline trait: the controller returns filters() of handle(Builder, Closure) classes scoped under app/Filters/<Domain>. Sorting goes through Filters\Sort (whitelisted columns, expressions map for computed orderings) — never trust raw sort params.
