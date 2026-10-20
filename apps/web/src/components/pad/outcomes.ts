"use client";

import { useEffect } from "react";
import { ApiError } from "@/lib/api/client";
import type { ShortfallBody } from "@/lib/api/types";
import { orderLinesOf, useCarts, type Cart, type Refusal } from "@/lib/cart";
import { onOutboxApplied, useOutbox, type OutboxFailure } from "@/lib/outbox/store";

// What happened to the sends this pad queued, put back where the waiter looks: a refused send lands on the cart (the
// shortfall on its exact line, another refusal on the line it names), an accepted one finishes the cart. The outbox
// reports both, whichever screen of the pad is open (this hook lives above the tabs), and the shell strip stays quiet
// about the pad sends (`inline`).

function refusalOf(error: ApiError): Refusal {
  return { key: error.key, message_en: error.messages.en ?? error.key, message_am: error.messages.am ?? error.messages.en ?? error.key };
}

/** The new state of a cart after its send was refused. */
export function cartAfterRefusal(cart: Cart, error: ApiError): Cart {
  const base: Cart = { ...cart, sending: false, shortfall: [], lineErrors: {}, error: null };

  if (error.key === "orders.send.shortfall") {
    const body = error.body as unknown as ShortfallBody;
    // the order exists on the API now (open): from here on a send is PUT lines, then POST send, on the same order
    return { ...base, serverOrderId: body.order?.id ?? cart.serverOrderId, shortfall: body.shortfall ?? [] };
  }

  if (error.status === 422) {
    const named = typeof error.body.line_client_uuid === "string" ? error.body.line_client_uuid : null;
    if (named) return { ...base, lineErrors: { [named]: refusalOf(error) } };
    // validation.failed names a line by position: lines.2.qty -> the third line of what was sent
    const lines = orderLinesOf(cart);
    const lineErrors: Record<string, Refusal> = {};
    for (const [field, messages] of Object.entries(error.fieldErrors)) {
      const at = /^lines\.(\d+)\./.exec(field);
      const line = at ? lines[Number(at[1])] : undefined;
      if (line) lineErrors[line.client_uuid] = { key: error.key, message_en: messages[0] ?? error.key, message_am: error.messages.am ?? messages[0] ?? error.key };
    }
    if (Object.keys(lineErrors).length > 0) return { ...base, lineErrors };
  }

  // the session ended: the pad is locking; the cart waits, untouched, for the same waiter code
  if (error.key === "identity.acting_session.expired") return base;
  return { ...base, error: refusalOf(error) };
}

function handle(failure: OutboxFailure) {
  if (!failure.inline || !failure.subjectUuid) return false;
  const { carts, update } = useCarts.getState();
  const owner = Object.entries(carts).find(([, cart]) => cart.orderUuid === failure.subjectUuid);
  if (owner) update(Number(owner[0]), (cart) => cartAfterRefusal(cart, failure.error));
  useOutbox.getState().dismissFailure(failure.id);
  return true;
}

/** Wires the outbox answers to the carts. `onSent` is told when a send went through, for the "Order sent" notice. */
export function usePadOutcomes(onSent: () => void, onChanged: () => void) {
  const failures = useOutbox((s) => s.failures);

  useEffect(() => {
    for (const failure of failures) handle(failure);
  }, [failures]);

  useEffect(
    () =>
      onOutboxApplied((item, outcome) => {
        onChanged();
        if (outcome !== "accepted") return;
        const { carts, finish, update } = useCarts.getState();
        if (item.kind === "send_order" || item.kind === "send_existing") {
          const owner = Object.entries(carts).find(([, cart]) => cart.orderUuid === item.subjectUuid);
          if (owner) {
            finish(Number(owner[0]));
            onSent();
          }
        } else if (item.kind === "cancel_order") {
          // the open order a cart was holding after a refused send is gone: that cart starts over
          const owner = Object.entries(carts).find(([, cart]) => cart.serverOrderId === item.subjectId);
          if (owner) finish(Number(owner[0]));
        } else if (item.kind === "replace_lines") {
          const owner = Object.entries(carts).find(([, cart]) => cart.orderUuid === item.subjectUuid);
          if (owner) update(Number(owner[0]), (cart) => ({ ...cart, shortfall: [], lineErrors: {} }));
        }
      }),
    [onSent, onChanged],
  );
}
