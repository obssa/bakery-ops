"use client";

import { useTranslations } from "next-intl";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { lockDue, useActing } from "@/lib/acting";
import { addToCart, emptyCart, newOrderOf, orderLinesOf, useCarts, type Cart as CartState } from "@/lib/cart";
import { useErrorText } from "@/lib/errors";
import { playChime, storeMuted, storedMuted, unlockAudio } from "@/lib/kds";
import { createMenuFeed, createOrdersFeed, openOrdersOf, readyLineIds } from "@/lib/live/pad";
import { useLiveFeed } from "@/lib/live/useLiveFeed";
import type { Menu } from "@/lib/api/types";
import type { FeedSnapshot } from "@/lib/live/feed";
import type { OrdersState } from "@/lib/live/pad";
import { queueCancelOrder, queuePadSend, queueReplaceLines, queueSendExisting, useOutbox } from "@/lib/outbox/store";
import { useSession } from "@/lib/session";
import { PageHeader } from "../ui";
import { focusRing } from "../styles";
import { Cart } from "./Cart";
import { LockScreen } from "./LockScreen";
import { MenuPanel } from "./MenuPanel";
import { MyOrders } from "./MyOrders";
import { usePadOutcomes } from "./outcomes";
import { TablesPanel } from "./TablesPanel";

// The waiter pad (/pad). Signed in as the pad account, it is locked until a waiter types their short code; the waiter
// then has the pad for 10 minutes (the API acting session) and for as long as they keep touching it (60 s idle lock).
// Locking parks the waiter cart in memory under their id; nothing of them is on screen while it is locked.

type View = "order" | "orders";

const IDLE_CHECK_MS = 1000;
const EMPTY: CartState = emptyCart();
const NOTICE_MS = 8000;

/** Locks the pad when the waiter has been idle for 60 s or the 10-minute session is over; any touch resets the idle time. */
function useAutoLock() {
  const user = useActing((s) => s.user);
  useEffect(() => {
    if (!user) return;
    const touch = () => useActing.getState().touch();
    const check = () => {
      const reason = lockDue(useActing.getState(), Date.now());
      if (reason) useActing.getState().lock(reason);
    };
    check();
    const events = ["pointerdown", "keydown", "wheel", "touchstart"] as const;
    for (const name of events) document.addEventListener(name, touch, { capture: true, passive: true });
    const timer = setInterval(check, IDLE_CHECK_MS);
    return () => {
      for (const name of events) document.removeEventListener(name, touch, { capture: true });
      clearInterval(timer);
    };
  }, [user]);
}

interface WorkspaceProps {
  userId: number;
  userName: string;
  notice: boolean;
  onNoticeOff: () => void;
  orders: FeedSnapshot<OrdersState> | null;
  menu: FeedSnapshot<Menu | null> | null;
}

