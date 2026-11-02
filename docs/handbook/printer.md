# The receipt printer and its bridge (የደረሰኝ አታሚና ማገናኛው)

The website cannot reach a printer in the shop by itself. A small program, the **bridge**, runs on a computer in the shop. It asks
the server for receipts to print, and sends them to the printer (plan 10.2 D11). The printer can be on the network (port 9100, the best
choice: only a network printer can tell the bridge it is out of paper or its cover is open), a printer shared on Windows, or a device
on a Linux computer (such as a USB printer). The paper is 80 mm by default, or 58 mm: Admin > Payments > Receipts.

## Setting it up (once)

Follow the [print bridge runbook](../runbook/print-bridge.md): install the bridge on the shop computer and print its test page; on the
website, as an admin, add it under **Admin > People > Devices**, **Add a print bridge**; paste the token the website shows into the
bridge's token file; set it to run as a service; then print a receipt from the till. The token is shown only once, is stored on the
server only as a hash and is never written in the bridge's log.
The owner signs `print-bridge-shop` in the [go-live checklist](../go-live/README.md) when the shop printer has printed a
bilingual receipt with the right TIN.

## Every day

- After payment, the receipt on the till's pay sheet, on the order record and in the refund dialog has a **Print receipt** button
  (ደረሰኙን አትም). One press prints one copy. While the printer works the button waits, and the line under it reads "Waiting for the
  printer…", then "Printing…", then "Printed."; the button then reads **Print again**.
- If the printer reports a problem, the line reads "Not printed:" and the reason (paper out, printer offline, cover open), or "Not
  printed: the receipt waited too long". The till then shows the receipt link and its QR code by itself, so the customer can scan it
  instead. It does the same when no bridge is online.
- Printing needs a connection: while the till is offline the button says "Needs a connection". Print the receipt from the order
  record once the till is back online.
- A till computer with its own printer can print through a bridge on the same computer (the runbook's local mode). It still needs the
  website, which draws the receipt.
- The receipt says, in both languages, that it is a customer receipt and **not a fiscal receipt** (Q9). The shop's own sales
  register, if it has one, stays the fiscal record.

## Turning printing off and on (ማተምን ማጥፋትና ማብራት)

The shop can work without its receipt printer, for example before the printer is set up or while it is being repaired. Customers
then get the receipt link and its QR code instead of paper. This was decided for go-live on 2026-11-02.

- **To turn printing off:** as an admin, open **Admin > Payments > Receipts**. Clear **Print receipts on the shop's receipt printer**
  (ደረሰኞችን በሱቁ የደረሰኝ ማተሚያ አትም) and press **Save receipt settings**.
- **While printing is off:**
  - There is no **Print receipt** button on the till's pay sheet, on the order record or in the refund dialog.
  - After payment, the receipt link and its QR code show at once, without a press, for the customer to scan.
  - The server refuses any print ("Receipt printing is turned off in the receipt settings").
  - A till that loaded the settings a few minutes before the change may still show Print once. Pressing it is refused, and the link
    is shown.
  - The bridge, if one is installed, keeps running and prints anything that was already waiting. Nothing new reaches it.
- **To turn printing on again:** tick the same box and save. Print returns on the tills within a few minutes, or at once after a page
  reload. Print a receipt from the till to check the printer.
- Every change of the switch is in the audit log (`receipt_settings.update`, with the value before and after).
- Turning printing off leaves the paper width, the business details and the bridge as they were.

## When it stops printing

1. Check the printer: paper, power, cable or network.
2. Check that the bridge computer is on, and that it is shown as seen recently under Admin > People > Devices.
3. The [print bridge runbook](../runbook/print-bridge.md) says what each failure key means.
4. If the bridge was revoked, or its token lost: add a new print bridge on the Devices page and paste its token into the bridge's
   token file (no restart needed), then revoke the old one if it is still listed.
