"use client";

import { X } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useMemo, useState, type ReactNode } from "react";
import { useOrderDetail } from "@/lib/api/till";
import type { Line, LineStatus, Locale, Order, OrderStatus } from "@/lib/api/types";
import { trimDecimal } from "@/lib/decimal";
import { thumbStack } from "@/lib/images/orderThumbs";
import { secondsSince } from "@/lib/kds";
import { useWaitText } from "@/lib/useWaitText";
import { useErrorText } from "@/lib/errors";
import { useOrderNo } from "@/lib/orderNo";
import { BOARD_STATUSES, boardOf, createBoardFeed, isPayable, isSettled, type BoardFilters } from "@/lib/live/orders";
import { visibleLines } from "@/lib/live/pad";
import { useLiveFeed } from "@/lib/live/useLiveFeed";
import { useSession } from "@/lib/session";
import { kindLabelKey } from "@/lib/orderRecord";
import { useClock } from "@/lib/useDates";
import { useNow } from "@/lib/useNow";
import { useTables } from "../pad/TablesPanel";
import { HistoryLink } from "../audit/HistoryLink";
import { RecordLink } from "./RecordLink";
import { VoidDialog } from "../till/VoidDialog";
import { Alert, Button, Chip, IconButton, PageHeader, ProductImage, Skeleton, type Tone } from "../ui";
import { ICON_STROKE } from "../icons";
import { CARD, Notice, Toolbar, ToolbarSelect, useProductLook, type ProductLook } from "./kit";

// The admin Orders live board (/admin/orders): every active order of the branch through a LiveFeed over GET /orders,
// grouped by status, with each order's lines, stations, owner, table and age. A click opens its timeline (GET
// /orders/{order}: the events, oldest first). Read-only in Phase 1 except Void, which asks for a manager's PIN like the
// till does. Filters narrow the board by table and by status. Step 4.5d: the timeline's words come from the order record's list of kinds
// (lib/orderRecord.ts, the payment events included), and the panel links to the order's full record for the people who may open it.
//
// Step 6.4c (fresh-counter.md 4.4, the patterns of the catalog list and of the production board): the title row with its Amharic twin;
// the toolbar of the two filters (44 px selects, still labelled "Status" and "Table"); the columns as the production board draws them, a
// title and its count pill over white 16 px cards, no grey track; each card the order and where it is, its waiter and age, a stack of its
// products' photos (as the till's waiting list), its lines with their state chips and its total; the opened card held in a brand-600 line;
// the timeline a white card at the right from 1280 px (two board columns beside it, three without it).

const LINE_TONE: Record<LineStatus, Tone> = {
  queued: "neutral",
  accepted: "blue",
  ready: "positive",
  served: "neutral",
  rejected: "red",
  voided: "neutral",
};

function LineRow({ line }: { line: Line }) {
  const t = useTranslations("adminOrders");
  const p = useTranslations("pad");
  const locale = useLocale() as Locale;
  const name = locale === "am" ? line.item.name_am : line.item.name_en;
  const station = line.station ? (locale === "am" ? line.station.name_am : line.station.name_en) : t("display_case");
  const status =
    line.status === "queued" ? p("line_queued") : line.status === "accepted" ? p("line_accepted") : line.status === "ready" ? p("line_ready") : line.status === "served" ? p("line_served") : line.status === "rejected" ? line.rejected?.[locale === "am" ? "message_am" : "message_en"] ?? "" : t("line_voided");
  return (
    <li className="flex items-start justify-between gap-2 py-2">
      <span className="min-w-0 text-[15px] text-neutral-900">
        <span className="font-semibold tabular-nums">{trimDecimal(line.qty)} ×</span> {name}
        <span className="block text-[13px] text-neutral-600">{station}</span>
      </span>
      <Chip tone={LINE_TONE[line.status]} className="shrink-0">
        {status}
      </Chip>
    </li>
  );
}

