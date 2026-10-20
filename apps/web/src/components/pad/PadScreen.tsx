"use client";

import { useTranslations } from "next-intl";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { lockDue, useActing } from "@/lib/acting";
import { playChime, storeMuted, storedMuted, unlockAudio } from "@/lib/kds";
import { createMenuFeed, createOrdersFeed, openOrdersOf, readyLineIds } from "@/lib/live/pad";
import { useLiveFeed } from "@/lib/live/useLiveFeed";
import type { Menu } from "@/lib/api/types";
import type { FeedSnapshot } from "@/lib/live/feed";
import type { OrdersState } from "@/lib/live/pad";
import { useSession } from "@/lib/session";
import { PageHeader } from "../ui";
import { focusRing } from "../styles";
import { LockScreen } from "./LockScreen";
import { MyOrders } from "./MyOrders";
import { OrderingSurface } from "./OrderingSurface";
import { usePadOutcomes, useReleaseStaleSends } from "./outcomes";

// The waiter pad (/pad). Signed in as the pad account, it is locked until a waiter types their short code; the waiter
// then has the pad for 10 minutes (the API acting session) and for as long as they keep touching it (60 s idle lock).
// Locking parks the waiter cart in memory under their id; nothing of them is on screen while it is locked.

type View = "order" | "orders";

const IDLE_CHECK_MS = 1000;
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
  const [view, setView] = useState<View>("order");

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
        <OrderingSurface userId={userId} menu={menu} onSendStarted={onNoticeOff} />
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

  useReleaseStaleSends();

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