function Workspace({ userId, userName, notice, onNoticeOff, orders, menu }: WorkspaceProps) {
  const t = useTranslations("pad");
  const errorText = useErrorText();
  const [view, setView] = useState<View>("order");

  const stored = useCarts((s) => s.carts[userId]);
  const cart = stored ?? EMPTY;
  const update = useCarts((s) => s.update);
  const act = useCallback((change: (cart: CartState) => CartState) => update(userId, change), [update, userId]);

  const open = useMemo(() => (orders ? openOrdersOf(orders.state) : []), [orders]);

  // a line turning ready: a short chime and a vibration, unless muted (the first tap on the pad unlocks audio)
  const [muted, setMuted] = useState(false);
  const mutedRef = useRef(false);
  useEffect(() => {
    const stored = storedMuted();
    mutedRef.current = stored;
    setMuted(stored);
  }, []);
  const seenReady = useRef<Set<number> | null>(null);
  useEffect(() => {
    if (!orders?.loaded) return;
    const now = readyLineIds(orders.state);
    if (seenReady.current && [...now].some((id) => !seenReady.current?.has(id)) && !mutedRef.current) {
      playChime();
      try {
        navigator.vibrate?.(200);
      } catch {
        // no vibration on this device
      }
    }
    seenReady.current = now;
  }, [orders]);

  const readyCount = useMemo(() => open.reduce((n, order) => n + order.lines.filter((line) => line.status === "ready").length, 0), [open]);

  function send() {
    if (cart.sending) return;
    act((c) => ({ ...c, sending: true, shortfall: [], lineErrors: {}, error: null }));
    onNoticeOff();
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
    <div className="space-y-4" onPointerDown={unlockAudio} onKeyDown={unlockAudio}>
      <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-neutral-200 bg-white px-4 py-2">
        <p className="text-lg font-semibold text-neutral-900">{t("acting", { name: userName })}</p>
        <div className="flex items-center gap-2">
          <button
            type="button"
            aria-pressed={muted}
            onClick={() => {
              unlockAudio();
              const next = !muted;
              mutedRef.current = next;
              setMuted(next);
              storeMuted(next);
            }}
            className={`min-h-12 rounded-md border border-neutral-300 px-4 text-base font-medium ${muted ? "bg-neutral-900 text-white" : "bg-white text-neutral-900 hover:bg-neutral-100"} ${focusRing}`}
          >
            {muted ? t("unmute") : t("mute")}
          </button>
          <button
            type="button"
            onClick={() => useActing.getState().lock("manual")}
            className={`min-h-12 rounded-md bg-neutral-900 px-5 text-base font-medium text-white hover:bg-neutral-800 ${focusRing}`}
          >
            {t("lock")}
          </button>
        </div>
      </div>

      <div role="tablist" aria-label={t("views")} className="flex gap-2">
        {(["order", "orders"] as const).map((value) => (
          <button
            key={value}
            type="button"
            role="tab"
            aria-selected={view === value}
            onClick={() => setView(value)}
            className={`inline-flex min-h-12 items-center gap-2 rounded-md px-5 text-base font-medium ${view === value ? "bg-blue-700 text-white" : "border border-neutral-300 bg-white text-neutral-900 hover:bg-neutral-100"} ${focusRing}`}
          >
            {value === "order" ? t("tab_new_order") : t("tab_my_orders")}
            {value === "orders" && readyCount > 0 ? <span className="rounded-full bg-green-600 px-2 py-0.5 text-sm text-white">{t("ready_badge", { count: readyCount })}</span> : null}
          </button>
        ))}
      </div>

      <p role="status" className={notice ? "rounded-md bg-green-50 px-4 py-3 text-base font-medium text-green-800" : "sr-only"}>
        {notice ? t("sent") : ""}
      </p>

      {view === "order" ? (
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
      ) : (
        <MyOrders orders={open} loaded={!!orders?.loaded} stale={orders?.error ? t("stale") : null} />
      )}
    </div>
  );
}

export function PadScreen() {
  const t = useTranslations("pad");
  const user = useActing((s) => s.user);
  const branchId = useSession((s) => s.me?.user.branch_id ?? 0);
  useAutoLock();

  // the feeds: the waiter own orders while a waiter has the pad, the menu for the screen
  const ordersFeed = useMemo(() => (user ? createOrdersFeed(user.id) : null), [user]);
  const menuFeed = useMemo(() => createMenuFeed(branchId), [branchId]);
  const orders = useLiveFeed(ordersFeed);
  const menu = useLiveFeed(menuFeed);

  // the answers to this pad sends, whether or not the waiter is looking at the cart
  const [notice, setNotice] = useState(false);
  const noticeTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  useEffect(
    () => () => {
      if (noticeTimer.current !== null) clearTimeout(noticeTimer.current);
    },
    [],
  );
  const onSent = useCallback(() => {
    setNotice(true);
    if (noticeTimer.current !== null) clearTimeout(noticeTimer.current);
    noticeTimer.current = setTimeout(() => setNotice(false), NOTICE_MS);
  }, []);
  const onChanged = useCallback(() => ordersFeed?.refresh(), [ordersFeed]);
  usePadOutcomes(onSent, onChanged);

  // a cart left "sending" by a page that was closed mid-send: the send is not in the outbox any more, so it can be
  // tapped again (the API answers the same order for the same client UUID)
  useEffect(() => {
    const queued = new Set(useOutbox.getState().items.map((item) => item.subjectUuid));
    const { carts, update } = useCarts.getState();
    for (const [id, cart] of Object.entries(carts)) if (cart.sending && !queued.has(cart.orderUuid)) update(Number(id), (c) => ({ ...c, sending: false }));
  }, []);

  return (
    <div>
      <PageHeader title={t("title")} />
      {user ? (
        <Workspace key={user.id} userId={user.id} userName={user.name} notice={notice} onNoticeOff={() => setNotice(false)} orders={orders} menu={menu} />
      ) : (
        <LockScreen />
      )}
    </div>
  );
}
