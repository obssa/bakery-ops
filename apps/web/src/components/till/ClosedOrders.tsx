"use client";

import { useTranslations } from "next-intl";
import { useId } from "react";
import { useClosedOrders } from "@/lib/api/till";
import type { Order } from "@/lib/api/types";
import { compareDecimal } from "@/lib/decimal";
import { useErrorText } from "@/lib/errors";
import { useOrderNo } from "@/lib/orderNo";
import { useDateFormat } from "@/lib/useDates";
import { addisStamp } from "../payments/shared";
import { Alert, Badge, Button } from "../ui";

// The till's closed orders (step 4.5g): where a refund starts at the counter. The queue's feed lists unpaid orders only, and a refund of
// cash must be asked by someone working the till's open shift (the cashier), so the till lists the orders closed lately (GET
// /orders?status=closed, newest first) and each opens the refund through 4.5b's VoidDialog (whose path for a closed order is the refund).

export function ClosedOrders({ active, onRefund }: { active: boolean; onRefund: (order: Order) => void }) {
  const t = useTranslations("till");
  const errorText = useErrorText();
  const closed = useClosedOrders(active);

  return (
    <section className="space-y-4" aria-label={t("tab_closed")}>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-base text-neutral-700">{t("closed_intro")}</p>
        <Button type="button" variant="secondary" disabled={closed.isFetching} onClick={() => void closed.refetch()}>
          {t("closed_refresh")}
        </Button>
      </div>
      {closed.isPending ? (
        <p role="status" className="text-base text-neutral-600">
          {t("closed_loading")}
        </p>
      ) : closed.isError ? (
        <Alert>{errorText.message(closed.error)}</Alert>
      ) : closed.data.length === 0 ? (
        <p className="text-base text-neutral-600">{t("closed_empty")}</p>
      ) : (
        <ul className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
          {closed.data.map((order) => (
            <li key={order.id}>
              <ClosedCard order={order} onRefund={() => onRefund(order)} />
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

function ClosedCard({ order, onRefund }: { order: Order; onRefund: () => void }) {
  const t = useTranslations("till");
  const dates = useDateFormat();
  const no = useOrderNo(order);
  const headingId = useId();
  const refunded = order.refunded_total ?? "0.00";
  return (
    <article aria-labelledby={headingId} className="space-y-2 rounded-lg border border-neutral-200 bg-white p-4">
      <div className="flex items-start justify-between gap-3">
        <h3 id={headingId} className="text-base font-semibold text-neutral-900">
          {t("closed_order", { no })}
        </h3>
        {compareDecimal(refunded, "0", 2) > 0 ? <Badge tone="amber">{t("closed_refunded", { amount: refunded, currency: order.currency })}</Badge> : null}
      </div>
      <p className="text-base text-neutral-900 tabular-nums">{t("closed_total", { amount: order.totals.total, currency: order.currency })}</p>
      {order.closed_at ? <p className="text-sm text-neutral-600">{t("closed_at", { time: addisStamp(order.closed_at, dates) })}</p> : null}
      <Button type="button" variant="secondary" compact aria-label={t("refund_aria", { no })} onClick={onRefund}>
        {t("refund_button")}
      </Button>
    </article>
  );
}
