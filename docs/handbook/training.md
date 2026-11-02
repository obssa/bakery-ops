# Training plan (የሥልጠና ዕቅድ)

One session per role, held on the shop's own tablets before go-live, on the demo branch (never on the shop's real data). Each session
ends with the person doing every task on their list alone, while the trainer watches. The owner signs `training-done` in the
[go-live checklist](../go-live/README.md) once every role has had its session. Who attended is kept outside this public repository.

Sessions are taught in Amharic or English, as the person prefers, and the screens are switched to that language. Someone who works
in two roles attends both sessions.

## Everyone (15 minutes, at the start of every session)

- Sign in with their own account and short code, and lock the pad when stepping away.
- Never use someone else's account or PIN. Every action is recorded under the person who did it.
- What the screens do when the internet drops, and where the conflict tray is ([offline.md](offline.md)).
- Whom to call, in what order ([on-call plan](../runbook/on-call.md)).

## Per role

| Role | Session | Must be able to do alone |
|---|---|---|
| **Waiter** | 30 min | Take an order on the pad with modifiers and notes; send it; see it on the KDS; handle a "not enough stock" answer; find their orders; clear their items in the conflict tray. |
| **Chef, Barista** (KDS) | 30 min | Start the station's shift and make the opening count; accept, prepare and mark tickets ready; see the tickets that arrive after a drop; make the closing count and write the handover note. |
| **Bread baker, Cake baker** (production) | 45 min | Accept a production request; record a batch's steps and its output; open a call-in shift when the station is closed; the counts, including the co-signed counts of the bakery and the cake room. |
| **Cashier** (till) | 60 min | Declare the drawer; take payment on each channel, with a photo or reference where the channel asks for one ([channels.md](channels.md)); print a receipt or give the receipt link when printing fails ([printer.md](printer.md)); ask for a void or a refund; declare the cash and the float at the close. |
| **Manager** | 90 min | Everything the cashier does; approve a void or a refund with their PIN ([refunds-and-voids.md](refunds-and-voids.md)); review and approve counts and cash declarations, and know they never approve their own ([counts-and-handover.md](counts-and-handover.md)); open and close the day ([opening-and-closing.md](opening-and-closing.md)); read the dashboards ([dashboards.md](dashboards.md)). |
| **Admin** | 2 hours | Everything the manager does; people, roles and who may count where; enrol and revoke a tablet ([tablets.md](tablets.md)); change a payment channel; the product settings, including the two refund settings; read the System page ([system-page.md](system-page.md)) and know when to call the technical on-call ([backups.md](backups.md)). |
| **Technical on-call** | 1 hour, with the server runbook | Read the System page and `/api/v1/health`; drain or restart the queue; turn each kill switch off and on again on a copy; walk through a restore ([backup-restore.md](../runbook/backup-restore.md)) without running it on live. |

## A practice day

After the sessions, run one full practice day on the demo branch: open every station, sell, refund, void, count with a difference,
approve, close, and hand over. Then pull the network cable at the till for five minutes during it. Note what was hard, and fix the
handbook page or the training before go-live.
