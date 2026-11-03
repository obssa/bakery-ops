"use client";

import { useLocale, useTranslations } from "next-intl";
import { useId } from "react";
import type { Locale, Order } from "@/lib/api/types";
import { thumbStack, unitCount, type ProductImages } from "@/lib/images/orderThumbs";
import { ageOf, secondsSince } from "@/lib/kds";
import { isSettled } from "@/lib/live/orders";
import { visibleLines } from "@/lib/live/pad";
import { useOrderNo } from "@/lib/orderNo";
import { useNow } from "@/lib/useNow";
import { useWaiting } from "@/lib/outbox/store";
import { Badge, Button, EmptyState, ProductImage } from "../ui";

// The list of orders awaiting payment (step 6.3b, fresh-counter.md 3.4): the till's left zone, oldest first. Each card shows the order's
// number and table, its balance, a stack of up to three round product thumbs (+N) so the cashier knows an order by its food, how many items
// it holds and its waiter, and how long it has waited (minutes; the kitchen display's thresholds turn it amber and red, with the words "Slow"
// and "Late" so colour is not the only signal). A tap anywhere on the card takes its payment (the card's "Take payment" covers it), and
// "Void" sits above that. The card whose payment is open has the brand border. A paid order that is not served yet stays, marked settled,
// until it closes; one of its payments can still be voided (step 4.5b, the void of step 4.3c). The thumbs are joined from the catalog
// (useProductImages, phase-6 plan 10.2 Q7); offline they are the placeholders.

