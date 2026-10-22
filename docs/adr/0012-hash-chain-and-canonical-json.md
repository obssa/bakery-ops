# ADR 0012: The hash chain of locked counts, and its canonical JSON

- Status: proposed (Phase 3, step 3.4; becomes accepted in the Phase 3 gate report)
- Date: 2026-10-25
- Phase: 3

## Context

Rule 5 says every count is confirmed, explained, approved, locked and hash-chained, so that a changed record is detectable.
The people who would change one are the people the system has to trust with the database: the hosting account, a developer with
a console, a restored backup edited by hand. Two facts shape the design. The hash must be re-computable years later, on a
different PHP, by a different library, perhaps by a script that is not PHP at all, so what is hashed cannot be "whatever
`json_encode` printed that day" (traps.md, "Hash chain"). And the live host is a shared MariaDB where the chain's sequence
number is claimed by many requests at once, so it cannot depend on a lock on a row that may not exist (traps.md, "First-row
race").

## Decision

1. **The chain is the locked counts.** There is one chain per `(branch, location)` and it lives in the columns of
   `stock_counts` (`chain_seq`, `previous_hash`, `payload_hash`, `payload_version`, `canonical_payload`, `locked_at`,
   `locked_by_user_id`). The locked row is the link; there is no chain table and no head row. Every count that is approved
   (opening and closing, and the cash drawer's from step 3.5) is locked and becomes a link in the same transaction as the
   decision that approved it: the submit that finds no difference, the approval, the co-signature that finds none.
2. **The hash.** `payload_hash = lowercase hex of sha256(previous_hash . canonical_payload)`, where `.` is the byte
   concatenation of the 64 ASCII characters of the previous hash and the payload bytes. The first link of a place has
   `previous_hash` = 64 zeros (genesis). `canonical_payload`, the exact bytes that were hashed, is stored with the row, so a
   verifier needs no encoder to check a link and an auditor can read what was sealed.
3. **The sequence is claimed by a unique key.** `(branch_id, location_id, chain_seq)` is unique. The head is read without a
   lock; two locks that read the same head both try `n + 1`; the second waits for the first's commit, gets a duplicate-key
   error inside a savepoint, reads the row that now exists with a locking read (its hash is the previous hash of the next
   link) and tries `n + 2`. After eight lost claims the request is `409 shifts.lock.chain_busy` and rolls back whole. A stale
   read costs a retry, never a wrong link; a lock on the head would deadlock on the first link of a place.
4. **Canonical JSON, a closed grammar with an encoder of its own** (`Hash\CanonicalJson`, plan section 10.3). Only `null`,
   booleans, integers, strings, lists and maps; anything else (a float, an object) is a bug that throws. UTF-8, no whitespace
   outside strings, map keys in byte order at every depth (integer-like keys sort as strings), strings escaped minimally
   (`"`, `\`, U+0000 to U+001F as `\u00xx`; everything else raw, including `/`, U+007F, U+2028 and non-BMP characters; no
   normalisation). Decimals are fixed-scale strings, quantities with four places and money with two, zero never negative;
   instants are UTC with exactly six fraction digits; dates `YYYY-MM-DD`. Lists have a documented order (lines by item id,
   channels by code). An empty array is a list; no empty map occurs in the schema.
5. **The payload (version 1)** is everything that was decided about the count (`Hash\PayloadBuilder`): branch, place, shift
   and its local date, station, type, `seq`, the count it supersedes, the snapshot instant and the ledger movement it was
   measured at, who counted, who co-signed, the decision, its time and note, the lines with their figures, reason codes and
   adjustments, the cash block (null until step 3.5), and what the lock itself adds: `chain_seq`, `locked_at` (microseconds),
   `locked_by_user_id`, `payload_version`. Every key is always present, `null` when it has no value. `payload_version` is part
   of the payload and of the verifier's dispatch: a change of grammar is a new version and old links verify by the old rules
   forever.
6. **Verification checks four things per link** (`HashChain::verify`, `GET /locations/{id}/chain/verify`, nightly
   `shifts:verify-chain`): the numbering has no gap (`gap`), `previous_hash` is the hash of the link before it
   (`previous_mismatch`), the hash is the sha256 of `previous_hash` and the stored bytes (`hash_mismatch`), and the stored bytes
   are what the live rows produce today (`payload_mismatch`: a changed line, note or figure is found even by someone who left the
   stored hash alone). Each break is named by link and kind; `ChainBroken` tells the admins, once per distinct break.
7. **The database refuses what the application would never do** (migration 12). A locked `stock_counts` row accepts no UPDATE
   except `superseded_by_id` (and its twin `superseded_at`) going from NULL to a value, once; no count is ever deleted. Both
   engines. The MariaDB trigger names every column, and `StockCountsTriggerCoversEveryColumnTest` fails when a column is
   added and the trigger is not updated; `stock_count_lines` (migration 10) and the cash declaration are frozen the same way.
8. **An unlock supersedes, it never rewrites.** The admin's unlock gives the locked count a revision (a new draft, `seq + 1`,
   `supersede_cause = unlock`); the old link stays locked, keeps verifying, and points at the revision with `superseded_by_id`.
   The revision measures against the ledger the first approval made equal to the old counted figure, so approving it writes only
   the correcting difference, and when it locks it is the next link.
9. **A second implementation checks the first.** `scripts/verify_chain.py` (standard library only, no code in common with the
   PHP) reads the grammar and recomputes the pinned vectors (`apps/api/tests/Fixtures/chain/vectors.json`, written by
   `CanonicalJsonTest`: a stock count, a till count with cash, a Unicode note) and any chain export (`HashChain::export`). The CI
   `invariants` job runs it, so a change in PHP, in `brick/math` or in the encoder that moves one byte fails a job that has
   nothing to do with PHP.

## Consequences

- Someone with write access to the database *and* the application (or who can run `DROP TRIGGER`) can recompute a whole chain
  from some link onward, and deleting the newest links leaves a shorter chain that still verifies. The defences are the nightly
  check, the head hashes shown on the admin screen and printed to the log, and (Phase 4) their inclusion in the weekly summary
  sent outside the database. The chain makes tampering detectable by anyone who kept an earlier head hash; it does not make it
  impossible.
- A fix to a locked count is a visible event with a reason and an author (an unlock and a revision), not an edit.
- The stored `canonical_payload` of a link can be several kilobytes (a count with many lines); `MEDIUMTEXT` holds them.
- Counts left `approved` by the deploys between steps 3.3b and 3.4 are locked by the end of migration 12 (`Locks::lockApproved`,
  oldest decision first within a place); `shifts:lock-approved` repeats it for any count the migration could not lock.
- Changing the grammar (a new field, a new scale) is a new `payload_version` with a new builder, the old one kept for ever.
