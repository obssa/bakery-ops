# Enrolling and revoking tablets (ታብሌቶችን መመዝገብና መሰረዝ)

Every tablet, and every computer that uses the station screens (the pad, the KDS, the till, the production board, the shift bar),
is a **device** that the admin enrols once. The list is under Admin > People > Devices (መሣሪያዎች). It shows each device's kind and
station, and when it was last seen.

## Enrolling a tablet

1. On the tablet, sign in as the admin.
2. Tap **Enrol this tablet**, on the Devices page or on the "This tablet is not enrolled" screen. Give it a name the staff will
   recognise, such as "Till 1", and its station.
3. Sign out, and sign in as the station account.

## Revoking a tablet (lost, stolen or broken)

1. Admin > People > Devices, choose the device, then **Revoke** (ሰርዝ). It is refused on every station screen at once.
2. A revoked device is never enrolled again. A replacement, or the same tablet after a reset, makes a new id and is enrolled anew (D8).

## Device enforcement

The code can refuse every station screen to a device that is not enrolled (`IDENTITY_REQUIRE_DEVICE`). It stays **off** on the
live site until the owner signs `devices-enrolled` in the [go-live checklist](../go-live/README.md). A one-line change then turns it
on (plan 10.5).

When it is on, and a tablet breaks during service, whoever is on call can turn enforcement off for the moment with the kill switch,
`IDENTITY_REQUIRE_DEVICE=false`. That switch is in the
[runbook](../runbook/server.md#shift-rules-and-the-kill-switch-phase-3-on-since-step-37e) and the
[on-call plan](../runbook/on-call.md). Turn it back on once the replacement is enrolled.

A computer that opens admin pages which read station data (a manager's laptop) needs enrolling too.
