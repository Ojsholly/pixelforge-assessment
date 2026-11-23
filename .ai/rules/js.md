---
paths:
    - 'resources/js/**'
---

# Js

## Call backend routes via Wayfinder helpers

Frontend code hits backend routes through generated Wayfinder helpers — @/routes/... for named routes and @/actions/... controller helpers for form actions — never hardcode URLs or paths. Regenerate with php artisan wayfinder:generate after route changes.
