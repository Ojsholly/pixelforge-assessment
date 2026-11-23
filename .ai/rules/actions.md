---
paths:
    - 'app/Actions/**'
---

# Actions

## Use-case orchestration lives in Action classes

Write domain use-cases as Action classes exposing a single public execute() method (CreateGenerationAction::execute, ApplyGenerationStateAction::execute). Keep controllers thin — they inject and invoke actions or services rather than containing business logic.
