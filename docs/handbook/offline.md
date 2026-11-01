# When the internet drops, and the conflict tray (ኢንተርኔት ሲቋረጥና የግጭት ሳጥኑ)

> Written from the plan; checked against the build in 5.6b.

The station screens keep working while the internet is down. What they do is kept on the tablet, in its **outbox**, and sent in
order once the connection is back. Each write carries its own key, so a write sent twice is still one write: an order replayed
after a drop is one order on the server.

## What works offline (D10)

- Taking and sending orders on the pad; the KDS and station actions.
- Payments on every channel, with their receipt photos. A photo waits on the tablet and goes up later.
- Production batch steps; drafts of counts and of cash declarations.

## What needs the internet

- Anything with a PIN: a void, a refund, an approval, a handover signature.
- Every admin screen.

These screens say so when they are offline, and wait.

## Coming back online

- The pad and the till refetch their lists. The KDS replays the tickets sent while it was away.
- The outbox sends what it holds. The tablet shows how many items are still waiting.

## The conflict tray

Sometimes the server refuses something sent from the outbox, because the world changed meanwhile:

- another tablet sold the last unit (a stock shortfall);
- the shift closed (the shift rules);
- the order was closed;
- the tablet was revoked.

Nothing refused is dropped silently. It stays in the **conflict tray** with the server's message in both languages, and with what
was attempted. For each item, either:

- **edit and resend** it (a new write, for example the order with one less unit), or
- **discard** it, once you have dealt with it another way.

Clear the tray before the close. A count or cash draft left in the tray is not a count.

If a tablet keeps failing to sync, check the System page ([system-page.md](system-page.md)), then the
[on-call plan](../runbook/on-call.md).
