# Phase 3 pilot feedback

Copy this file to `docs/phases/phase-3-pilot-feedback.md` (or keep it in the owner's notes) and fill it in during the one-week pilot.
One page per day is enough. Short, concrete, in the staff's own words; Amharic is fine. Nothing here is a secret: no passwords, short codes
or real phone numbers.

Pilot week: ____ to ____ · Branch: ____ · Filled in by: ____

## Each day

| Day | Stations that started a shift | Started on time? | Counts made (opening / closing) | Differences found | Approved by | Chain verified at 03:00? | Anything odd |
|---|---|---|---|---|---|---|---|
| Mon | | | | | | | |
| Tue | | | | | | | |
| Wed | | | | | | | |
| Thu | | | | | | | |
| Fri | | | | | | | |
| Sat | | | | | | | |
| Sun | | | | | | | |

## By station (one block each: till, kitchen, bar, cake room, bakery)

- **How long** did the opening count take? the closing count? (minutes)
- Was the **prefilled figure** right? Which items were wrong, and why (not counted last night, moved after the closing, wrong unit)?
- Did you ever press **"Nothing changed"** without looking? (be honest: this is what the system is for)
- Was a **reason** you needed missing from the list? Which?
- Was **Start without counting** used? Why?
- Did the shift bar say something you did not understand? Quote it.
- The **handover note**: did the next shift read it? Did you need more than text (a photo, a time)?
- Anything the screen made you do twice.

## The till and the cash

- Opening float declared, closing counted, float left, banked (amounts, per day).
- Cash difference of the day, and the reason given. Was the denomination list right for the notes and coins in use?
- Did the **per-channel slips** (receipts counted against taken) match? Which channel was off?
- Payments refused because no shift ran (`payments.shift.required`): how many, and who had to start one?

## Approvals

- How many counts waited for approval at the end of each day, and for how long?
- Who approved, and was a second person always available? (a sole admin cannot approve their own count)
- Did an approval ever refuse because stock would go below zero (`shifts.adjustment.insufficient`)? What was done?
- Did the **overseer co-signature** (cake room, bakery) slow the close? By how much?
- Notifications: did the approver hear about a waiting count? Through which channel (app, push, Telegram)?

## Things that went wrong

| When | Station | What happened | What you did | Did the system help or get in the way? |
|---|---|---|---|---|
| | | | | |

Include every time you wanted the **kill switch** (the three shift rules off) and why. If it was used: when, by whom, and when it went back on.

## The chain

- Did `shifts:verify-chain` report a break on any night? (it should not.) If yes: the place, the link, the kind, and what the cause turned out to be.
- Any count you wanted to change after it locked? What was it, and was **unlock** clear enough to use?

## Questions for the owner (decisions Phase 3 left open)

1. **Negative stock.** Approving a count never takes stock below zero; the approval is refused whole. Was that right in practice, or should a correction be allowed to leave a negative balance?
2. **A sole admin confirming their own count or batch.** Refused by default. Did that block a real day? Who is the second person on a quiet shift?
3. **Push and Telegram keys.** Do the approvers want to be told outside the app? Which channel?
4. **The demo branch.** Should `bakery:seed-demo` be run on the live demo branch (it opens shifts there and adds a day of sample orders; the history stays unwritten)?

## What to do next

Top three things that would make next week easier:

1.
2.
3.

Things that must not change:

-
