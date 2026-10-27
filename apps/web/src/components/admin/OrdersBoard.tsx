"use client";

import { useLocale, useTranslations } from "next-intl";
import { useMemo, useState } from "react";
import { useOrderDetail } from "@/lib/api/till";
import type { Line, LineStatus, Locale, Order, OrderStatus } from "@/lib/api/types";
import { trimDecimal } from "@/lib/decimal";
import { clock, secondsSince } from "@/lib/kds";
import { useErrorText } from "@/lib/errors";
import { useOrderNo } from "@/lib/orderNo";
import { BOARD_STATUSES, boardOf, createBoardFeed, isPayable, isSettled, type BoardFilters } from "@/lib/live/orders";
import { useLiveFeed } from "@/lib/live/useLiveFeed";
import { useSession } from "@/lib/session";
import { kindLabelKey } from "@/lib/orderRecord";
import { useNow } from "@/lib/useNow";
import { useTables } from "../pad/TablesPanel";
import { HistoryLink } from "../audit/HistoryLink";
import { RecordLink } from "./RecordLink";
import { VoidDialog } from "../till/VoidDialog";
import { Alert, Badge, Button, Field, PageHeader, Select } from "../ui";
import { focusRing } from "../styles";

// The admin Orders live board (/admin/orders): every active order of the branch through a LiveFeed over GET /orders,
// grouped by status, with each order's lines, stations, owner, table and age. A click opens its timeline (GET
// /orders/{order}: the events, oldest first). Read-only in Phase 1 except Void, which asks for a manager's PIN like the
// till does. Filters narrow the board by table and by status. Step 4.5d: the timeline's words come from the order record's list of kinds
// (lib/orderRecord.ts, the payment events included), and the panel links to the order's full record for the people who may open it.

const LINE_TONE: Record<LineStatus, "neutral" | "blue" | "green" | "amber" | "red"> = {
  queued: "neutral",
  accepted: "blue",
  ready: "green",
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
    <li className="flex flex-wrap items-center justify-between gap-2 py-1.5">
      <span className="min-w-0 text-base text-neutral-900">
        {trimDecimal(line.qty)} × {name} <span className="text-neutral-600">· {station}</span>
      </span>
      <Badge tone={LINE_TONE[line.status]}>{status}</Badge>
    </li>
  );
}

function OrderCard({ order, now, selected, onOpen }: { order: Order; now: number; selected: boolean; onOpen: (order: Order) => void }) {
  const t = useTranslations("adminOrders");
  const k = useTranslations("kds");
  const no = useOrderNo(order);
  const where = order.type === "takeaway" ? k("takeaway") : order.dining_table ? k("table", { code: order.dining_table.code }) : "";
  const seconds = secondsSince(order.sent_at ?? order.created_at, now);
  return (
    <article className={`space-y-2 rounded-lg border-2 bg-white p-3 ${selected ? "border-blue-700" : "border-neutral-200"}`}>
      <button type="button" aria-label={t("open_order", { no })} aria-pressed={selected} onClick={() => onOpen(order)} className={`block w-full rounded-md text-left ${focusRing}`}>
        <span className="block text-lg font-semibold text-neutral-900">
          {k("order_no", { no })}
          {where ? <span className="font-normal text-neutral-700"> · {where}</span> : null}
        </span>
        <span className="block text-base text-neutral-700">{t("owner", { name: order.owner.name ?? "" })}</span>
        <span className="block text-base text-neutral-700">{t("age", { time: clock(seconds) })}</span>
      </button>
      <div className="flex flex-wrap gap-1.5">
        {order.status === "open" ? <Badge tone="amber">{t("unsent")}</Badge> : null}
        {isSettled(order) && order.status !== "open" ? <Badge tone="green">{t("settled")}</Badge> : null}
      </div>
      <ul className="divide-y divide-neutral-200">
        {order.lines
          .filter((line) => line.status !== "voided")
          .map((line) => (
            <LineRow key={line.id} line={line} />
          ))}
      </ul>
      <p className="text-base font-semibold text-neutral-900">{t("total", { total: order.totals.total, currency: order.currency })}</p>
    </article>
  );
}

