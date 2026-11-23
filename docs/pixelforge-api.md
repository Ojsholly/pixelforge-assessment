# Pixelforge API contract

What this app actually consumes of the fictional provider
(<https://pixelforge.promptedit.dev/docs>). All findings below were verified
live against the real API — see `internal/audit-findings.md`.

## Endpoints used

| Endpoint                                | Purpose in app                                         | Notes                                                                                                                 |
| --------------------------------------- | ------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------- |
| `GET /v1/pricing`                       | Live rates for estimates + `rates_snapshot` at submit  | Public; cached 5 min (wire array — see `serializable_classes` note in [architecture](architecture.md))                |
| `POST /v1/generations`                  | Submit a generation                                    | `Idempotency-Key` header; `metadata.local_generation_id` passed for fallback matching; `202` + full generation object |
| `GET /v1/generations/{id}`              | Reconcile pending generations; manual "Refresh status" | Other accounts' ids → 404                                                                                             |
| `GET /v1/usage?start_date&end_date`     | Admin reconciliation (max range enforced by provider)  | `{data[]… , total{…}}` shape                                                                                          |
| _(inbound)_ `POST /webhooks/pixelforge` | Generation lifecycle events                            | `Pixelforge-Signature: t={ts},v1={hmac}`                                                                              |

`GET /v1/generations` (list) is implemented by the provider but not consumed —
reconciliation targets known ids individually.

## Idempotency

- Same `Idempotency-Key` + same params → `202` + `Idempotent-Replayed: true`
    - the original `gen_` id.
- Same key + different params → `409` `idempotency_key_reused`.

## Errors

| Provider response                            | App handling                                                    |
| -------------------------------------------- | --------------------------------------------------------------- |
| `400 content_policy_violation` (`[blocked]`) | surfaced as provider error on the generation                    |
| `422 validation_failed` (`param` + `errors`) | surfaced per-field                                              |
| `401 invalid_api_key`                        | `ProviderException` → provider error                            |
| `503 overloaded` + `Retry-After`             | honored via `retryAfterSeconds` on `ProviderException`          |
| `generation.failed` webhook                  | `error{code,message}` recorded; **costs $0**, full hold release |

## Signature scheme

```
Pixelforge-Signature: t={unix_ts},v1={hex_hmac_sha256("{ts}.{raw_body}", secret)}
```

Verified on raw bytes before decode; ±300 s timestamp tolerance; constant-time
compare.

## Test prompts (must stay usable)

| Prompt prefix | Behavior                                                                         |
| ------------- | -------------------------------------------------------------------------------- |
| `[fail]`      | Accepted, then `generation.failed` — costs $0                                    |
| `[slow]`      | Webhook arrives ~60–90 s after acceptance                                        |
| `[duplicate]` | Provider delivers the same event more than once — deduped by `provider_event_id` |
