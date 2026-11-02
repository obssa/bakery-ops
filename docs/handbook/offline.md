# When the internet drops, and the conflict tray (ኢንተርኔት ሲቋረጥና የግጭት ሳጥኑ)

The station screens keep working while the internet is down. What they do is kept on the tablet, in its **outbox**, and sent in
order once the connection is back. Each write carries its own key, so a write sent twice is still one write: an order replayed
after a drop is one order on the server.

## What works offline (D10)

- Taking and sending orders on the pad; the KDS and station actions.
- Payments on every channel, with their receipt photos (below).
- Production batch steps; drafts of counts and of cash declarations.

## Receipt photos (step 5.2b)

- **Every photo is kept on the tablet first**, shrunk, then uploaded: at once when online, or with the payment once the connection is
  back. A kept photo is enough: the till lets the cashier **Complete** a payment on a photo channel without waiting for the upload.
- **The limit is 50 MB of photos waiting** for their upload. Past it, the till refuses a new photo and says how much is waiting.
  Bring the tablet back online and let the queue empty.
- **A photo nothing uses any more is dropped**: replaced or removed from its row, its payment done, its conflict dismissed. A photo
  left behind by a closed tab or a reload goes after 12 hours.
- If the server refuses a photo, the row asks for another one. A photo no longer on the tablet is refused on the tablet, with a
  message in both languages.

## What needs the internet

- Anything with a PIN: a void, a refund, an approval, a handover signature.
- Every admin screen.

These screens say so when they are offline, and wait.

## Coming back online

- The pad and the till refetch their lists. The KDS replays the tickets sent while it was away.
- The outbox sends what it holds. The tablet shows how many items are still waiting.

## The conflict tray

> Written from the plan; checked against the build in 5.6b.

Sometimes the server refuses something sent from the outbox, because the world changed meanwhile:

- another tablet sold the last unit (a stock shortfall);
- the shift closed (the shift rules);
- the order was closed;
- the tablet was revoked;
- a receipt photo was refused (the tray shows the photo it names).

Nothing refused is dropped silently. It stays in the **conflict tray** with the server's message in both languages, and with what
was attempted. For each item, either:

- **edit and resend** it (a new write, for example the order with one less unit), or
- **discard** it, once you have dealt with it another way.

Clear the tray before the close. A count or cash draft left in the tray is not a count.

If a tablet keeps failing to sync, check the System page ([system-page.md](system-page.md)), then the
[on-call plan](../runbook/on-call.md).
