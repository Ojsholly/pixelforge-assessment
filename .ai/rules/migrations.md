---
paths:
    - 'database/migrations/**'
---

# Migrations

## Enum columns are strings plus model casts

Store enum/state-backed fields as string() columns with the matching cast on the model — a backed enum (direction, type, event_type) or a spatie State subclass (generations.status -> GenerationState); do not use ->enum() columns.
