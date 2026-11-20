# ADR 0032: Records say what may happen next: `actions` from the actions' own rules, and a generated mirror of the status machines

- Status: **accepted** (phase-6/allowed-actions, 2026-11-20)
- Date: 2026-11-20
- Phase: 6 (a fix after the claim)

## Context

The review of the app's implicit state machines (2026-11-20, item 33) found about a dozen places where the web app copied a rule of the
API: which status comes after which, who may approve a count, which card a baker may drag, whether an order may still be voided. Three of
the copies already disagreed with the server:

- the outbox's "already sent" list left out `paid` and `voided`;
- the void dialog treated an open or cancelled order as closed;
- the shift bar sent every status it did not know to "counting".

A fourth disagreement came up while this ADR was written: the count screens said the person who counted never rejects their own count,
but the API lets a holder of `shifts.approve` send their own count back, as a recount.

Each copy is a second implementation that must be changed with the first and is not tested against it.

## Decision

### 1. Every record with a status machine carries `actions`

Every API answer that shows a record whose status has a `transitions()` table now carries `actions`. This is the list of the moves the
person looking may make now, one name per write endpoint:

| Record | Names | Read from |
|---|---|---|
| Order | `edit`, `send`, `cancel`, `void`, `refund` | `GET /orders`, `GET /orders/{id}`, every order answer |
| Order line | `accept`, `ready`, `reject`, `serve` | the order's lines, the line answers |
| Station tickets | `accept`, `ready`, `reject` only | `GET /stations/{id}/tickets` |
| Payment | `verify`, `dispute`, `void` | `GET /payments`, `GET /payments/{id}`, `GET /orders/{id}/payments` |
| Production request | `assign`, `accept`, `start`, `finish`, `confirm`, `send_back`, `cancel` | the board and the card |
| Transfer | `confirm`, `reject` | `GET /transfers` and the answers |
| Purchase | `edit`, `confirm`, `cancel` | `GET /purchases`, `GET /purchases/{id}` and the answers |
| Shift | `join`, `leave`, `close`, `cancel` | `GET /shifts/{id}`, the station's current shift |
| Count | `approve`, `cosign`, `reject`, `unlock` | the count, the shift's counts, the approval queue |

- **Tickets list only the station's own steps.** Every reader of the rail holds `orders.accept` at that station, so the station's steps are
  the same for each of them, and the rail's ETag (one per station) stays true for everyone.
- **What is not listed:**
  - starting a shift (the station's, decided before a shift exists);
  - counting itself (the lines typed decide it);
  - paying (the till's, decided by Payments).

### 2. The list comes from the actions' own code, never a copy of it

- **One `verdict()` method per action.** Each action that writes one of these moves now has a static `verdict()` method
  (`App\Shared\StateMachine\Verdict`): allowed, repeat or refused with the very refusal the action throws.
- **The action and the list read the same method.** The action reads it before it writes: `settled()` for "repeat or write", and
  `orThrow()` where there is no repeat. Under its row lock the action asks again. The record's `*Actions` class (`RequestActions`,
  `CountActions`, `ShiftActions`, `OrderActions`, `PaymentActions`, `InventoryActions`) reads the same method.
- **A repeat is not listed.** A repeat is a move whose result the record already shows: the same baker accepting again, a verified payment
  verified again.
- **Permissions are asked as the actions ask them.**
  - The account must hold the route's permission.
  - The person who acts must hold the action's permission. That is the short-code person on a shared screen, or else the account.
  - Then come the station checks (`People::can(..., $station)`, overseers) and ownership (the order's owner, or `stations.all`).
- **The question is asked once per answer.** Each `*Actions` instance remembers what it asked, so a board of thirty cards asks each
  permission once. `OrderActions` and `InventoryActions` are bound `scoped`, one per request, which Octane forgets after the request.
- **Typed input and stock are not checked for the list.** The produced figure, the baker chosen, an ingredient that is short, the
  products' refund settings and the manager's PIN are found when the move is made. The list says the record allows the move, and the
  endpoint still answers those questions itself.

### 3. A matrix proves it, per record

`Tests\Support\ActionsMatrix` builds each record in every state it can be in. For every role of the bakery it reads the record's
`actions` through the API, then calls every endpoint, each in a savepoint that is rolled back. It fails on any disagreement and names the
state, the person and the move.

- **An endpoint accepts a move when** it answers 2xx and the record changed.
- **A refusal for the person's situation is not a disagreement.** `payments.shift.required` and `inventory.shift.required` say what the
  person must do first, not what the record is, so a listed move refused with one of them is allowed.
- **A 5xx is always a disagreement.**
- **The tests:**
  - `RequestActionsTest`
  - `CountActionsTest`
  - `ShiftActionsTest`
  - `OrderActionsTest` (orders and lines)
  - `PaymentActionsTest`
  - `InventoryActionsTest` (transfers and purchases)

### 4. Where a screen has no record, it reads a mirror generated from the enums

- **The command.** `php artisan bakery:state-machines` (`App\Console\StateMachines`) writes `apps/web/src/lib/api/stateMachines.ts`.
- **What the file holds, for every status enum:**
  - its values;
  - `transitions()`;
  - the statuses reachable from each one;
  - the terminal ones;
  - every yes/no question the enum answers about a status, such as `isRunning` and `isSubmitted`.
- **The checks.** `tests/Unit/StateMachinesMirrorTest.php` fails when the committed file is not the command's output, or when an enum with
  `transitions()` is missing from it.
- **Who uses it.** The outbox decides whether a send landed from the statuses reachable after `sent`, and the shift bar names a running
  shift by `isRunning`. Neither has a list of its own any more.

## Consequences

- **Screens offer what the API accepts.** A rule changed in an action changes what every screen offers in the same deploy, and the matrix
  says which state and role moved.
- **Answers grow by one short array per record.** The board, the orders list and the queue ask each permission once per answer.
- **A new write endpoint for one of these records needs three things:** a name in its `*Actions` class, its call in the matrix, and the
  web reading the name.
- **A new status enum needs one thing:** a line in `StateMachines::MACHINES`. The mirror test fails until it has one.
