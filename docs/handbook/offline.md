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

- When the connection comes back, or the tablet wakes after half a minute or more asleep, the pad and the till read their lists
  again from the start, and the kitchen and bar screens fetch the tickets sent while they were away.
- The outbox sends what it holds. The tablet shows how many items are still waiting.

## The conflict tray

Sometimes the server refuses something sent from the outbox, because the world changed meanwhile:

- another tablet sold the last unit (a stock shortfall), or there is not enough stock;
- no shift is open where one is needed;
- the order was closed, cancelled or voided meanwhile;
- a receipt photo was refused, or is no longer on the tablet.

Nothing refused is dropped silently. It stays in the **Changes not saved** tray at the top of every screen, even after a reload,
with what was tried, the server's message in English and in Amharic (a shortfall also names each short line), and a hint. The
buttons depend on why it was refused:

- **Edit and resend** (an order from the pad or the till): the order goes back to the cart, with the short line marked. Change
  it and press Send; the tray entry goes away by itself.
- **Send again** (no shift open, not enough stock): once the shift is started or the stock is in, it goes as a new write.
- **Discard**: on every entry. It is the only button when sending again would be refused again (a closed order, a photo). A
  discarded order the server still holds open shows as not sent in **My orders**, where it can be cancelled. A refused photo:
  discard it, then take the payment again with a new photo.

If an admin revoked the tablet, nothing is refused: the tray says how many changes are kept on it, and they are sent once the
tablet is enrolled again.

Clear the tray before the close. A count or cash draft left in the tray is not a count.

If a tablet keeps failing to sync, check the System page ([system-page.md](system-page.md)), then the
[on-call plan](../runbook/on-call.md).
