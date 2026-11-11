# Reading the dashboards (ትንተናዎችን ማንበብ)

Admin > Analytics (ትንተና) has one dashboard per question: Sales (with the hourly view), Products, one product, Channels, Stations,
Staff, Shifts, and "Me" (a person's own figures). Each compares the chosen days with the same number of days before them.

## How current they are

- **Today** is at most about three minutes behind the till: a payment asks for today to be rebuilt a minute later.
- **Yesterday** is rebuilt after midnight, and again when a shift locks.
- A day the server has not calculated yet shows as **"Not calculated yet"**, never as zero. Today alone gets a calm blue line instead
  (its figures catch up within a few minutes of each sale); a past day can be calculated again with **Rebuild**. The "?" by the heading
  says the same on the screen.

The [runbook's "Dashboards and the cron"](../runbook/server.md#dashboards-and-the-cron) explains the rebuilds for whoever is on call.

## Reading them right

- **Sales** count an order when it is paid. Refunds and voids are shown on their own lines, not hidden in the totals
  ([refunds-and-voids.md](refunds-and-voids.md)).
- **Cost and margin**: some lines say **"cost incomplete"**. Display-case lines, and the recipe changes made by modifiers, are not
  costed yet (D6). Read their margin as an estimate.
- **Channels** match Payments > Reconciliation, day by day ([channels.md](channels.md)).
- **Shifts** and **Stations** come from locked shifts. A shift still open is not in them yet.
- Figures are by the local day in Addis Ababa. A night shift belongs to the day it started.

Exports (CSV, Excel, PDF) and the weekly summary are described in the
[runbook](../runbook/server.md#analytics-exports-and-the-weekly-summary-step-44d).
