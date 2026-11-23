---
paths:
    - 'app/Http/**'
---

# Http

## Redirect with to_route()

Controllers redirect via to_route(named.route) rather than redirect()->route() or url(); back() and redirect('/') appear only where the flow requires them (return-to-form, logout-after-delete).