/** Up to three of the order's products as overlapping round photos, then "+N" (the till's stack); beside the order's name, so hidden. */
function ThumbStack({ order, look }: { order: Order; look: ProductLook }) {
  const locale = useLocale() as Locale;
  const images = useMemo(() => {
    const map = new Map<number, NonNullable<ReturnType<ProductLook["image"]>>>();
    for (const line of order.lines) {
      const image = look.image(line.item.id);
      if (image) map.set(line.item.id, image);
    }
    return map;
  }, [order.lines, look]);
  const { thumbs, more } = thumbStack(visibleLines(order), images);
  if (thumbs.length === 0) return null;
  return (
    <span aria-hidden="true" className="flex shrink-0 items-center">
      {thumbs.map((thumb, index) => (
        <ProductImage
          key={thumb.itemId}
          image={thumb.image}
          name={locale === "am" ? thumb.name_am : thumb.name_en}
          category={look.category(thumb.itemId)}
          size="thumb"
          className={`size-7 shrink-0 rounded-full border-2 border-white ${index > 0 ? "-ml-2" : ""}`}
        />
      ))}
      {more > 0 ? (
        <span className="-ml-2 inline-flex h-7 min-w-7 items-center justify-center rounded-full border-2 border-white bg-neutral-100 px-1 text-[11px] font-bold tabular-nums text-neutral-700">
          +{more}
        </span>
      ) : null}
    </span>
  );
}

function OrderCard({ order, now, selected, look, onOpen }: { order: Order; now: number; selected: boolean; look: ProductLook; onOpen: (order: Order) => void }) {
  const t = useTranslations("adminOrders");
  const k = useTranslations("kds");
  const no = useOrderNo(order);
  const where = order.type === "takeaway" ? k("takeaway") : order.dining_table ? k("table", { code: order.dining_table.code }) : "";
  const seconds = secondsSince(order.sent_at ?? order.created_at, now);
  // the stations' way of saying a wait (lib/durations.ts): "5:07", "1 h 05", "3 d", so an order open for days does not read "5926:26"
  const age = useWaitText()(seconds);
  const unsent = order.status === "open";
  const settled = isSettled(order) && order.status !== "open";
  return (
    <article
      className={`flex flex-col gap-2.5 rounded-2xl border bg-white p-3.5 motion-safe:transition-colors motion-safe:duration-150 ${
        selected ? "border-brand-600 inset-ring-1 inset-ring-brand-600" : "border-neutral-200"
      }`}
    >
      {/* the whole head opens the timeline, but the button holds only the order's name, so its name ("Open order 12") holds what it shows */}
      <div className="relative -m-1 flex items-start justify-between gap-3 rounded-xl p-1 hover:bg-neutral-50">
        <span className="min-w-0">
          <span className="block text-base text-neutral-900">
            <button
              type="button"
              aria-label={t("open_order", { no })}
              aria-pressed={selected}
              onClick={() => onOpen(order)}
              className="text-left font-semibold outline-none after:absolute after:inset-0 after:rounded-xl focus-visible:after:ring-4 focus-visible:after:ring-brand-600/35"
            >
              {k("order_no", { no })}
            </button>
            {where ? <span className="text-neutral-600"> · {where}</span> : null}
          </span>
          <span className="block text-[13px] text-neutral-600">{t("owner", { name: order.owner.name ?? "" })}</span>
          <span className="block text-[13px] tabular-nums text-neutral-600">{t("age", { time: age })}</span>
        </span>
        <ThumbStack order={order} look={look} />
      </div>
      {unsent || settled ? (
        <div className="flex flex-wrap gap-1.5">
          {unsent ? (
            <Chip tone="amber" dot>
              {t("unsent")}
            </Chip>
          ) : null}
          {settled ? (
            <Chip tone="green" dot>
              {t("settled")}
            </Chip>
          ) : null}
        </div>
      ) : null}
      <ul className="divide-y divide-neutral-100 border-y border-neutral-100">
        {order.lines
          .filter((line) => line.status !== "voided")
          .map((line) => (
            <LineRow key={line.id} line={line} />
          ))}
      </ul>
      <p className="text-right text-[15px] font-semibold tabular-nums text-neutral-900">{t("total", { total: order.totals.total, currency: order.currency })}</p>
    </article>
  );
}

