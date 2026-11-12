# ADR 0026: The admin switches the counting rules per station

- Status: **accepted** (phase-6/counting-rules, 2026-11-12)
- Date: 2026-11-12
- Phase: 6

## Context

`docs/architecture.md` (section 4 and section 6) draws the count as fixed. Every shift that has something to count starts with an opening
count and ends with a closing count. At a station that requires an overseer, every handed-in count waits for a second person's
co-signature. Every count that differs waits for a manager's decision before the stock is corrected. On 2026-11-12 Ramin asked for "a
setting page where the admin can turn on and off the double count and the co-sign feature … whenever we want". Asked whether "double
count" meant counting at both ends of a shift or the manager's approval, he answered "both". On the live Demo Bakery, two counts had then
been waiting days for a co-signer, and every shift began with a count of what the last shift had just counted.

The co-signature was tied to `stations.requires_overseer`. That flag also makes a finished production batch wait for an overseer
(`FinishBatch`), so switching it for counts would have changed the production board too.

## Decision

1. **Three switches per station**, columns on `stations` (migration `2026_11_18_100000`), each read when the step happens:
   - `count_opening` (default on). On, a start goes to `opening` with its opening drafts, as before. Off, every start opens at once,
     carried from the last closing count, as `carry` always did (`StartShift`). The station screen then offers only "Start shift".
   - `count_cosign` (default off). For existing stations it was backfilled from `requires_overseer`, so nothing changed on the day the
     migration ran. On, a handed-in count waits in `awaiting_cosign` (`SubmitCount`). Off, it goes straight on. From here on it is
     separate from `requires_overseer`, which keeps meaning the production overseer and keeps its switch in People. A station created later
     starts with `count_cosign` equal to its `requires_overseer`.
   - `count_approval` (default on). On, a count that differs waits in `pending_approval` for a holder of `shifts.approve`. Off, the
     counting rule approves it at the moment it would start to wait: at its submit, or at its co-signature where co-sign is on.
2. **An approval by the rule writes exactly what a manager's approval writes.** `Counts/CountApproval` is now the one place where
   approving happens; `ApproveCount` and the rule both call it. It writes one `count_adjustment` per varying line (the delta, UUIDv5 client
   ids), accepts the reasons, sets `decision = approved` and `decided_at`, and links the count into the hash chain at once. The differences:
   `decided_by_user_id` is null (no person decided), the lock names the person whose submit or co-signature triggered it, and the audit
   row is `count.approve_by_rule`. No `VarianceDecided` is announced, because nobody decided; it is announced the same way as a count with
   no difference that is approved at submit. A reason is still required for every difference. The rule never bypasses `approve_self` for
   people: it is not a person.
3. **When the rule cannot approve, a person decides.** This happens when an adjustment would take stock below zero because stock moved
   between the count and its co-signature, or when an item was deleted since. The count then waits in `pending_approval` as before, and
   `count.approve_by_rule_skipped` records why.
4. **A switch changes what happens next, never what is waiting.** Shifts already running, and counts already in `awaiting_cosign` or
   `pending_approval`, are left as they are. The page shows how many counts are waiting and links to them.
5. **Replay reads what a count did, not today's switch.** A count waited for a co-signature if it was co-signed or still waits for one. A
   count approved without a co-signature never waited for one. The single case the rows cannot tell is a count rejected before anybody
   co-signed it; that case follows the station's current co-sign rule.
6. **Who:** `GET` and `PUT /counting-rules`, `shifts.manage`, the admin alone. The PUT is one transaction over every station named (locked
   FOR UPDATE in id order). It writes one audited `station.counting_rules` per station that changes, and nothing for a station already as
   asked. It is idempotent like every write. The page is Admin > System > Counting rules.

## What each switch gives up

- **Opening count off.** A difference found at closing may have started before the shift (in the hours between two shifts, or in the
  previous shift's last minutes). With both counts, it is pinned to one shift. The opening's drift check (`count.opening_drift`) no longer
  runs, because there is no opening count. The ledger is unchanged: the closing count still corrects it.
- **Co-sign off.** Nobody checks a count before it moves on. A counting mistake goes to the manager (or, with approval off too, straight
  into the stock), where a second person would have caught it first.
- **Approval off.** The stock is corrected by the counter's figures without anyone looking first. The reasons and the locked, hash-chained
  count stay, so the correction is still explained and cannot be changed afterwards. Rule 5's "approved" is satisfied by a rule the admin
  set, recorded in the audit log, instead of by a person.

## Consequences

- `architecture.md` (count lifecycle) and the skill's `domain-model.md` and `workflows-and-screens.md` describe the three switches and
  their defaults.
- `GET /me` work stations, the shift's and the queue's station blocks carry `counting_rules`, so screens can follow the rules without
  asking again.
- The hash chain's payload (version 1) is unchanged: a rule-approved count has `decision: "approved"` and `decided_by_user_id: null`,
  which `verify_chain.py` already accepts.
