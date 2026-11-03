"use client";

import { ChevronDown } from "lucide-react";
import { useTranslations } from "next-intl";
import { useCallback, useEffect, useState } from "react";
import { addToCart, cartFrom, cartTotal, emptyCart, newOrderOf, orderLinesOf, useCarts, type Cart as CartState } from "@/lib/cart";
import { useErrorText } from "@/lib/errors";
import type { Menu } from "@/lib/api/types";
import type { FeedSnapshot } from "@/lib/live/feed";
import { queueCancelOrder, queuePadSend, queueReplaceLines, queueSendExisting, useOutbox } from "@/lib/outbox/store";
import { focusRing } from "../styles";
import { Cart, unitsOf } from "./Cart";
import { MenuPanel } from "./MenuPanel";
import { OrderHead, TablesSheet } from "./TablesPanel";

// The ordering surface shared by the waiter pad and the cashier till (fresh-counter.md 3.2 and the Pad board, step 6.3a), for one person's
// cart (the waiter who unlocked the pad, or the signed-in cashier): the menu on the left, filling the width, and the 368 px white order
// panel on the right, with what the order is for at its head ("Change" opens the "Tables" sheet: dine-in or takeaway, the table grid),
// then the cart with its Send button. Sending goes through the outbox;
// what the API answers is put back on the cart by `usePadOutcomes` (outcomes.ts), which the hosting screen mounts once.
//
// `fill` (the pad, in the shell's `fill` frame): from 1024 px the two zones hold the window's height, the menu scrolls under its search and
// chips and the panel's lines scroll above its footer, so Send is always in reach. Below 1024 px (a tablet held upright, a phone) the panel
// follows the menu, and a bar at the bottom of the window keeps the count and the total in sight with a link down to the order. Without
// `fill` (the till, for now) the panel sticks beside the menu as the page scrolls.

const EMPTY: CartState = emptyCart();

const ORDER_PANEL_ID = "order-panel";

export function OrderingSurface({
  userId,
  menu,
  onSendStarted,
  fill = false,
  tablesOpen,
  onTablesOpen,
}: {
  /** whose cart this is: the acting waiter on the pad, the signed-in cashier on the till */
  userId: number;
  menu: FeedSnapshot<Menu | null> | null;
  /** the host clears its "Order sent" notice when a new send starts */
  onSendStarted: () => void;
  /** the zones hold the window's height and scroll on their own (the pad, in the shell's `fill` frame) */
  fill?: boolean;
  /** the "Tables" sheet, when the host opens it too (the pad's top bar "Tables"); else the surface keeps it */
  tablesOpen?: boolean;
  onTablesOpen?: (open: boolean) => void;
}) {
  const t = useTranslations("pad");
  const errorText = useErrorText();

  const stored = useCarts((s) => s.carts[userId]);
  const cart = stored ?? EMPTY;
  const update = useCarts((s) => s.update);
  const act = useCallback((change: (cart: CartState) => CartState) => update(userId, change), [update, userId]);

  // the conflict tray's "Edit and resend" (step 5.2c): the refused order comes back into this person's cart once the menu is here, unless
  // the cart already holds another order (that one is sent or cleared first)
  const restore = useCarts((s) => s.restore);
  const menuItems = menu?.loaded ? menu.state?.items : undefined;
  useEffect(() => {
    if (!restore || !menuItems) return;
    const current = useCarts.getState().carts[userId];
    if (current && current.lines.length > 0 && current.orderUuid !== restore.source.orderUuid) return;
    const restored = cartFrom(restore.source, menuItems, restore, restore.resolves);
    useCarts.setState((s) => ({ restore: null, carts: { ...s.carts, [userId]: restored } }));
  }, [restore, menuItems, userId]);

  function send() {
    if (cart.sending) return;
    // the new send replaces this order's refused writes in the conflict tray (and the ones this cart was rebuilt from)
    useOutbox.getState().supersede(cart.orderUuid, cart.resolves);
    act((c) => ({ ...c, sending: true, shortfall: [], lineErrors: {}, error: null, resolves: [] }));
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
      queueCancelOrder(cart.serverOrderId, cart.orderUuid);
      return;
    }
    useCarts.getState().reset(userId);
  }

  const items = menu?.state?.items;
  const currency = items?.[0]?.currency ?? "ETB";
  const menuError = menu?.error ? t("stale") : null;

  const [ownSheet, setOwnSheet] = useState(false);
  const sheetOpen = tablesOpen ?? ownSheet;
  const setSheet = onTablesOpen ?? setOwnSheet;
  const locked = cart.serverOrderId !== null || cart.sending;

  const panel = (
    <>
      <OrderHead type={cart.type} tableId={cart.tableId} locked={locked} sent={cart.serverOrderId !== null} onChange={() => setSheet(true)} />
      <Cart cart={cart} menu={items} categories={menu?.state?.categories} currency={currency} onChange={act} onSend={send} onClear={clear} />
    </>
  );
  const sheet =
    sheetOpen && !locked ? (
      <TablesSheet
        type={cart.type}
        tableId={cart.tableId}
        onType={(type) => act((c) => ({ ...c, type }))}
        onTable={(id) => act((c) => ({ ...c, tableId: id }))}
        onClose={() => setSheet(false)}
      />
    ) : null;
  const menuPanel = (
    <MenuPanel
      menu={menu?.state ?? null}
      loading={!menu?.loaded}
      error={menu?.loaded ? menuError : menu?.error ? errorText.message(menu.error) : null}
      onAdd={(item, extra) => act((c) => addToCart(c, item, extra))}
      sticky={fill}
    />
  );

  if (!fill) {
    return (
      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_368px]">
        <div className="min-w-0">{menuPanel}</div>
        <div className="flex max-h-[calc(100dvh-2rem)] flex-col gap-3.5 overflow-hidden rounded-2xl border border-neutral-200 bg-white p-5 lg:sticky lg:top-4 lg:self-start">
          {panel}
        </div>
        {sheet}
      </div>
    );
  }

  return (
    <div className="flex flex-col lg:grid lg:min-h-0 lg:flex-1 lg:grid-cols-[minmax(0,1fr)_368px]">
      {/* the menu's search and chips stick to the top of this zone and bring their own top padding */}
      <div className="min-w-0 px-4 pb-5 sm:px-6 lg:min-h-0 lg:overflow-y-auto">{menuPanel}</div>
      <div id={ORDER_PANEL_ID} className="flex scroll-mt-4 flex-col gap-3.5 border-t border-neutral-200 bg-white p-5 lg:min-h-0 lg:border-l lg:border-t-0">
        {panel}
      </div>
      {sheet}
      {cart.lines.length > 0 ? (
        // below 1024 px the panel is under the menu: the count and the total stay in sight, with the way down to them
        <div className="sticky bottom-0 z-20 flex items-center justify-between gap-3 border-t border-neutral-200 bg-white px-4 py-3 shadow-float lg:hidden">
          <p className="min-w-0 truncate text-base font-semibold tabular-nums text-neutral-900">
            {t("items_count", { count: Number(unitsOf(cart)) })} · {currency} {cartTotal(cart)}
          </p>
          <a
            href={`#${ORDER_PANEL_ID}`}
            className={`inline-flex min-h-12 shrink-0 items-center gap-2 rounded-xl bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 ${focusRing}`}
          >
            {t("view_order")}
            <ChevronDown className="size-5" strokeWidth={2} aria-hidden="true" />
          </a>
        </div>
      ) : null}
    </div>
  );
}