/** The stack of round thumbs beside the item count; hidden from readers, the lines are read on the pay sheet. */
function ThumbStack({ order, images, categoryOf }: { order: Order; images: ProductImages; categoryOf: (itemId: number) => string | null }) {
  const locale = useLocale() as Locale;
  const { thumbs, more } = thumbStack(visibleLines(order), images);
  if (thumbs.length === 0) return null;
  return (
    <span aria-hidden="true" className="flex shrink-0 items-center">
      {thumbs.map((thumb, index) => (
        <ProductImage
          key={thumb.itemId}
          image={thumb.image}
          name={locale === "am" ? thumb.name_am : thumb.name_en}
          category={categoryOf(thumb.itemId)}
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

function QueueCard({
  order,
  now,
  selected,
  payBlocked: blockedBy,
  images,
  categoryOf,
  onPay,
  onVoid,
}: {
  order: Order;
  now: number;
  selected: boolean;
  payBlocked: string | null;
  images: ProductImages;
  categoryOf: (itemId: number) => string | null;
  onPay: (order: Order) => void;
  onVoid: (order: Order) => void;
}) {
  const t = useTranslations("till");
  const k = useTranslations("kds");
  const headingId = useId();
  const settled = isSettled(order);
  const seconds = secondsSince(order.sent_at, now);
  const age = ageOf(seconds);
  const minutes = t("queue_age", { minutes: Math.floor(seconds / 60) });
  const no = useOrderNo(order);
  // a payment completed offline waits in the outbox (step 5.2a): no second sheet for this order until it was sent
  const waiting = useWaiting((item) => item.kind === "pay_batch" && item.subjectId === order.id);
  const payBlocked = waiting ? t("payment_waiting") : blockedBy;
  const where = order.type === "takeaway" ? k("takeaway") : order.dining_table ? k("table", { code: order.dining_table.code }) : "";
  const lines = visibleLines(order);
  const units = unitCount(lines);
  const items = units.whole && units.count !== null ? t("queue_items", { count: units.count }) : t("queue_items_qty", { qty: units.text });
  const waiter = order.owner.name ?? "";
  const late = !settled && age !== "fresh";

  return (
    <article
      aria-labelledby={headingId}
      className={`relative rounded-2xl border bg-white ${selected ? "border-brand-600 ring-1 ring-brand-600" : "border-neutral-200"}`}
    >
      <div className="space-y-1.5 px-4 pb-2.5 pt-3.5">
        <div className="flex items-baseline justify-between gap-3">
          <h3 id={headingId} className="min-w-0 truncate text-base font-bold text-neutral-900">
            {t("order_heading", { no })}
            {where ? <span className="font-semibold text-neutral-600"> · {where}</span> : null}
          </h3>
          <p className="shrink-0 text-base font-bold tabular-nums text-neutral-900">
            {order.totals.balance} {order.currency}
          </p>
        </div>
        <div className="flex items-center justify-between gap-3 text-[13px] text-neutral-600">
          <span className="flex min-w-0 items-center gap-2.5">
            <ThumbStack order={order} images={images} categoryOf={categoryOf} />
            <span className="min-w-0 truncate">
              {items}
              {waiter ? (
                <>
                  <span aria-hidden="true"> · </span>
                  {waiter}
                </>
              ) : null}
            </span>
          </span>
          <span className="flex shrink-0 items-center gap-1.5">
            <time
              dateTime={order.sent_at ?? undefined}
              aria-label={t("waiting", { time: minutes })}
              className={late ? (age === "red" ? "font-semibold text-red-700" : "font-semibold text-amber-800") : ""}
            >
              {minutes}
            </time>
            {late ? <Badge tone={age === "red" ? "red" : "amber"}>{k(age === "red" ? "late" : "slow")}</Badge> : null}
          </span>
        </div>
      </div>
      <div className="flex flex-wrap items-center justify-between gap-2 border-t border-neutral-100 px-2.5 py-2">
        {settled ? (
          <>
            <Badge tone="green">{t("settled")}</Badge>
            {/* paid in full, not served yet: a wrong payment can still be voided (step 4.3c); once the order closes it is a refund */}
            <Button type="button" variant="secondary" size="md" aria-label={t("void_payment_aria", { no })} onClick={() => onVoid(order)}>
              {t("void_payment")}
            </Button>
          </>
        ) : (
          <>
            {/* above the card's tap area, so it is never a payment by mistake */}
            <Button type="button" variant="ghost" size="md" className="relative z-10" aria-label={t("void_aria", { no })} onClick={() => onVoid(order)}>
              {t("void")}
            </Button>
            <Button
              type="button"
              size="md"
              // the whole card is this button's tap area
              className="static! after:absolute after:inset-0 after:rounded-2xl after:content-['']"
              aria-label={t("take_payment_aria", { no })}
              aria-describedby={payBlocked ? `pay-blocked-${order.id}` : undefined}
              disabled={payBlocked !== null}
              onClick={() => onPay(order)}
            >
              {t("take_payment")}
            </Button>
          </>
        )}
      </div>
      {payBlocked && !settled ? (
        <p id={`pay-blocked-${order.id}`} className="px-4 pb-3 text-[15px] font-medium text-amber-900">
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
  selectedId,
  images,
  categoryOf,
  onPay,
  onVoid,
}: {
  orders: Order[];
  loaded: boolean;
  stale: string | null;
  /** why Pay is disabled (no shift is running at the till), or null */
  payBlocked: string | null;
  /** the order whose payment is open on the sheet */
  selectedId: number | null;
  images: ProductImages;
  categoryOf: (itemId: number) => string | null;
  onPay: (order: Order) => void;
  onVoid: (order: Order) => void;
}) {
  const t = useTranslations("till");
  const now = useNow();
  const titleId = useId();
  return (
    <section aria-labelledby={titleId} className="space-y-3">
      <div className="flex items-baseline justify-between gap-3 px-1">
        <h2 id={titleId} className="text-[17px] font-semibold text-neutral-900">
          {t("queue_title")}
        </h2>
        {loaded ? <p className="text-sm text-neutral-600">{t("queue_count", { count: orders.length })}</p> : null}
      </div>
      {stale ? (
        <p role="status" className="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-base text-amber-900">
          {stale}
        </p>
      ) : null}
      {!loaded ? (
        <p role="status" className="px-1 text-base text-neutral-600">
          {t("queue_loading")}
        </p>
      ) : orders.length === 0 ? (
        <div className="rounded-2xl border border-neutral-200 bg-white">
          <EmptyState title={t("queue_empty")} level={3} />
        </div>
      ) : (
        <ul aria-label={t("queue_list")} className="flex flex-col gap-2.5">
          {orders.map((order) => (
            <li key={order.id}>
              <QueueCard
                order={order}
                now={now}
                selected={order.id === selectedId}
                payBlocked={payBlocked}
                images={images}
                categoryOf={categoryOf}
                onPay={onPay}
                onVoid={onVoid}
              />
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
