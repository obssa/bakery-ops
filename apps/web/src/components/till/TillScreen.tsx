"use client";

import { useTranslations } from "next-intl";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import type { Order } from "@/lib/api/types";
import { createQueueFeed, queueOf } from "@/lib/live/orders";
import { createMenuFeed } from "@/lib/live/pad";
import { useLiveFeed } from "@/lib/live/useLiveFeed";
import { useSession } from "@/lib/session";
import { OrderingSurface } from "../pad/OrderingSurface";
import { usePadOutcomes, useReleaseStaleSends } from "../pad/outcomes";
import { PageHeader } from "../ui";
import { CashWidget } from "../shifts/CashWidget";
import { ShiftBar } from "../shifts/ShiftBar";
import { useShiftStation } from "../shifts/useShiftStation";
import { CASH_NEEDS_SHIFT_BUILD, payBlockReason } from "@/lib/shifts";
import { useShiftNotice } from "@/lib/station";
import { focusRing } from "../styles";
import { ClosedOrders } from "./ClosedOrders";
import { DisplayCasePanel } from "./DisplayCasePanel";
import { PaySheet } from "./PaySheet";
import { TillQueue } from "./TillQueue";
import { VoidDialog } from "./VoidDialog";

// The cashier till (/till): the signed-in cashier (no short code) sees the orders awaiting payment, oldest first, and
// takes payment (the pay sheet of step 4.5b: any enabled channel, split, receipt photos) or voids, and refunds a closed order (step 4.5g,
// the "Closed orders" tab); the same ordering surface as the waiter pad takes orders at the counter (the API stamps them
// created_via = till). The queue is a LiveFeed over GET /orders (?unpaid=1 first, then the cursor, ETag-gated).

type View = "queue" | "order" | "closed";
const TILL_KINDS = ["till"] as const;
const NOTICE_MS = 8000;

export function TillScreen() {
  const t = useTranslations("till");
  const me = useSession((s) => s.me);
  const userId = me?.user.id ?? 0;
  const branchId = me?.user.branch_id ?? 0;
  const [view, setView] = useState<View>("queue");
  const [paying, setPaying] = useState<Order | null>(null);
  const [voiding, setVoiding] = useState<Order | null>(null);

  // the shift at the till: Pay waits for it once the API has said a payment needs one (payments.require_shift_for_cash, step 3.5; switched
  // on in step 3.7e; every channel since step 4.3b), or from the first screen with the build flag. With the rule off nothing blocks, the bar
  // only shows the state. The pay sheet reads the same reason (step 4.5b).
  const shiftStation = useShiftStation({ kinds: TILL_KINDS });
  const cashNeedsShift = useShiftNotice((s) => s.cashNeedsShift) || CASH_NEEDS_SHIFT_BUILD;
  const block = payBlockReason({ shift: shiftStation.shift, tracked: shiftStation.station?.tracks_shifts === true, loaded: shiftStation.loaded, enforced: cashNeedsShift });
  const payBlocked = block === null ? null : t(block === "no_shift" ? "pay_blocked_no_shift" : "pay_blocked_not_open");

  const queueFeed = useMemo(() => createQueueFeed(branchId), [branchId]);
  const menuFeed = useMemo(() => createMenuFeed(branchId), [branchId]);
  const queue = useLiveFeed(queueFeed);
  const menu = useLiveFeed(menuFeed);
  const orders = useMemo(() => (queue ? queueOf(queue.state) : []), [queue]);

  // "Order sent" for the cashier's own sends; the queue looks again whenever a write of this screen was applied
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
  const refresh = useCallback(() => queueFeed.refresh(), [queueFeed]);
  usePadOutcomes(onSent, refresh);
  useReleaseStaleSends();

  return (
    <div className="space-y-4">
      <PageHeader title={t("title")} />
      <ShiftBar model={shiftStation} />
      <CashWidget shift={shiftStation.shift} />

      <div role="tablist" aria-label={t("views")} className="flex gap-2">
        {(["queue", "order", "closed"] as const).map((value) => (
          <button
            key={value}
            type="button"
            role="tab"
            aria-selected={view === value}
            onClick={() => setView(value)}
            className={`inline-flex min-h-12 items-center gap-2 rounded-md px-5 text-base font-medium ${view === value ? "bg-blue-700 text-white" : "border border-neutral-300 bg-white text-neutral-900 hover:bg-neutral-100"} ${focusRing}`}
          >
            {value === "queue" ? t("tab_queue") : value === "order" ? t("tab_new_order") : t("tab_closed")}
            {value === "queue" && orders.length > 0 ? <span className="rounded-full bg-neutral-900 px-2 py-0.5 text-sm text-white">{orders.length}</span> : null}
          </button>
        ))}
      </div>

      <p role="status" className={notice ? "rounded-md bg-green-50 px-4 py-3 text-base font-medium text-green-800" : "sr-only"}>
        {notice ? t("sent") : ""}
      </p>

      {view === "queue" ? (
        <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
          <TillQueue orders={orders} loaded={!!queue?.loaded} stale={queue?.error ? t("stale") : null} payBlocked={payBlocked} onPay={setPaying} onVoid={setVoiding} />
          <DisplayCasePanel />
        </div>
      ) : view === "order" ? (
        <OrderingSurface userId={userId} menu={menu} onSendStarted={() => setNotice(false)} />
      ) : (
        // step 4.5g: the orders closed lately, where a refund starts (the void dialog's path for a closed order)
        <ClosedOrders active={view === "closed"} onRefund={setVoiding} />
      )}

      {paying ? (
        // the order as the queue holds it now (a line added meanwhile changes the balance), else as it was when Pay was pressed
        <PaySheet order={orders.find((o) => o.id === paying.id) ?? paying} shift={block} onClose={() => setPaying(null)} onChanged={refresh} />
      ) : null}
      {voiding ? <VoidDialog order={voiding} onClose={() => setVoiding(null)} onVoided={refresh} /> : null}
    </div>
  );
}
