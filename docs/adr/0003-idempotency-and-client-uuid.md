# ADR 0003: Idempotency-Key on every write, client UUID on every offline-capable record

- Status: accepted
- Date: 2026-10-18
- Phase: 0

## Context

Internet drops mid-order are normal in the shop (architecture.md sections 5 and 9). Pads keep an
IndexedDB outbox and replay writes when the connection returns; a replay must never create a second
order, a second payment or a second count. Rule 7 requires an `Idempotency-Key` and a client UUID on
every write.

## Decision

- Shared middleware `idempotency` on every `POST/PUT/PATCH/DELETE` under `/api/v1` except the public
  allowlist (`login`, `health`, short-code verify). Scope of a key is `(branch_id, user_id, key)`.
- Concurrency guard: Redis `SET NX EX` on the key before the handler runs; a second request with the
  same key while the first is in flight receives `409` and retries; after completion the stored response
  snapshot (`idempotency_keys.response_status`, `response_body`) is replayed with identical body.
- Request fingerprint (`request_hash`) is stored; the same key with a different body is `422`.
- Tables that an offline client can create carry `client_uuid` with a unique index
  `(branch_id, client_uuid)`: orders, order_lines, payments, stock_counts, production_batches,
  transfers, purchases. This makes a replay safe even if the key store was flushed.
- Keys expire after 24 hours.

## Consequences

- Positive: outbox replay is safe; duplicate taps on a slow pad are harmless; the invariant is
  mechanically checked so new endpoints cannot forget it.
- Negative: every write test needs an idempotency case; Redis is a hard dependency of the API.

## Enforcement

`tests/Invariants/IdempotencyCoverageTest.php` (every write route has the middleware; every route in
`tests/Support/write_endpoints.php` passes `assertIdempotent()`), `scripts/check_invariants.py --idempotency`
in the `invariants` CI job, unique indexes in migrations.

## Alternatives considered

- Idempotency only on orders and payments: leaves counts, batches and transfers exposed to the same
  replay. Rejected.
- Client UUID only, no header: cannot cover action endpoints such as `POST /orders/{id}/send`. Rejected.
