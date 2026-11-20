# ADR 0030: An Idempotency-Key is kept only while the outcome is unknown

- Status: **accepted** (phase-6/idempotency-keys, 2026-11-20)
- Date: 2026-11-20
- Phase: 6 (a fix after the claim)
- Builds on: ADR 0003 (idempotency and client UUIDs)

## Context

ADR 0003 makes every write carry an Idempotency-Key. The API stores the answer for the key for 24 hours and replays it: successes and
refusals (4xx) alike. It never stores a 5xx, and it does not store a refusal of a request that carried a secret (a PIN).

The web's key holder (`useIdempotencyKey`) reused the key for the same payload until `done()`, and most forms called `done()` only on a
success. So a refusal was kept. For example, the manager taps Confirm on a transfer the store cannot cover and gets "not enough at the
source". The store buys more, and the manager taps Confirm again: the same key goes out, and the API replays the stored refusal until the
page is reloaded. The same happened with "start a shift first" on the production card, purchases, device enrolment and every admin form.

A review of the key holders (2026-11-20) found four more:

- **The production board's drops took a new key for every write.** A drop again after a lost answer was a second write, not a replay.
- **The refund and void dialogs kept their key after the person changed the request** (another payment, another reason). The API then
  answered `422 idempotency.key_reused` instead of doing the new request.
- **The transfer request sheet kept one client_uuid for its whole life.** The API answers a client_uuid it has already seen with the
  transfer written then (200), whatever the new body asks. So after a lost answer, a request the person had changed was shown as sent,
  while the API held the first one.
- **The shift bar's start key was named "start" for every station.** A start at another station after a lost answer reused the key, and
  the API answered `422 key_reused`.

## Decision

1. **One rule, `outcomeUnknown(error)`** (`lib/api/idempotency.ts`). The outcome of a write is unknown in three cases, and only then is its
   key kept for the retry:
   - no answer: a network failure or a timeout (status 0);
   - a server error (5xx);
   - the same key still running (`idempotency.in_flight`).

   Any other answer is definite: a success, or a refusal that wrote nothing. Something that is not an API answer (a bug before the call)
   counts as unknown, because keeping a key can never write twice. The pay sheet's `keepsKey` is now this same function.
2. **`KeySlot` (one form's write) and `KeyRing` (named writes).** Both keep a key while the outcome is unknown. Both let it go on
   `done()` after a success and on `failed(error)` after a definite refusal. `useIdempotencyKey()` now returns `failed` beside `keyFor`
   and `done`, and every form that uses it passes `failed` as its `onError`, or calls it in its catch. A changed payload still always gets
   a new key.

   One exception: the item page's save is two writes (the changes, then the price) whose failure does not say which one failed. It keeps
   both keys, as before. The item's version refuses a second edit anyway.
3. **The payload a key is chosen by mirrors the server's fingerprint.** A PIN is left out (the API's `SECRET_FIELDS`). So retyping the PIN
   after a lost answer is still the same request, and changing anything else is a new one. The void and refund dialogs now choose their key
   from their request this way. Before, they kept one attempt key until a refusal.
4. **The production board keeps one key per write and subject** (`accept:{request}`, `start:{request}` with its batch client_uuid,
   `confirm:{batch}`) across the retries of the same move.
5. **The shift bar names a start by its station** (`start:{station}`) and uses the same rule.
6. **The transfer request sheet** keeps its client_uuid across an unknown outcome only. It takes a new one after a refusal, and after a
   success when the person starts another request. It compares the API's answer with what it sent: the item, the places and the quantity
   (`answeredAsSent`). When they differ, it shows the transfer the API holds and says "Your first request had already gone through, for
   {qty}. Your change was not sent." in English and Amharic.

## Consequences

- A refusal is checked again on the next tap once its cause is fixed. No reload is needed.
- A retry after a lost answer replays the first write everywhere, the production board included. Client UUIDs still guard the ledger rows
  in the rare case where a key is let go and the write had in fact happened.
- The shifts screens' own key maps (`useKeys`, the counts, cash, notes and approval forms) already let a key go on any answer but a lost
  connection. They are left as they are: a 5xx is never stored, so a new key after one is harmless.
- Tests:
  - `e2e/p6/screens-idempotency-logic.spec.ts` covers the rule, the slot, the ring and the answer check.
  - `e2e/p6/screens-idempotency.spec.ts` runs on the CI stack. It covers a refused confirm that goes through on the same tap once the store
    bought more, and a lost answer followed by an edited resend: one transfer, and the sheet says the change was not sent.
