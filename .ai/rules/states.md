---
paths:
    - 'app/States/**'
---

# States

## Generation lifecycle via spatie model-states

Generation status is a State subclass tree (Queued/Processing/Succeeded/Failed) with an explicit allowTransition whitelist in GenerationState::config(). Transition through the state API — check canTransitionTo() before transitionTo() — never write the status column directly.
