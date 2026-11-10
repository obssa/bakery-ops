"use client";

import { HandCoins } from "lucide-react";
import { useTranslations } from "next-intl";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import type { Order } from "@/lib/api/types";
import { createQueueFeed, queueOf } from "@/lib/live/orders";
import { createMenuFeed } from "@/lib/live/pad";
import { useLiveFeed } from "@/lib/live/useLiveFeed";
import { onReconnect } from "@/lib/reconnect";
import { useProductImages } from "@/lib/productImages";
import { useSession } from "@/lib/session";
import { useStationHeader } from "@/lib/topBar";
import { useWide } from "@/lib/useWide";
import { OrderingSurface } from "../pad/OrderingSurface";
import { usePadOutcomes, useReleaseStaleSends } from "../pad/outcomes";
import { EmptyState } from "../ui";
import { StationHeaderActions } from "../StationHeaderActions";
import { focusRing } from "../styles";
import { CashWidget } from "../shifts/CashWidget";
import { ShiftBar } from "../shifts/ShiftBar";
import { useShiftStation } from "../shifts/useShiftStation";
import { CASH_NEEDS_SHIFT_BUILD, payBlockReason } from "@/lib/shifts";
import { useShiftNotice } from "@/lib/station";
import { ClosedOrders } from "./ClosedOrders";
import { DisplayCasePanel } from "./DisplayCasePanel";
import { PaySheet } from "./PaySheet";
import { TillQueue } from "./TillQueue";
import { VoidDialog } from "./VoidDialog";

// The cashier till (/till): the signed-in cashier (no short code) sees the orders awaiting payment, oldest first, and
// takes payment (the pay sheet of step 4.5b: any enabled channel, split, receipt photos) or voids, and refunds a closed order (step 4.5g,
// the "Closed orders" tab); the same ordering surface as the waiter pad takes orders at the counter (the API stamps them
// created_via = till). The queue is a LiveFeed over GET /orders (?unpaid=1 first, then the cursor, ETag-gated).
//
// Step 6.3b (fresh-counter.md 3.4): the views are the station's segmented tabs in the title row. The queue's view is the till's pay screen:
// the 360 px waiting list (TillQueue, with the product thumbs joined from the catalog), and beside it the pay sheet of the order chosen, or,
// with none open, a line saying what to do and the display case. Below 1024 px the list takes the width and the sheet opens over it.

type View = "queue" | "order" | "closed";
const TILL_KINDS = ["till"] as const;
const NOTICE_MS = 8000;

