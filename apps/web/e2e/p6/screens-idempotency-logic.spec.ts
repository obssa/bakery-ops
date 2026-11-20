import { expect, test } from "@playwright/test";
import { KeyRing, KeySlot, outcomeUnknown } from "../../src/lib/api/idempotency";
import type { NewTransfer } from "../../src/lib/api/types";
import { keepsKey } from "../../src/lib/paySheet";
import { answeredAsSent } from "../../src/lib/transfers";

// phase-6/idempotency-keys (ADR 0030), as plain logic (no browser, no API): a form's Idempotency-Key is reused only while the outcome of
// its last send is unknown. The API stores a refusal (4xx) and replays it for the same key, so keeping the key after a refusal answered
// "start a shift first" again after the shift was started; a definite answer now lets the key go. Rides in p6-a11y (the name starts with
// "screens").

const network = { status: 0, key: "network" };
const serverError = { status: 500, key: "http.500" };
const inFlight = { status: 409, key: "idempotency.in_flight" };
const refused = { status: 409, key: "inventory.transfer.insufficient" };
const invalid = { status: 422, key: "validation.failed" };
const reused = { status: 422, key: "idempotency.key_reused" };

test.describe("idempotency keys: reused only while the outcome is unknown", () => {
  test("the outcome is unknown for no answer, a server error and a key still running; any other answer is definite", () => {
    expect(outcomeUnknown(network)).toBe(true);
    expect(outcomeUnknown(serverError)).toBe(true);
    expect(outcomeUnknown({ status: 503, key: "files.unavailable" })).toBe(true);
    expect(outcomeUnknown(inFlight)).toBe(true);
    expect(outcomeUnknown(refused)).toBe(false);
    expect(outcomeUnknown(invalid)).toBe(false);
    expect(outcomeUnknown(reused)).toBe(false);
    expect(outcomeUnknown({ status: 403, key: "auth.forbidden" })).toBe(false);
    // not an API answer at all (a bug before the call): keeping the key can never write twice
    expect(outcomeUnknown(new Error("boom"))).toBe(true);
    expect(outcomeUnknown(null)).toBe(true);
    // the pay sheet's rule is the same one
    expect(keepsKey).toBe(outcomeUnknown);
  });

  test("a slot keeps its key for the same payload while the outcome is unknown, and lets it go on a definite answer", () => {
    const slot = new KeySlot();
    const first = slot.keyFor({ id: 7 });
    expect(slot.keyFor({ id: 7 })).toBe(first); // a double tap
    slot.failed(network);
    expect(slot.keyFor({ id: 7 })).toBe(first); // a retry after a lost answer replays the first
    slot.failed(serverError);
    slot.failed(inFlight);
    expect(slot.keyFor({ id: 7 })).toBe(first);

    // a refusal: the next tap, the same payload, is a new request the server checks again (the cause may be fixed by now)
    slot.failed(refused);
    const second = slot.keyFor({ id: 7 });
    expect(second).not.toBe(first);
    slot.failed(invalid);
    expect(slot.keyFor({ id: 7 })).not.toBe(second);
  });

  test("a changed payload always gets a new key, and a success ends the write", () => {
    const slot = new KeySlot();
    const first = slot.keyFor({ id: 7, reason: "late" });
    slot.failed(network);
    expect(slot.keyFor({ id: 7, reason: "burnt" })).not.toBe(first);
    const third = slot.keyFor({ id: 7, reason: "burnt" });
    slot.done();
    expect(slot.keyFor({ id: 7, reason: "burnt" })).not.toBe(third);
    // a PIN left out of the payload (undefined) is the same payload, as the API leaves it out of its fingerprint
    const voided = new KeySlot();
    const key = voided.keyFor({ order: 1, target: "order", reason: "mistake", pin: undefined });
    voided.failed(network);
    expect(voided.keyFor({ order: 1, target: "order", reason: "mistake" })).toBe(key);
  });

  test("a ring keeps one key per name: a production move's retries share it, a start at another station does not", () => {
    const ring = new KeyRing();
    const accept = ring.keyFor("accept:41");
    const start = ring.keyFor("start:41");
    expect(start).not.toBe(accept);
    ring.failed("start:41", network);
    expect(ring.keyFor("start:41")).toBe(start); // the board's drop again after a lost answer
    ring.failed("start:41", refused);
    expect(ring.keyFor("start:41")).not.toBe(start);
    ring.done("accept:41");
    expect(ring.keyFor("accept:41")).not.toBe(accept);
    // the shift bar names a start by its station
    expect(ring.keyFor("start:3")).not.toBe(ring.keyFor("start:5"));
  });
});

test.describe("the request sheet's answer check", () => {
  const sent: NewTransfer = { client_uuid: "8d0f0c1e-6d43-4a5f-9a43-0a0b7c1d2e3f", item_id: 12, to_location_id: 4, from_location_id: 1, qty: "2500" };
  const answer = { item: { id: 12 }, to_location: { id: 4 }, from_location: { id: 1 }, qty: "2500.0000" } as Parameters<typeof answeredAsSent>[1];

  test("the transfer the API answered with is the one sent when the item, the places and the quantity match", () => {
    expect(answeredAsSent(sent, answer)).toBe(true);
    expect(answeredAsSent({ ...sent, from_location_id: undefined }, answer)).toBe(true);
    expect(answeredAsSent({ ...sent, qty: "2500.00" }, answer)).toBe(true);
  });

  test("an answer for the first request (its client_uuid known) is told apart from the edited one", () => {
    expect(answeredAsSent({ ...sent, qty: "3000" }, answer)).toBe(false);
    expect(answeredAsSent({ ...sent, item_id: 13 }, answer)).toBe(false);
    expect(answeredAsSent({ ...sent, to_location_id: 5 }, answer)).toBe(false);
    expect(answeredAsSent({ ...sent, from_location_id: 2 }, answer)).toBe(false);
  });
});