function Timeline({ order, onClose, onVoid }: { order: Order; onClose: () => void; onVoid: (order: Order) => void }) {
  const t = useTranslations("adminOrders");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const detail = useOrderDetail(order.id, order.version);
  const no = useOrderNo(order);
  const lines = new Map(order.lines.map((line) => [line.id, line]));
  const via = detail.data ? t(`via_${detail.data.created_via}` as "via_till") : "";

  return (
    <section aria-label={t("timeline", { no })} className="space-y-3 rounded-lg border border-neutral-200 bg-white p-4 lg:sticky lg:top-4 lg:self-start">
      <div className="flex items-start justify-between gap-2">
        <h2 className="text-lg font-semibold text-neutral-900">{t("timeline", { no })}</h2>
        <Button type="button" variant="secondary" onClick={onClose}>
          {t("close_timeline")}
        </Button>
      </div>
      {detail.isError ? <Alert>{errorText.message(detail.error)}</Alert> : null}
      {detail.isPending ? (
        <p role="status" className="text-base text-neutral-600">
          {t("timeline_loading")}
        </p>
      ) : null}
      {detail.data ? (
        <>
          <p className="text-base text-neutral-700">{t("created_via", { via })}</p>
          <ol className="space-y-2 border-l-2 border-neutral-200 pl-4">
            {detail.data.events.map((event) => {
              const line = event.order_line_id !== null ? lines.get(event.order_line_id) : undefined;
              const key = kindLabelKey(event.event);
              const label = key ? t(key as "ev_order_sent") : event.event;
              const at = new Date(event.at);
              return (
                <li key={event.id} className="text-base text-neutral-900">
                  <span className="font-medium">{label}</span>
                  {line ? <span className="text-neutral-700"> · {locale === "am" ? line.item.name_am : line.item.name_en}</span> : null}
                  <time dateTime={event.at} className="block text-sm text-neutral-600">
                    {Number.isNaN(at.getTime()) ? event.at : at.toLocaleTimeString(locale === "am" ? "am-ET" : "en-GB", { hour: "2-digit", minute: "2-digit", second: "2-digit" })}
                  </time>
                </li>
              );
            })}
          </ol>
        </>
      ) : null}
      <div className="flex flex-wrap gap-3">
        {isPayable(order) ? (
          <Button type="button" variant="secondary" aria-label={t("void_aria", { no })} onClick={() => onVoid(order)}>
            {t("void")}
          </Button>
        ) : null}
        <RecordLink orderId={order.id} label={t("open_record")} />
        <HistoryLink kind="order" id={order.id} />
      </div>
    </section>
  );
}

export function OrdersBoard() {
  const t = useTranslations("adminOrders");
  const branchId = useSession((s) => s.me?.user.branch_id ?? 0);
  const tables = useTables();
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
    <div className="space-y-4">
      <PageHeader title={t("title")} intro={t("intro")} />

      <div role="group" aria-label={t("filters")} className="flex flex-wrap items-end gap-3">
        <div className="w-56">
          <Field label={t("status")}>
            {(props) => (
              <Select {...props} value={filters.status} onChange={(event) => setFilters({ ...filters, status: event.target.value as OrderStatus | "all" })}>
                <option value="all">{t("all_statuses")}</option>
                {BOARD_STATUSES.map((status) => (
                  <option key={status} value={status}>
                    {t(`status_${status}` as "status_sent")}
                  </option>
                ))}
              </Select>
            )}
          </Field>
        </div>
        <div className="w-56">
          <Field label={t("table")}>
            {(props) => (
              <Select {...props} value={String(filters.tableId)} onChange={(event) => setFilters({ ...filters, tableId: event.target.value === "all" ? "all" : Number(event.target.value) })}>
                <option value="all">{t("all_tables")}</option>
                {(tables.data ?? []).map((table) => (
                  <option key={table.id} value={table.id}>
                    {table.code}
                  </option>
                ))}
              </Select>
            )}
          </Field>
        </div>
      </div>

      {snapshot?.error ? (
        <p role="status" className="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-base text-amber-900">
          {t("stale")}
        </p>
      ) : null}

      {!snapshot?.loaded || !columns ? (
        <p role="status" className="text-base text-neutral-600">
          {t("loading")}
        </p>
      ) : (
        <div className={`grid gap-4 ${opened ? "xl:grid-cols-[minmax(0,1fr)_24rem]" : ""}`}>
          <div>
            {total === 0 ? <p className="mb-3 text-base text-neutral-600">{t("empty")}</p> : null}
            <div aria-label={t("board")} role="group" className="grid gap-3 md:grid-cols-3 2xl:grid-cols-6">
              {BOARD_STATUSES.map((status) => (
                <section key={status} aria-label={t(`status_${status}` as "status_sent")} className="space-y-3 rounded-lg bg-neutral-100 p-3">
                  <h2 className="flex items-center justify-between text-base font-semibold text-neutral-900">
                    <span>{t(`status_${status}` as "status_sent")}</span>
                    <span className="rounded-full bg-white px-2 py-0.5 text-sm text-neutral-700">{columns[status].length}</span>
                  </h2>
                  {columns[status].length === 0 ? (
                    <p className="text-sm text-neutral-500">{t("col_empty")}</p>
                  ) : (
                    columns[status].map((order) => <OrderCard key={order.id} order={order} now={now} selected={order.id === openId} onOpen={(o) => setOpenId(o.id === openId ? null : o.id)} />)
                  )}
                </section>
              ))}
            </div>
          </div>
          {opened ? <Timeline order={opened} onClose={() => setOpenId(null)} onVoid={setVoiding} /> : null}
        </div>
      )}
      {voiding ? <VoidDialog order={voiding} onClose={() => setVoiding(null)} onVoided={() => feed.refresh()} /> : null}
    </div>
  );
}