export function TillScreen({ titleTwin }: { /** the Amharic title beside the English one (the English page) */ titleTwin?: string }) {
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

  const queueFeed = useMemo(() => createQueueFeed(branchId), [branchId]);
  // reconnect reconcile (step 5.2c): back online, or visible again after a gap, the queue is read again from the start
  useEffect(() => onReconnect(() => queueFeed.reload()), [queueFeed]);
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

  // the product photos of the waiting list's thumbs (the catalog's, phase-6 plan 10.2 Q7), and each product's category for a placeholder's
  // icon, from the menu the till already reads for its own orders
  const images = useProductImages();
  const categoryOf = useMemo(() => {
    const names = new Map<number, string>((menu?.state?.categories ?? []).map((c) => [c.id, c.name_en]));
    const byItem = new Map<number, string>();
    for (const item of menu?.state?.items ?? []) if (item.category_id !== null && names.has(item.category_id)) byItem.set(item.item_id, names.get(item.category_id) ?? "");
    return (itemId: number) => byItem.get(itemId) ?? null;
  }, [menu]);

  // from 1024 px the pay sheet is the centre of the till, beside the list; below, a dialog over it
  const wide = useWide();
  // while a Complete is on its way the sheet keeps its order: another card cannot take its place before the answer (and the change) shows
  const [sheetBusy, setSheetBusy] = useState(false);
  const open = paying ? (orders.find((o) => o.id === paying.id) ?? paying) : null;
  const choose = useCallback((order: Order) => {
    if (!sheetBusy) setPaying(order);
  }, [sheetBusy]);
  const sheet = open ? (
    // the order as the queue holds it now (a line added meanwhile changes the balance), else as it was when Pay was pressed; one sheet per
    // order, so a draft and its state never carry over to the next
    <PaySheet
      key={open.id}
      order={open}
      shift={block}
      inline={wide && view === "queue"}
      onClose={() => setPaying(null)}
      onChanged={refresh}
      onPendingChange={setSheetBusy}
      onVoid={() => setVoiding(open)}
    />
  ) : null;

  // another view closes the sheet (it is the queue's centre), never while a Complete is on its way
  const switchView = (next: View) => {
    if (sheetBusy) return;
    if (next !== view) setPaying(null);
    setView(next);
  };

  // the till's views, as tabs at the head of the waiting list (the Till board's left column), and in the same place in the other two views
  const viewTabs = (
    <div role="tablist" aria-label={t("views")} className="flex w-full max-w-[360px] gap-1 rounded-xl border border-neutral-200 bg-white p-1">
      {(["queue", "order", "closed"] as const).map((value) => {
        const selected = view === value;
        return (
          <button
            key={value}
            type="button"
            role="tab"
            aria-selected={selected}
            onClick={() => switchView(value)}
            className={`inline-flex min-h-11 min-w-0 flex-1 items-center justify-center gap-1.5 rounded-lg px-2 text-center text-sm font-semibold leading-tight motion-safe:transition-colors motion-safe:duration-150 ${
              selected ? "bg-neutral-900 text-white" : "text-neutral-700 hover:bg-neutral-100"
            } ${focusRing}`}
          >
            {value === "queue" ? t("tab_queue") : value === "order" ? t("tab_new_order") : t("tab_closed")}
            {value === "queue" && orders.length > 0 ? (
              <span className={`rounded-full px-1.5 text-xs tabular-nums ${selected ? "bg-white text-neutral-900" : "bg-neutral-900 text-white"}`}>{orders.length}</span>
            ) : null}
          </button>
        );
      })}
    </div>
  );

  // the Till board's header is the one station top bar (lib/topBar.ts): the till's name and its Amharic name there, the shift's line under
  // them; the shift's menu and the drawer's figure on its right, and the shift's strip under it while the shift needs someone
  useStationHeader({ title: t("title"), titleAm: titleTwin });

  return (
    <div className="space-y-5">
      {/* the page's heading is the bar's name, for a reader */}
      <h1 className="sr-only">{t("title")}</h1>
      <ShiftBar model={shiftStation} look="menu" />
      <StationHeaderActions>
        <CashWidget shift={shiftStation.shift} look="summary" />
      </StationHeaderActions>


      <p role="status" className={notice ? "rounded-xl bg-positive-50 px-4 py-3 text-base font-medium text-positive-700" : "sr-only"}>
        {notice ? t("sent") : ""}
      </p>

      {view === "queue" ? (
        // fresh-counter.md 3.4: the 360 px waiting list on the left, the pay sheet (or, with none open, the display case) beside it. Below 1024 px
        // one column that may not grow past the window: without `minmax(0, 1fr)` a card's long line widened it and the phone scrolled sideways
        <div className="grid grid-cols-[minmax(0,1fr)] items-start gap-6 lg:grid-cols-[360px_minmax(0,1fr)]">
          <TillQueue
            orders={orders}
            loaded={!!queue?.loaded}
            stale={queue?.error ? t("stale") : null}
            selectedId={open?.id ?? null}
            images={images}
            categoryOf={categoryOf}
            onOpen={choose}
            head={viewTabs}
          />
          {wide && sheet ? (
            sheet
          ) : (
            <div className="min-w-0 space-y-6">
              {wide && orders.length > 0 ? (
                <div className="rounded-[20px] border border-dashed border-neutral-300">
                  <EmptyState icon={HandCoins} title={t("pick_title")} body={t("pick_body")} />
                </div>
              ) : null}
              <DisplayCasePanel />
            </div>
          )}
        </div>
      ) : view === "order" ? (
        <div className="space-y-4">
          {viewTabs}
          <OrderingSurface userId={userId} menu={menu} onSendStarted={() => setNotice(false)} />
        </div>
      ) : (
        // step 4.5g: the orders closed lately, where a refund starts (the void dialog's path for a closed order)
        <div className="space-y-4">
          {viewTabs}
          <ClosedOrders active={view === "closed"} onRefund={setVoiding} />
        </div>
      )}

      {sheet && !(wide && view === "queue") ? sheet : null}
      {voiding ? (
        <VoidDialog
          order={voiding}
          onClose={() => setVoiding(null)}
          onVoided={() => {
            refresh();
            // the order the sheet showed is voided, or owes again: it is opened afresh from its card
            setPaying(null);
          }}
        />
      ) : null}
    </div>
  );
}
