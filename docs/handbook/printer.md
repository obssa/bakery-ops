# The receipt printer and its bridge (የደረሰኝ አታሚና ማገናኛው)

> Written from the plan; checked against the build in 5.6b.

The website cannot reach a printer in the shop by itself. A small program, the **bridge**, runs on a computer in the shop. It asks
the server for receipts to print, and sends them to the printer (plan 10.2 D11). The printer can be on the network (port 9100), a
Windows shared printer, or a USB device. The paper is 80 mm by default, or 58 mm: Admin > Payments > Receipts.

## Setting it up (once)

Follow the [print bridge runbook](../runbook/print-bridge.md): install the bridge on the shop computer, enrol it as a device of kind
**bridge**, paste the token it shows once, then print a test receipt. The token is shown only once and is never written in a log.
The owner signs `print-bridge-shop` in the [go-live checklist](../go-live/README.md) when the shop printer has printed a
bilingual receipt with the right TIN.

## Every day

- After payment, the receipt on the till's pay sheet, on the order record and in the refund dialog has a **Print receipt** button
  (ደረሰኙን አትም). One press prints one copy. While the printer works the button waits, and the line under it reads "Waiting for the
  printer…", then "Printing…", then "Printed."; the button then reads **Print again**.
- If the printer reports a problem, the line reads "Not printed:" and the reason (paper out, printer offline, cover open), or "Not
  printed: the receipt waited too long". The till then shows the receipt link and its QR code by itself, so the customer can scan it
  instead. It does the same when no bridge is online.
- Printing needs a connection: while the till is offline the button says "Needs a connection". The bridge's local mode (see the
  runbook) is the way to print while the internet is down.
- The receipt says, in both languages, that it is a customer receipt and **not a fiscal receipt** (Q9). The shop's own sales
  register, if it has one, stays the fiscal record.

## When it stops printing

1. Check the printer: paper, power, cable or network.
2. Check that the bridge computer is on, and that it is shown as seen recently under Admin > People > Devices.
3. The [print bridge runbook](../runbook/print-bridge.md) says what each failure key means.
4. If the bridge was revoked, or its token lost: enrol it again (a new device), and paste the new token.
