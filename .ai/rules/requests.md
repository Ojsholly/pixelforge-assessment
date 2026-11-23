---
paths:
  - 'app/Http/Requests/**'
---

# Requests

## Validate HTTP input in Form Requests
HTTP input validates in dedicated FormRequest classes grouped by feature subdirectory (Http/Requests/<Domain>), with authorize(): bool. Validator::make() is used only inside Fortify action classes, which receive raw input arrays.
