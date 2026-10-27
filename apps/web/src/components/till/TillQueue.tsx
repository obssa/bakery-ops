"use client";

import { useLocale, useTranslations } from "next-intl";
import type { Locale, Order } from "@/lib/api/types";
import { ageOf, clock, secondsSince, type TicketAge } from "@/lib/kds";
import { trimDecimal } from "@/lib/decimal";
import { isSettled } from "@/lib/live/orders";
import { visibleLines } from "@/lib/live/pad";
import { useOrderNo } from "@/lib/orderNo";
import { useNow } from "@/lib/useNow";
import { Badge, Button } from "../ui";

// The queue of orders awaiting payment: oldest first, each with its table, number, waiter, how long it has waited (the
// kitchen display's amber and red thresholds, with the words "Slow" and "Late" so colour is not the only signal), its
// lines and the balance. A paid order that is not served yet stays, marked settled, until it closes; one of its payments can still be voided
// (step 4.5b, the void of step 4.3c).

const AGE_STYLE: Record<TicketAge, string> = {
  fresh: "border-neutral-200 bg-white",
  amber: "border-amber-400 bg-amber-50",
  red: "border-red-500 bg-red-50",
};

function QueueCard({
  order,
  now,
  payBlocked,
  onPay,
  onVoid,
}: {
  order: Order;
  now: number;
  payBlocked: string | null;
  onPay: (order: Order) => void;
  onVoid: (order: Order) => void;
}) {
  const t = useTranslations("till");
  const k = useTranslations("kds");
  const locale = useLocale() as Locale;
  const settled = isSettled(order);
  const seconds = secondsSince(order.sent_at, now);
  const age = ageOf(seconds);
  const no = useOrderNo(order);
  const where = order.type === "takeaway" ? k("takeaway") : order.dining_table ? k("table", { code: order.dining_table.code }) : "";

  return (
    <article className={`space-y-3 rounded-lg border-2 p-4 ${settled ? "border-green-400 bg-green-50" : AGE_STYLE[age]}`}>
      <header className="flex flex-wrap items-baseline justify-between gap-2">
        <h3 className="text-lg font-semibold text-neutral-900">
          {t("order_heading", { no })}
          {where ? <span className="font-normal text-neutral-700"> · {where}</span> : null}
        </h3>
        <p className="flex items-center gap-2 text-base text-neutral-700">
          <span>{t("waiter", { name: order.owner.name ?? "" })}</span>
          <time dateTime={order.sent_at ?? undefined} aria-label={t("waiting", { time: clock(seconds) })} className={age === "red" ? "font-semibold text-red-700" : age === "amber" ? "font-semibold text-amber-800" : ""}>
            {clock(seconds)}
          </time>
          {age !== "fresh" && !settled ? <Badge tone={age === "red" ? "red" : "amber"}>{k(age === "red" ? "late" : "slow")}</Badge> : null}
        </p>
      </header>
      <ul className="divide-y divide-neutral-200 text-base text-neutral-900">
        {visibleLines(order).map((line) => (
          <li key={line.id} className="py-1">
            {trimDecimal(line.qty)} × {locale === "am" ? line.item.name_am : line.item.name_en}
          </li>
        ))}
      </ul>
      <footer className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-lg font-semibold text-neutral-900">{t("balance", { amount: order.totals.balance, currency: order.currency })}</p>
        {settled ? (
          <div className="flex flex-wrap items-center gap-2">
            <Badge tone="green">{t("settled")}</Badge>
            {/* paid in full, not served yet: a wrong payment can still be voided (step 4.3c); once the order closes it is a refund */}
            <Button type="button" variant="secondary" aria-label={t("void_payment_aria", { no })} onClick={() => onVoid(order)}>
              {t("void_payment")}
            </Button>
          </div>
        ) : (
          <div className="flex gap-2">
            <Button type="button" variant="secondary" aria-label={t("void_aria", { no })} onClick={() => onVoid(order)}>
              {t("void")}
            </Button>
            <Button
              type="button"
              className="min-h-14 px-6 text-lg"
              aria-label={t("take_payment_aria", { no })}
              aria-describedby={payBlocked ? `pay-blocked-${order.id}` : undefined}
              disabled={payBlocked !== null}
              onClick={() => onPay(order)}
            >
              {t("take_payment")}
            </Button>
          </div>
        )}
      </footer>
      {payBlocked && !settled ? (
        <p id={`pay-blocked-${order.id}`} className="text-base font-medium text-amber-900">
          {payBlocked}
        </p>
      ) : null}
    </article>
  );
}

export function TillQueue({
  orders,
  loaded,
  stale,
  payBlocked,
  onPay,
  onVoid,
}: {
  orders: Order[];
  loaded: boolean;
  stale: string | null;
  /** why Pay is disabled (no shift is running at the till), or null */
  payBlocked: string | null;
  onPay: (order: Order) => void;
  onVoid: (order: Order) => void;
}) {
  const t = useTranslations("till");
  const now = useNow();
  return (
    <section aria-label={t("queue_title")} className="space-y-3">
      {stale ? (
        <p role="status" className="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-base text-amber-900">
          {stale}
        </p>
      ) : null}
      {!loaded ? (
        <p role="status" className="text-base text-neutral-600">
          {t("queue_loading")}
        </p>
      ) : orders.length === 0 ? (
        <p className="rounded-md border border-neutral-200 bg-white px-4 py-6 text-base text-neutral-700">{t("queue_empty")}</p>
      ) : (
        <ul aria-label={t("queue_list")} className="grid gap-4 lg:grid-cols-2">
          {orders.map((order) => (
            <li key={order.id}>
              <QueueCard order={order} now={now} payBlocked={payBlocked} onPay={onPay} onVoid={onVoid} />
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
