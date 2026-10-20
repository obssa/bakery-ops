"use client";

import { useTranslations } from "next-intl";
import { useCallback } from "react";
import { addToCart, emptyCart, newOrderOf, orderLinesOf, useCarts, type Cart as CartState } from "@/lib/cart";
import { useErrorText } from "@/lib/errors";
import type { Menu } from "@/lib/api/types";
import type { FeedSnapshot } from "@/lib/live/feed";
import { queueCancelOrder, queuePadSend, queueReplaceLines, queueSendExisting } from "@/lib/outbox/store";
import { Cart } from "./Cart";
import { MenuPanel } from "./MenuPanel";
import { TablesPanel } from "./TablesPanel";

// The ordering surface shared by the waiter pad and the cashier till: dine-in or takeaway, the table grid, the menu and
// the cart with its Send button, for one person's cart (the waiter who unlocked the pad, or the signed-in cashier).
// Sending goes through the outbox; what the API answers is put back on the cart by `usePadOutcomes` (outcomes.ts),
// which the hosting screen mounts once.

const EMPTY: CartState = emptyCart();

export function OrderingSurface({
  userId,
  menu,
  onSendStarted,
}: {
  /** whose cart this is: the acting waiter on the pad, the signed-in cashier on the till */
  userId: number;
  menu: FeedSnapshot<Menu | null> | null;
  /** the host clears its "Order sent" notice when a new send starts */
  onSendStarted: () => void;
}) {
  const t = useTranslations("pad");
  const errorText = useErrorText();

  const stored = useCarts((s) => s.carts[userId]);
  const cart = stored ?? EMPTY;
  const update = useCarts((s) => s.update);
  const act = useCallback((change: (cart: CartState) => CartState) => update(userId, change), [update, userId]);

  function send() {
    if (cart.sending) return;
    act((c) => ({ ...c, sending: true, shortfall: [], lineErrors: {}, error: null }));
    onSendStarted();
    if (cart.serverOrderId === null) {
      queuePadSend(newOrderOf(cart));
    } else {
      const replaced = queueReplaceLines(cart.serverOrderId, cart.orderUuid, orderLinesOf(cart));
      queueSendExisting(cart.serverOrderId, cart.orderUuid, replaced?.id ?? null);
    }
  }

  function clear() {
    if (cart.serverOrderId !== null) {
      // the API holds it open: cancel it there too (an unsent order, nothing reserved)
      queueCancelOrder(cart.serverOrderId);
      return;
    }
    useCarts.getState().reset(userId);
  }

  const items = menu?.state?.items;
  const currency = items?.[0]?.currency ?? "ETB";
  const menuError = menu?.error ? t("stale") : null;

  return (
    <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_24rem]">
      <div className="space-y-5">
        <TablesPanel
          type={cart.type}
          tableId={cart.tableId}
          locked={cart.serverOrderId !== null || cart.sending}
          onType={(type) => act((c) => ({ ...c, type }))}
          onTable={(id) => act((c) => ({ ...c, tableId: id }))}
        />
        <MenuPanel
          menu={menu?.state ?? null}
          loading={!menu?.loaded}
          error={menu?.loaded ? menuError : menu?.error ? errorText.message(menu.error) : null}
          onAdd={(item, extra) => act((c) => addToCart(c, item, extra))}
        />
      </div>
      <div className="lg:sticky lg:top-4 lg:self-start">
        <Cart cart={cart} menu={items} currency={currency} onChange={act} onSend={send} onClear={clear} />
      </div>
    </div>
  );
}
