"use client";

import { useLocale, useTranslations } from "next-intl";
import { useId, type ReactNode } from "react";
import type { Locale, Order } from "@/lib/api/types";
import { thumbStack, unitCount, type ProductImages } from "@/lib/images/orderThumbs";
import { ageOf, secondsSince } from "@/lib/kds";
import { isSettled } from "@/lib/live/orders";
import { visibleLines } from "@/lib/live/pad";
import { useOrderNo } from "@/lib/orderNo";
import { useNow } from "@/lib/useNow";
import { useWaiting } from "@/lib/outbox/store";
import { Badge, EmptyState, ProductImage } from "../ui";

// The list of orders awaiting payment (step 6.3b, fresh-counter.md 3.4): the till's left zone, oldest first. Each card shows the order's
// number and table, its balance, a stack of up to three round product thumbs (+N) so the cashier knows an order by its food, how many items
// it holds and its waiter, and how long it has waited (minutes; the kitchen display's thresholds turn it amber and red, with the words "Slow"
// and "Late" so colour is not the only signal). As on the Till board, the whole card is one button, "Take payment for order N": it opens the
// order on the sheet beside the list, where it is paid, or voided ("Void" moved there from the card in 6.3b). The card open on the sheet has
// the 2 px brand border. A paid order that is not served yet stays, marked settled, until it closes; its card ("Open order N") opens it on the
// sheet, where one of its payments can still be voided (step 4.5b, the void of step 4.3c). The thumbs are joined from the catalog
// (useProductImages, phase-6 plan 10.2 Q7); offline they are the placeholders. Pay itself waits for the till's shift on the sheet, which
// says why (step 3.7d); only a payment saved offline and not sent yet keeps the card shut.

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
  images,
  categoryOf,
  onOpen,
}: {
  order: Order;
  now: number;
  selected: boolean;
  images: ProductImages;
  categoryOf: (itemId: number) => string | null;
  onOpen: (order: Order) => void;
}) {
  const t = useTranslations("till");
  const k = useTranslations("kds");
  const detailsId = useId();
  const blockedId = useId();
  const settled = isSettled(order);
  const seconds = secondsSince(order.sent_at, now);
  const age = ageOf(seconds);
  const minutes = t("queue_age", { minutes: Math.floor(seconds / 60) });
  const no = useOrderNo(order);
  // a payment completed offline waits in the outbox (step 5.2a): no second sheet for this order until it was sent
  const waiting = useWaiting((item) => item.kind === "pay_batch" && item.subjectId === order.id);
  const where = order.type === "takeaway" ? k("takeaway") : order.dining_table ? k("table", { code: order.dining_table.code }) : "";
  const lines = visibleLines(order);
  const units = unitCount(lines);
  const items = units.whole && units.count !== null ? t("queue_items", { count: units.count }) : t("queue_items_qty", { qty: units.text });
  const waiter = order.owner.name ?? "";
  const late = !settled && age !== "fresh";

  return (
    // the Till board's card, one control: its title "Order N" is the button ("Take payment for order N", "Open order N" once paid), and its
    // tap area covers the card; the rest is read as the card's text. The button's visible words are part of its name (WCAG 2.5.3)
    <div
      className={`relative flex flex-col gap-1.5 rounded-2xl border bg-white px-4 py-3.5 text-neutral-900 motion-safe:transition-colors motion-safe:duration-150 has-[button:focus-visible]:ring-4 has-[button:focus-visible]:ring-brand-600/35 has-[button:focus-visible]:ring-offset-1 ${
        selected ? "border-brand-600 ring-1 ring-inset ring-brand-600" : "border-neutral-200 has-[button:enabled:hover]:border-neutral-300"
      } ${waiting ? "opacity-70" : ""}`}
    >
      <div className="flex items-baseline justify-between gap-3">
        <p className={`min-w-0 truncate text-base ${selected ? "font-bold" : "font-semibold"}`}>
          <button
            type="button"
            aria-label={settled ? t("open_order_aria", { no }) : t("take_payment_aria", { no })}
            aria-describedby={waiting ? `${detailsId} ${blockedId}` : detailsId}
            aria-current={selected ? "true" : undefined}
            disabled={waiting}
            onClick={() => onOpen(order)}
            className="text-left outline-none after:absolute after:inset-0 after:rounded-2xl after:content-[''] disabled:cursor-not-allowed"
          >
            {t("order_heading", { no })}
          </button>
          {where ? <span> · {where}</span> : null}
        </p>
        <p className={`shrink-0 text-base tabular-nums ${selected ? "font-bold" : "font-semibold"}`}>
          {order.totals.balance} {order.currency}
        </p>
      </div>
      <div id={detailsId} className="flex items-center justify-between gap-3 text-[13px] text-neutral-600">
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
          {settled ? <Badge tone="green">{t("settled")}</Badge> : null}
          {late ? <Badge tone={age === "red" ? "red" : "amber"}>{k(age === "red" ? "late" : "slow")}</Badge> : null}
          <time
            dateTime={order.sent_at ?? undefined}
            aria-label={t("waiting", { time: minutes })}
            className={late ? (age === "red" ? "font-semibold text-red-700" : "font-semibold text-amber-800") : ""}
          >
            {minutes}
          </time>
        </span>
      </div>
      {waiting ? (
        <p id={blockedId} className="text-sm font-medium text-amber-900">
          {t("payment_waiting")}
        </p>
      ) : null}
    </div>
  );
}


export function TillQueue({
  orders,
  loaded,
  stale,
  selectedId,
  images,
  categoryOf,
  onOpen,
  head,
}: {
  orders: Order[];
  loaded: boolean;
  stale: string | null;
  /** the order open on the sheet */
  selectedId: number | null;
  images: ProductImages;
  categoryOf: (itemId: number) => string | null;
  onOpen: (order: Order) => void;
  /** what heads the list: the till's views */
  head?: ReactNode;
}) {
  const t = useTranslations("till");
  const now = useNow();
  const titleId = useId();
  return (
    // still the region "Orders awaiting payment" (its name is what the specs and a reader find); the board's heading reads "Waiting to pay"
    <section aria-label={t("queue_title")} className="space-y-2.5">
      {head}
      <div className="flex items-baseline justify-between gap-3 px-1 pb-1">
        <h2 id={titleId} className="text-[17px] font-semibold text-neutral-900">
          {t("queue_heading")}
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
              <QueueCard order={order} now={now} selected={order.id === selectedId} images={images} categoryOf={categoryOf} onOpen={onOpen} />
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