function Timeline({ order, onClose, onVoid }: { order: Order; onClose: () => void; onVoid: (order: Order) => void }) {
  const clock = useClock();
  const t = useTranslations("adminOrders");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const detail = useOrderDetail(order.id, order.version);
  const no = useOrderNo(order);
  const lines = new Map(order.lines.map((line) => [line.id, line]));
  const via = detail.data ? t(`via_${detail.data.created_via}` as "via_till") : "";

  return (
    <section aria-label={t("timeline", { no })} className={`${CARD} space-y-4 p-5 xl:sticky xl:top-8 xl:self-start`}>
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0 space-y-1">
          <h2 className="text-[17px] font-semibold text-neutral-900">{t("timeline", { no })}</h2>
          {detail.data ? <p className="text-[13px] text-neutral-600">{t("created_via", { via })}</p> : null}
        </div>
        <IconButton label={t("close_timeline")} size="md" variant="ghost" onClick={onClose} className="-mr-2 -mt-2">
          <X className="size-5" strokeWidth={ICON_STROKE} aria-hidden="true" />
        </IconButton>
      </div>
      {detail.isError ? <Alert>{errorText.message(detail.error)}</Alert> : null}
      {detail.isPending ? (
        <p role="status" className="text-[15px] text-neutral-600">
          {t("timeline_loading")}
        </p>
      ) : null}
      {detail.data ? (
        <ol className="ml-1.5 space-y-3 border-l-2 border-neutral-200">
          {detail.data.events.map((event) => {
            const line = event.order_line_id !== null ? lines.get(event.order_line_id) : undefined;
            const key = kindLabelKey(event.event);
            const label = key ? t(key as "ev_order_sent") : event.event;
            const at = new Date(event.at);
            return (
              <li key={event.id} className="relative pl-5 text-[15px] text-neutral-900">
                <span aria-hidden="true" className="absolute -left-[7px] top-1.5 size-3 rounded-full bg-brand-600 ring-2 ring-white" />
                <span className="font-semibold">{label}</span>
                {line ? <span className="text-neutral-700"> · {locale === "am" ? line.item.name_am : line.item.name_en}</span> : null}
                <time dateTime={event.at} className="block text-[13px] tabular-nums text-neutral-600">
                  {Number.isNaN(at.getTime()) ? event.at : clock(event.at, { seconds: true })}
                </time>
              </li>
            );
          })}
        </ol>
      ) : null}
      <div className="flex flex-wrap gap-2.5 border-t border-neutral-200 pt-4">
        <RecordLink orderId={order.id} label={t("open_record")} />
        <HistoryLink kind="order" id={order.id} />
        {isPayable(order) ? (
          <Button type="button" variant="danger" size="md" aria-label={t("void_aria", { no })} onClick={() => onVoid(order)}>
            {t("void")}
          </Button>
        ) : null}
      </div>
    </section>
  );
}

/** A column of the board: its status and the count in a pill over its cards (the production board's columns). */
function Column({ status, count, children }: { status: OrderStatus; count: number; children: ReactNode }) {
  const t = useTranslations("adminOrders");
  const title = t(`status_${status}` as "status_sent");
  return (
    <section aria-label={title} className="flex min-w-0 flex-col gap-3">
      <h2 className="flex items-center justify-between gap-2 px-1 text-base font-semibold text-neutral-900">
        <span>{title}</span>
        <span className="inline-flex h-[30px] min-w-[30px] items-center justify-center rounded-full border border-neutral-200 bg-white px-2.5 text-sm font-semibold tabular-nums text-neutral-900">
          {count}
        </span>
      </h2>
      {count === 0 ? <p className="rounded-2xl border border-dashed border-neutral-300 px-4 py-5 text-center text-[13px] text-neutral-600">{t("col_empty")}</p> : children}
    </section>
  );
}

