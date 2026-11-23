---
paths:
  - 'resources/js/**'
---

# Js

## Call backend routes via Wayfinder helpers
Frontend code hits backend routes through generated Wayfinder helpers imported from @/routes/... — never hardcode URLs or paths. Regenerate with php artisan wayfinder:generate after route changes.
