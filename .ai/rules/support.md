---
paths:
    - 'app/Support/**'
---

# Support

## Serialize for Inertia via Presenter classes

Models are serialized for Inertia props by Presenter classes in app/Support (GenerationPresenter, TransactionPresenter). There is no app/Http/Resources layer; add presenter methods instead of shaping props inline in controllers.