export function OrdersBoard({ titleAm }: { titleAm?: string }) {
  const t = useTranslations("adminOrders");
  const branchId = useSession((s) => s.me?.user.branch_id ?? 0);
  const tables = useTables();
  const look = useProductLook();
  const [filters, setFilters] = useState<BoardFilters>({ status: "all", tableId: "all" });
  const [openId, setOpenId] = useState<number | null>(null);
  const [voiding, setVoiding] = useState<Order | null>(null);
  const now = useNow(30_000);

  const feed = useMemo(() => createBoardFeed(branchId), [branchId]);
  const snapshot = useLiveFeed(feed);
  const columns = useMemo(() => (snapshot ? boardOf(snapshot.state, filters) : null), [snapshot, filters]);
  const opened = openId !== null ? snapshot?.state[openId] : undefined;
  const total = columns ? BOARD_STATUSES.reduce((n, status) => n + columns[status].length, 0) : 0;

  return (
    <>
      <PageHeader title={t("title")} intro={t("intro")} twin={titleAm ? { text: titleAm, lang: "am" } : undefined} />

      <div className="flex flex-col gap-6">
        <div role="group" aria-label={t("filters")}>
          <Toolbar>
            <ToolbarSelect label={t("status")} value={filters.status} onChange={(event) => setFilters({ ...filters, status: event.target.value as OrderStatus | "all" })}>
              <option value="all">{t("all_statuses")}</option>
              {BOARD_STATUSES.map((status) => (
                <option key={status} value={status}>
                  {t(`status_${status}` as "status_sent")}
                </option>
              ))}
            </ToolbarSelect>
            <ToolbarSelect
              label={t("table")}
              value={String(filters.tableId)}
              onChange={(event) => setFilters({ ...filters, tableId: event.target.value === "all" ? "all" : Number(event.target.value) })}
            >
              <option value="all">{t("all_tables")}</option>
              {(tables.data ?? []).map((table) => (
                <option key={table.id} value={table.id}>
                  {table.code}
                </option>
              ))}
            </ToolbarSelect>
          </Toolbar>
        </div>

        {snapshot?.error ? (
          <Notice tone="amber" live>
            {t("stale")}
          </Notice>
        ) : null}

        {!snapshot?.loaded || !columns ? (
          <div className="space-y-4">
            <p role="status" className="text-[15px] text-neutral-600">
              {t("loading")}
            </p>
            <div aria-hidden="true" className="grid gap-4 md:grid-cols-3">
              {[0, 1, 2].map((i) => (
                <div key={i} className="space-y-3">
                  <Skeleton className="h-6 w-28" />
                  <Skeleton className="h-36 rounded-2xl" />
                </div>
              ))}
            </div>
          </div>
        ) : (
          <div className={`grid items-start gap-5 ${opened ? "xl:grid-cols-[minmax(0,1fr)_23rem]" : ""}`}>
            <div className="min-w-0">
              {total === 0 ? <p className="mb-4 text-[15px] text-neutral-600">{t("empty")}</p> : null}
              <div aria-label={t("board")} role="group" className={`grid items-start gap-4 md:grid-cols-3 ${opened ? "xl:grid-cols-2 2xl:grid-cols-3" : "2xl:grid-cols-6"}`}>
                {BOARD_STATUSES.map((status) => (
                  <Column key={status} status={status} count={columns[status].length}>
                    {columns[status].map((order) => (
                      <OrderCard key={order.id} order={order} now={now} look={look} selected={order.id === openId} onOpen={(o) => setOpenId(o.id === openId ? null : o.id)} />
                    ))}
                  </Column>
                ))}
              </div>
            </div>
            {opened ? <Timeline order={opened} onClose={() => setOpenId(null)} onVoid={setVoiding} /> : null}
          </div>
        )}
      </div>
      {voiding ? <VoidDialog order={voiding} onClose={() => setVoiding(null)} onVoided={() => feed.refresh()} /> : null}
    </>
  );
}
