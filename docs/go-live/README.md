# Going live: the checklist and how to sign an item

Phase 5 is done only when every required item of [`docs/go-live-checklist.json`](../go-live-checklist.json) passes
(phase-5 plan 10.3). CI checks most items by itself. Nine items need the owner: hardware, money, an account, a person,
or a legal answer. Only the owner signs them, in GitHub's web editor.

## What each row means

`python scripts/gate_check.py --mode done --phase 5` (and the `gate-p5` job in CI) prints one row per item, `checklist <id>`:

| Row | Meaning |
|---|---|
| `PASS` | The verifier found what it needs. |
| `FAIL` | Something is missing or broken. While phase 5 is open, the `gate-p5` job shows it as a warning and stays green. |
| `SKIP` | Run on a PC: a CI job is trusted to CI. |
| `WAIT` | A sign-off has not been made yet. `WAIT (Ramin)` is the owner's turn, not a failure. |
| `WARN` | An optional item (`push-telegram`) that is not done. It never blocks. |

`phase_status.py claim-done 5` runs the gate first. It refuses, and lists the waiting items, until every required item passes.

## The items the owner signs

| Item | What the sign-off says |
|---|---|
| `devices-enrolled` | Every shop tablet is enrolled (Admin > People > Devices), so device enforcement may go on. A one-line PR then turns it on (plan 10.5). |
| `print-bridge-shop` | The bridge runs on the shop computer, and the shop printer printed a bilingual receipt with the right TIN. |
| `am-reviewed` | A fluent reviewer read the Amharic review sheet, and the corrections are in. |
| `training-done` | Every role had its training session ([training plan](../handbook/training.md)). |
| `on-call-agreed` | The people on call agreed to the [on-call plan](../runbook/on-call.md). Their names and numbers stay outside this repository. |
| `offsite-backup` | An off-site copy is set up and one copy was restored, **or** the owner accepts in writing that the only copy is on the host. |
| `error-tracking` | Error tracking is on and a test event was seen, **or** the owner accepts going live without it. |
| `receipt-wording` | The receipt wording is right for the shop: a customer receipt, not a fiscal receipt. |
| `push-telegram` | Optional: web push and Telegram keys are set. Not a go-live blocker. |

## How to sign (in the browser only)

1. On GitHub, open [`docs/go-live/signoff-template.md`](signoff-template.md) and copy its text.
2. Go to the `docs/go-live` folder, choose **Add file > Create new file**, and name it `signoffs/<item>.md`, for example
   `signoffs/training-done.md`.
3. Paste the text and fill the four fields: `item` (the same as the file name), `date` (today, `YYYY-MM-DD`), `signed_by`
   (your name or your role; the repository is public), and `statement` (in your own words: what you checked, or the risk you accept).
4. Commit in the browser. Either open a pull request (its `gate-p5` run shows the item turn `PASS`), or commit straight to `main`.

To change a sign-off later, edit it in the browser the same way.

## Why the browser

Agents commit from the developer PC under the owner's own git identity, so the author of a commit cannot tell them apart.
GitHub's web editor commits as `GitHub <noreply@github.com>`, and no agent uses it. So:

- every commit that touched a sign-off file must have that committer, or the item is `FAIL`;
- `check_invariants.py --signoffs` (in the `invariants` job) fails any pull request with a local commit in `docs/go-live/signoffs/`;
- **agents never create, edit or delete anything in `docs/go-live/signoffs/`** (`CLAUDE.md`, the `bakery-ops-builder` skill).

This README and the template live outside that folder, so no agent commit ever touches it.
