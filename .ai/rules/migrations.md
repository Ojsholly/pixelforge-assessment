---
paths:
    - 'database/migrations/**'
---

# Migrations

## Enum columns are strings plus model casts

Store enum-backed fields as string() columns with a backed-enum cast on the model (status, direction, type, event_type); do not use ->enum() columns.
