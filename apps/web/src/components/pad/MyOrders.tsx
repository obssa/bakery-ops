"use client";

import { useLocale, useTranslations } from "next-intl";
import type { Line, LineStatus, Locale, Order } from "@/lib/api/types";
import { trimDecimal } from "@/lib/decimal";
import { visibleLines } from "@/lib/live/pad";
import { queueCancelOrder, queueServeLine, useOutbox } from "@/lib/outbox/store";
import { Badge, Button } from "../ui";

// The acting waiter own open orders with the live status of every line: sent, cooking, ready, served. A ready line
// has a green badge and a Served button; an unsent order (open) can be cancelled. Void is not on the pad.

const TONE: Record<LineStatus, "neutral" | "blue" | "green" | "amber" | "red"> = {
  queued: "neutral",
  accepted: "blue",
  ready: "green",
  served: "neutral",
  rejected: "red",
  voided: "neutral",
};

function LineRow({ line }: { line: Line }) {
  const t = useTranslations("pad");
  const locale = useLocale() as Locale;
  const pending = useOutbox((s) => s.items.some((item) => item.subjectId === line.id));
  const name = locale === "am" ? line.item.name_am : line.item.name_en;
  const modifiers = line.modifiers.map((m) => (locale === "am" ? m.name_am : m.name_en));
  const status =
    line.status === "queued"
      ? t("line_queued")
      : line.status === "accepted"
        ? t("line_accepted")
        : line.status === "ready"
          ? t("line_ready")
          : line.status === "served"
            ? t("line_served")
            : t("line_rejected", { reason: (locale === "am" ? line.rejected?.message_am : line.rejected?.message_en) ?? "" });

  return (
    <li className="flex flex-wrap items-center justify-between gap-3 py-3">
      <div className="min-w-0">
        <p className="text-lg font-semibold text-neutral-900">
          {trimDecimal(line.qty)} × {name}
        </p>
        {modifiers.length > 0 ? <p className="text-base text-neutral-700">{modifiers.join(", ")}</p> : null}
        {line.notes ? <p className="text-base italic text-neutral-700">{line.notes}</p> : null}
      </div>
      <div className="flex items-center gap-2">
        <Badge tone={TONE[line.status]}>{pending ? t("sending") : status}</Badge>
        {line.status === "ready" ? (
          <Button type="button" aria-label={t("serve_line", { name })} disabled={pending} onClick={() => queueServeLine(line.id)}>
            {t("serve")}
          </Button>
        ) : null}
      </div>
    </li>
  );
}

function OrderCard({ order }: { order: Order }) {
  const t = useTranslations("pad");
  const k = useTranslations("kds");
  const lines = visibleLines(order);
  const ready = lines.filter((line) => line.status === "ready").length;
  const pending = useOutbox((s) => s.items.some((item) => item.kind === "cancel_order" && item.subjectId === order.id));
  const where = order.type === "takeaway" ? k("takeaway") : order.dining_table ? k("table", { code: order.dining_table.code }) : "";

  return (
    <article className={`rounded-lg border-2 p-4 ${ready > 0 ? "border-green-500 bg-green-50" : "border-neutral-200 bg-white"}`}>
      <header className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-lg font-semibold text-neutral-900">
          {t("order_heading", { no: String(order.order_no) })}
          {where ? <span className="font-normal text-neutral-700"> · {where}</span> : null}
        </h3>
        <div className="flex items-center gap-2">
          {ready > 0 ? <Badge tone="green">{t("ready_badge", { count: ready })}</Badge> : null}
          {order.status === "open" ? <Badge tone="amber">{t("order_unsent")}</Badge> : null}
        </div>
      </header>
      <ul className="mt-1 divide-y divide-neutral-200">
        {lines.map((line) => (
          <LineRow key={line.id} line={line} />
        ))}
      </ul>
      <footer className="mt-2 flex flex-wrap items-center justify-between gap-2">
        <p className="text-base font-semibold text-neutral-900">{t("order_total", { total: order.totals.total, currency: order.currency })}</p>
        {order.status === "open" ? (
          <Button type="button" variant="secondary" aria-label={t("cancel_order_aria", { no: String(order.order_no) })} disabled={pending} onClick={() => queueCancelOrder(order.id, order.client_uuid)}>
            {t("cancel_order")}
          </Button>
        ) : null}
      </footer>
    </article>
  );
}

export function MyOrders({ orders, loaded, stale }: { orders: Order[]; loaded: boolean; stale: string | null }) {
  const t = useTranslations("pad");
  return (
    <section aria-label={t("orders_title")} className="space-y-3">
      {stale ? (
        <p role="status" className="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-base text-amber-900">
          {stale}
        </p>
      ) : null}
      {!loaded ? (
        <p role="status" className="text-base text-neutral-600">
          {t("orders_loading")}
        </p>
      ) : orders.length === 0 ? (
        <p className="rounded-md border border-neutral-200 bg-white px-4 py-6 text-base text-neutral-700">{t("no_orders")}</p>
      ) : (
        <ul aria-label={t("orders_list")} className="grid gap-4 lg:grid-cols-2">
          {orders.map((order) => (
            <li key={order.id}>
              <OrderCard order={order} />
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
