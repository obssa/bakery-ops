"use client";

import { CircleCheck, Lock, Volume2, VolumeX } from "lucide-react";
import { useTranslations } from "next-intl";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { lockDue, useActing } from "@/lib/acting";
import { playChime, storeMuted, storedMuted, unlockAudio } from "@/lib/kds";
import { createMenuFeed, createOrdersFeed, openOrdersOf, readyLineIds } from "@/lib/live/pad";
import { useLiveFeed } from "@/lib/live/useLiveFeed";
import type { Menu } from "@/lib/api/types";
import type { FeedSnapshot } from "@/lib/live/feed";
import type { OrdersState } from "@/lib/live/pad";
import { onReconnect } from "@/lib/reconnect";
import { useSession } from "@/lib/session";
import { Avatar, Button, SegmentedControl } from "../ui";
import { LockScreen } from "./LockScreen";
import { MyOrders } from "./MyOrders";
import { OrderingSurface } from "./OrderingSurface";
import { usePadOutcomes, useReleaseStaleSends } from "./outcomes";

// The waiter pad (/pad). Signed in as the pad account, it is locked until a waiter types their short code; the waiter
// then has the pad for 10 minutes (the API acting session) and for as long as they keep touching it (60 s idle lock).
// Locking parks the waiter cart in memory under their id; nothing of them is on screen while it is locked.
//
// Step 6.3a (fresh-counter.md 3.2): the pad is drawn edge to edge under the station top bar (the shell's `fill` frame). Unlocked, a white
// bar holds the "Pad views" tabs (New order, My orders with its ready count) and, on the right, who has the pad, the chime switch and
// "Lock pad"; under it the ordering surface (the menu and the order panel) or the waiter's open orders. "Order sent" shows as a toast over
// the menu, so nothing on the screen moves under the waiter's finger. The page's h1 is for readers: the top bar and the "Screens" tabs
// already say where the screen is.

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
    <div className="flex flex-col lg:min-h-0 lg:flex-1" onPointerDown={unlockAudio} onKeyDown={unlockAudio}>
      <div className="flex shrink-0 flex-wrap items-center justify-between gap-3 border-b border-neutral-200 bg-white px-4 py-2.5 sm:px-6">
        <SegmentedControl
          tabs
          tone="station"
          label={t("views")}
          value={view}
          onChange={setView}
          options={[
            { value: "order", label: t("tab_new_order") },
            {
              value: "orders",
              label: (
                <>
                  {t("tab_my_orders")}
                  {readyCount > 0 ? (
                    <span className="inline-flex min-h-6 items-center rounded-full bg-brand-600 px-2 text-[13px] font-bold tabular-nums text-white">
                      {t("ready_badge", { count: readyCount })}
                    </span>
                  ) : null}
                </>
              ),
            },
          ]}
        />
        <div className="flex flex-wrap items-center gap-2">
          <div className="mr-1 flex min-w-0 items-center gap-2.5">
            <Avatar name={userName} size="sm" />
            <p className="truncate text-base font-semibold text-neutral-900">{t("acting", { name: userName })}</p>
          </div>
          <Button
            type="button"
            variant="secondary"
            aria-pressed={muted}
            onClick={() => {
              unlockAudio();
              const next = !muted;
              mutedRef.current = next;
              setMuted(next);
              storeMuted(next);
            }}
          >
            {muted ? <VolumeX className="size-5" strokeWidth={1.75} aria-hidden="true" /> : <Volume2 className="size-5" strokeWidth={1.75} aria-hidden="true" />}
            {muted ? t("unmute") : t("mute")}
          </Button>
          <Button type="button" variant="dark" onClick={() => useActing.getState().lock("manual")}>
            <Lock className="size-5" strokeWidth={1.75} aria-hidden="true" />
            {t("lock")}
          </Button>
        </div>
      </div>

      {/* a toast over the menu (centred on it from 1024 px, beside the 368 px order panel); a polite live region either way */}
      <p
        role="status"
        className={
          notice
            ? "fixed bottom-6 left-1/2 z-30 flex max-w-[calc(100%-2rem)] -translate-x-1/2 items-center gap-2.5 rounded-2xl border border-brand-100 bg-brand-50 px-5 py-3.5 text-base font-semibold text-brand-700 shadow-float lg:left-[calc((100%-368px)/2)]"
            : "sr-only"
        }
      >
        {notice ? (
          <>
            <CircleCheck className="size-5 shrink-0" strokeWidth={2} aria-hidden="true" />
            {t("sent")}
          </>
        ) : (
          ""
        )}
      </p>

      {view === "order" ? (
        <OrderingSurface fill userId={userId} menu={menu} onSendStarted={onNoticeOff} />
      ) : (
        <div className="px-4 py-5 sm:px-6 lg:min-h-0 lg:flex-1 lg:overflow-y-auto">
          <MyOrders orders={open} loaded={!!orders?.loaded} stale={orders?.error ? t("stale") : null} />
        </div>
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
  // reconnect reconcile (step 5.2c): back online, or visible again after a gap, the waiter's list is read again from the start
  useEffect(() => onReconnect(() => ordersFeed?.reload()), [ordersFeed]);
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
    <div data-shell-fill className="flex flex-1 flex-col lg:min-h-0">
      <h1 className="sr-only">{t("title")}</h1>
      {user ? (
        <Workspace key={user.id} userId={user.id} userName={user.name} notice={notice} onNoticeOff={() => setNotice(false)} orders={orders} menu={menu} />
      ) : (
        <div className="flex flex-1 items-start justify-center px-4 py-8 sm:items-center lg:min-h-0 lg:overflow-y-auto">
          <LockScreen />
        </div>
      )}
    </div>
  );
}
