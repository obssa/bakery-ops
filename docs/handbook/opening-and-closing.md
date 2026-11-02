# Opening and closing the day (ቀኑን መክፈትና መዝጋት)

Shifts are per station. The till, the kitchen, the bar, the cake room and the bakery each open and close their own shift. The store
tracks no shift.

## Opening

1. Each station's tablet is signed in as its station account, and is enrolled (see [tablets.md](tablets.md)).
2. At each station: **Start shift** (ፈረቃ ጀምር) on the shift bar, then the opening count. Mark "Nothing changed" where that is
   true, and give a reason where it is not. The count is prefilled from last night's locked closing.
3. At the till, the cashier declares the drawer: the float that stayed overnight, counted by note and coin.
4. Check the System page (see [system-page.md](system-page.md)): the scheduler ticked in the last few minutes, the queue is not
   stuck, and last night's backup is there.

The three shift rules are on. Without an open shift, stock does not move at a station that tracks shifts, and the till takes no
cash. A baker who accepts a request at a closed station opens a call-in shift. Check those shifts under Shifts, and close them at
the end of the day.

## Closing

1. At every station: **Close shift** (ፈረቃውን ዝጋ), then the closing count.
2. The cashier declares the cash, and the float that stays in the drawer.
3. A manager or the admin approves what differs, never the person who counted ([counts-and-handover.md](counts-and-handover.md)).
   A locked count shows its hash.
4. Write the handover note for the next shift.

## Next morning

- Last night's chain check was clean, and the pending approvals queue is empty.

## If the rules get in the way

If the shift rules block the shop, the kill switch turns them off on the server. It is the
[runbook's "Shift rules and the kill switch"](../runbook/server.md#shift-rules-and-the-kill-switch-phase-3-on-since-step-37e),
and it is done by whoever is on call ([on-call.md](../runbook/on-call.md)). The day-one list is the
[pilot day-one checklist](../runbook/server.md#pilot-day-one-checklist-phase-3).
