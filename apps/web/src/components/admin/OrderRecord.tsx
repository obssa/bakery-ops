"use client";

import { useQueryClient } from "@tanstack/react-query";
import { useSearchParams } from "next/navigation";
import { useLocale, useTranslations } from "next-intl";
import { useId, useMemo, useState, type ReactNode } from "react";
import { Link } from "@/i18n/navigation";
import { useRosterPeople } from "@/lib/api/adminShifts";
import { ApiError } from "@/lib/api/client";
import { useOrderDetail, useOrderPayments } from "@/lib/api/till";
import type { Line, LineStatus, Locale, OrderDetail, OrderPayments, OrderStatus, PaymentView } from "@/lib/api/types";
import { dayText } from "@/lib/dates";
import { subtractDecimal, trimDecimal } from "@/lib/decimal";
import { useErrorText } from "@/lib/errors";
import { useOrderNo } from "@/lib/orderNo";
import {
  balanceView,
  canVoidOrder,
  canVoidPayment,
  kindLabelKey,
  kindSpec,
  nameOf,
  peopleOf,
  photoOf,
  receiptAvailable,
  recordEntries,
  recordIdOf,
  urlsStale,
  type People,
  type RecordEntry,
  type Tone,
} from "@/lib/orderRecord";
import { referenceTail } from "@/lib/paySheet";
import { can, useSession } from "@/lib/session";
import { useDateFormat } from "@/lib/useDates";
import { HistoryLink } from "../audit/HistoryLink";
import { PhotoThumb, PhotoViewer } from "../payments/PhotoViewer";
import { addisStamp, Money, PaymentStatusCell, useChannelName } from "../payments/shared";
import { ReceiptActions } from "../till/ReceiptPanel";
import { VoidDialog } from "../till/VoidDialog";
import { Alert, Badge, Button, PageHeader } from "../ui";
import { focusRing } from "../styles";

// Admin > Orders > an order's record (step 4.5d; payments.verify: the admin and the manager): /admin/orders/record?id= (a query, not a path
// segment: the static export has no dynamic routes). Two reads that exist already, GET /orders/{order} (the order, its lines and its
// timeline; the orders board's key ["order", id, version], here with no version) and GET /orders/{order}/payments (["order-payments", id],
// the till's void dialog's key and shape), merged into one list by time (lib/orderRecord.ts recordEntries). Neither read answers an ETag, so
// the page does not poll: Refresh reads both again, and so does every void made here. A viewer of a photo always asks for a fresh signed
// address; when it opens more than five minutes (less a margin) after the payments were read, the payments are read again too, so every
// thumbnail gets a fresh address (urlsStale); a picture that fails asks once more (4.5c's useSignedUrl).
//
// The only writes are the existing ones: the void of the whole order (the board's PIN dialog, POST /orders/{order}/void) and the void of
// one payment (4.5b's VoidDialog, POST /payments/{payment}/void), each with a new Idempotency-Key after a refusal and the same one when the
// outcome is unknown. The receipt's two actions are 4.5b's (ReceiptActions). REFUND: not built (steps 4.1b and 4.3d wait for Ramin's answer
// on R-C); `RefundPlaceholder` below marks its place.

const STATUS_TONE: Record<OrderStatus, Tone> = {
  open: "amber",
  sent: "blue",
  in_progress: "blue",
  ready: "blue",
  served: "blue",
  paid: "green",
  closed: "green",
  cancelled: "neutral",
  voided: "red",
};

const LINE_TONE: Record<LineStatus, Tone> = { queued: "neutral", accepted: "blue", ready: "green", served: "neutral", rejected: "red", voided: "neutral" };

const DOT: Record<Tone, string> = {
  neutral: "bg-neutral-400",
  blue: "bg-blue-600",
  green: "bg-green-600",
  amber: "bg-amber-500",
  red: "bg-red-600",
};

export function OrderRecordScreen() {
  const t = useTranslations("orderRecord");
  const params = useSearchParams();
  const id = recordIdOf(params);
  if (id === null) {
    return (
      <>
        <PageHeader title={t("page_title")} />
        <div className="space-y-4">
          <Alert>{t("no_id")}</Alert>
          <BackToBoard />
        </div>
      </>
    );
  }
  return <OrderRecord key={id} id={id} />;
}

function BackToBoard() {
  const t = useTranslations("orderRecord");
  return (
    <Link href="/admin/orders" className={`inline-flex min-h-12 items-center text-base text-blue-800 underline underline-offset-2 ${focusRing}`}>
      {t("back")}
    </Link>
  );
}

function OrderRecord({ id }: { id: number }) {
  const t = useTranslations("orderRecord");
  const errorText = useErrorText();
  const detail = useOrderDetail(id, null);

  if (!detail.data) {
    if (detail.isError) {
      const missing = detail.error instanceof ApiError && detail.error.status === 404;
      return (
        <>
          <PageHeader title={t("page_title")} />
          <div className="space-y-4">
            <Alert>{missing ? t("not_found") : errorText.message(detail.error)}</Alert>
            {missing ? null : (
              <Button variant="secondary" onClick={() => void detail.refetch()}>
                {t("retry")}
              </Button>
            )}
            <BackToBoard />
          </div>
        </>
      );
    }
    return (
      <>
        <PageHeader title={t("page_title")} />
        <p role="status" className="text-base text-neutral-600">
          {t("loading")}
        </p>
      </>
    );
  }
  return <RecordBody order={detail.data} detail={detail} />;
}

type DetailQuery = ReturnType<typeof useOrderDetail>;

function RecordBody({ order, detail }: { order: OrderDetail; detail: DetailQuery }) {
  const t = useTranslations("orderRecord");
  const dates = useDateFormat();
  const client = useQueryClient();
  const no = useOrderNo(order);
  const payments = useOrderPayments(order.id);
  const roster = useRosterPeople();
  const me = useSession((s) => s.me);
  const mayVoid = can(me, "orders.void_request");
  const [voiding, setVoiding] = useState<{ payment?: number } | null>(null);
  const [viewing, setViewing] = useState<{ paymentId: number; fileId: number } | null>(null);

  const list = payments.data?.data;
  const people = useMemo(
    () => peopleOf({ users: roster.data ?? null, order, payments: list ?? null, me: me ? { user: me.user, acting: me.acting } : null }),
    [roster.data, order, list, me],
  );
  const fetching = detail.isFetching || payments.isFetching;
  const readAt = Math.min(detail.dataUpdatedAt, payments.dataUpdatedAt || detail.dataUpdatedAt);

  function refresh() {
    void detail.refetch();
    void payments.refetch();
  }

  function afterVoid() {
    // the dialog has asked for the payments again (["order-payments", id]); the order's timeline and money too
    void client.invalidateQueries({ queryKey: ["order", order.id] });
  }

  function openPhoto(paymentId: number, fileId: number) {
    // the viewer asks for its own fresh address; thumbnails read more than five minutes ago are read again with the payments
    if (urlsStale(payments.dataUpdatedAt, Date.now())) void payments.refetch();
    setViewing({ paymentId, fileId });
  }

  return (
    <>
      <PageHeader title={t("title", { no })} intro={t("intro")} />
      <div className="space-y-6">
        <div className="flex flex-wrap items-center gap-3">
          <Button variant="secondary" disabled={fetching} onClick={refresh}>
            {fetching ? t("refreshing") : t("refresh")}
          </Button>
          <p className="text-sm text-neutral-600">{t("read_at", { time: addisStamp(new Date(readAt).toISOString(), dates) })}</p>
          <BackToBoard />
        </div>
        {detail.isRefetchError || payments.isRefetchError ? <Alert>{t("stale")}</Alert> : null}

        <Details order={order} payments={payments.data ?? null} people={people} mayVoid={mayVoid} onVoid={() => setVoiding({})} />
        <Lines order={order} />

        <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,30rem)]">
          <Timeline order={order} payments={payments} people={people} />
          <div className="space-y-6">
            <Payments order={order} payments={payments} people={people} mayVoid={mayVoid} onVoid={(payment) => setVoiding({ payment })} onPhoto={openPhoto} />
            <Receipt order={order} />
            {receiptAvailable(order) ? <RefundPlaceholder order={order} /> : null}
          </div>
        </div>
      </div>
      {voiding ? <VoidDialog order={order} payment={voiding.payment} onClose={() => setVoiding(null)} onVoided={afterVoid} /> : null}
      {viewing ? <PhotoViewer paymentId={viewing.paymentId} fileId={viewing.fileId} onClose={() => setViewing(null)} /> : null}
    </>
  );
}

function usePersonName(people: People): (id: number) => string {
  const t = useTranslations("orderRecord");
  return (id) => nameOf(people, id) ?? t("person_no", { id });
}

// --- the header ---------------------------------------------------------------------------------------------------------------------------

function Fact({ term, children }: { term: string; children: ReactNode }) {
  return (
    <div className="space-y-0.5">
      <dt className="text-sm font-medium text-neutral-600">{term}</dt>
      <dd className="text-base text-neutral-900">{children}</dd>
    </div>
  );
}

function Details({
  order,
  payments,
  people,
  mayVoid,
  onVoid,
}: {
  order: OrderDetail;
  payments: OrderPayments | null;
  people: People;
  mayVoid: boolean;
  onVoid: () => void;
}) {
  const t = useTranslations("orderRecord");
  const o = useTranslations("adminOrders");
  const dates = useDateFormat();
  const headingId = useId();
  const no = useOrderNo(order);
  const person = usePersonName(people);
  const money = balanceView(order.totals, payments?.data ?? null, payments?.balance ?? null);
  const where =
    order.type === "takeaway" ? t("takeaway") : order.table_code || order.dining_table ? t("dine_in_table", { code: order.table_code ?? order.dining_table?.code ?? "" }) : t("dine_in");
  const stateText =
    money.state === "due"
      ? t("state_due")
      : money.state === "settled"
        ? t("state_settled")
        : money.state === "over"
          ? t("state_over", { amount: subtractDecimal("0.00", money.balance), currency: order.currency })
          : t("state_none");
  const stateTone: Tone = money.state === "due" ? "amber" : money.state === "settled" ? "green" : money.state === "over" ? "red" : "neutral";

  return (
    <section aria-labelledby={headingId} className="space-y-4 rounded-lg border border-neutral-200 bg-white p-4">
      <h2 id={headingId} className="text-lg font-semibold text-neutral-900">
        {t("details")}
      </h2>
      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_auto]">
        <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
          <Fact term={t("status")}>
            <Badge tone={STATUS_TONE[order.status]}>{t(`status_${order.status}`)}</Badge>
          </Fact>
          <Fact term={t("business_day")}>{order.business_date ? dayText(order.business_date, dates, { withYear: true }) : "—"}</Fact>
          <Fact term={t("where")}>{where}</Fact>
          <Fact term={t("owner")}>{order.owner.name || person(order.owner.id)}</Fact>
          <Fact term={t("taken_at")}>{o(`via_${order.created_via}`)}</Fact>
          <Fact term={t("created")}>{addisStamp(order.created_at, dates)}</Fact>
          <Fact term={t("total")}>
            <Money amount={order.totals.total} currency={order.currency} />
          </Fact>
          <Fact term={t("paid")}>
            <Money amount={order.totals.paid} currency={order.currency} />
          </Fact>
        </dl>
        <div className="space-y-2 rounded-lg bg-neutral-50 p-4 lg:min-w-64">
          <p className="text-sm font-medium text-neutral-600">{t("balance")}</p>
          <p role="status" aria-label={t("balance")} className="text-2xl font-semibold tabular-nums text-neutral-900">
            <Money amount={money.balance} currency={order.currency} />
          </p>
          <Badge tone={stateTone}>{stateText}</Badge>
        </div>
      </div>
      {!money.inStep ? <p className="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-base text-amber-900">{t("out_of_step")}</p> : null}
      <div className="flex flex-wrap gap-3">
        {mayVoid && canVoidOrder(order) ? (
          <Button type="button" variant="secondary" aria-label={o("void_aria", { no })} onClick={onVoid}>
            {o("void")}
          </Button>
        ) : null}
        <HistoryLink kind="order" id={order.id} />
      </div>
    </section>
  );
}

function Lines({ order }: { order: OrderDetail }) {
  const t = useTranslations("orderRecord");
  const o = useTranslations("adminOrders");
  const locale = useLocale() as Locale;
  const headingId = useId();
  const no = useOrderNo(order);
  return (
    <section aria-labelledby={headingId} className="space-y-3 rounded-lg border border-neutral-200 bg-white p-4">
      <h2 id={headingId} className="text-lg font-semibold text-neutral-900">
        {t("lines")}
      </h2>
      {order.lines.length === 0 ? (
        <p className="text-base text-neutral-600">{t("lines_none")}</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-left text-base">
            <caption className="sr-only">{t("lines_table", { no })}</caption>
            <thead className="border-b border-neutral-200 text-sm text-neutral-600">
              <tr>
                <th scope="col" className="py-2 pr-4 font-medium">
                  {t("col_item")}
                </th>
                <th scope="col" className="py-2 pr-4 font-medium">
                  {t("col_qty")}
                </th>
                <th scope="col" className="py-2 pr-4 font-medium">
                  {t("col_station")}
                </th>
                <th scope="col" className="py-2 pr-4 font-medium">
                  {t("col_status")}
                </th>
                <th scope="col" className="py-2 text-right font-medium">
                  {t("col_total")}
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-neutral-100">
              {order.lines.map((line) => (
                <tr key={line.id} className={line.status === "voided" || line.status === "rejected" ? "text-neutral-500" : "text-neutral-900"}>
                  <th scope="row" className="py-3 pr-4 font-normal">
                    {locale === "am" ? line.item.name_am || line.item.name_en : line.item.name_en || line.item.name_am}
                  </th>
                  <td className="py-3 pr-4 tabular-nums">{trimDecimal(line.qty)}</td>
                  <td className="py-3 pr-4">{line.station ? (locale === "am" ? line.station.name_am : line.station.name_en) : o("display_case")}</td>
                  <td className="py-3 pr-4">
                    <Badge tone={LINE_TONE[line.status]}>{t(`line_${line.status}`)}</Badge>
                  </td>
                  <td className="py-3 text-right tabular-nums">
                    <Money amount={line.line_total} currency={order.currency} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}

// --- the timeline -------------------------------------------------------------------------------------------------------------------------

type PaymentsQuery = ReturnType<typeof useOrderPayments>;

function Timeline({ order, payments, people }: { order: OrderDetail; payments: PaymentsQuery; people: People }) {
  const t = useTranslations("orderRecord");
  const headingId = useId();
  const no = useOrderNo(order);
  // the list waits for the payments (or their refusal), so an entry about a payment names its channel from the start
  const settled = payments.data !== undefined || payments.isError;
  const list = payments.data?.data;
  const entries = useMemo(() => recordEntries(order.events, list ?? []), [order.events, list]);
  const byId = useMemo(() => new Map((list ?? []).map((p) => [p.id, p])), [list]);
  const lines = useMemo(() => new Map(order.lines.map((line) => [line.id, line])), [order.lines]);

  return (
    <section aria-labelledby={headingId} className="space-y-3 rounded-lg border border-neutral-200 bg-white p-4">
      <h2 id={headingId} className="text-lg font-semibold text-neutral-900">
        {t("timeline")}
      </h2>
      <p className="text-base text-neutral-600">{t("timeline_intro")}</p>
      {!settled ? (
        <p role="status" className="text-base text-neutral-600">
          {t("loading")}
        </p>
      ) : entries.length === 0 ? (
        <p className="text-base text-neutral-600">{t("timeline_empty")}</p>
      ) : (
        <ol aria-label={t("timeline_list", { no })} className="ml-1.5 border-l-2 border-neutral-200">
          {entries.map((entry) => (
            <EntryItem key={entry.key} entry={entry} currency={order.currency} payment={entry.paymentId !== null ? byId.get(entry.paymentId) : undefined} line={entry.lineId !== null ? lines.get(entry.lineId) : undefined} people={people} />
          ))}
        </ol>
      )}
    </section>
  );
}

function EntryItem({ entry, currency, payment, line, people }: { entry: RecordEntry; currency: string; payment: PaymentView | undefined; line: Line | undefined; people: People }) {
  const t = useTranslations("orderRecord");
  const o = useTranslations("adminOrders");
  const locale = useLocale() as Locale;
  const dates = useDateFormat();
  const channelName = useChannelName();
  const person = usePersonName(people);
  const key = kindLabelKey(entry.kind);
  const spec = kindSpec(entry.kind);
  const label = key ? o(key) : entry.kind;
  const tone = spec?.tone ?? "neutral";
  const channel = payment ? channelName(payment.channel) : (entry.channelCode ?? "—");
  const decision = entry.source === "payment" && entry.kind !== "payment.recorded";

  return (
    <li className="relative pb-4 pl-6 last:pb-0">
      <span aria-hidden className={`absolute -left-[7px] top-2 h-3 w-3 rounded-full ring-2 ring-white ${DOT[tone]}`} />
      <div className="flex flex-wrap items-baseline justify-between gap-x-3">
        <span className="text-base font-medium text-neutral-900">{label}</span>
        <time dateTime={entry.at} className="text-sm tabular-nums text-neutral-600">
          {addisStamp(entry.at, dates)}
        </time>
      </div>
      {line ? (
        <p className="text-base text-neutral-700">
          {trimDecimal(line.qty)} × {locale === "am" ? line.item.name_am || line.item.name_en : line.item.name_en || line.item.name_am}
        </p>
      ) : null}
      {spec?.payment && entry.paymentId !== null ? (
        <p className="text-base text-neutral-700">{t("entry_payment", { id: entry.paymentId, channel, amount: entry.amount ?? payment?.amount ?? "—", currency })}</p>
      ) : null}
      {entry.actorId !== null ? (
        <p className="text-sm text-neutral-700">
          {t("by", { name: person(entry.actorId) })}
          {entry.accountId !== null ? ` · ${t("on_account", { name: person(entry.accountId) })}` : null}
        </p>
      ) : decision ? (
        <p className="text-sm text-neutral-600">{t("who_in_history")}</p>
      ) : null}
      {entry.approverId !== null ? <p className="text-sm font-medium text-neutral-900">{t("approved_by", { name: person(entry.approverId) })}</p> : null}
      {entry.reason ? <p className="text-sm text-neutral-700">{t("reason", { reason: entry.reason })}</p> : null}
    </li>
  );
}

// --- the payments -------------------------------------------------------------------------------------------------------------------------

function Payments({
  order,
  payments,
  people,
  mayVoid,
  onVoid,
  onPhoto,
}: {
  order: OrderDetail;
  payments: PaymentsQuery;
  people: People;
  mayVoid: boolean;
  onVoid: (paymentId: number) => void;
  onPhoto: (paymentId: number, fileId: number) => void;
}) {
  const t = useTranslations("orderRecord");
  const errorText = useErrorText();
  const headingId = useId();
  const list = payments.data?.data ?? [];
  return (
    <section aria-labelledby={headingId} className="space-y-3">
      <h2 id={headingId} className="text-lg font-semibold text-neutral-900">
        {t("payments")}
      </h2>
      {payments.isPending ? (
        <p role="status" className="text-base text-neutral-600">
          {t("payments_loading")}
        </p>
      ) : payments.isError && !payments.data ? (
        <div className="space-y-3">
          <Alert>{errorText.message(payments.error)}</Alert>
          <Button variant="secondary" onClick={() => void payments.refetch()}>
            {t("retry")}
          </Button>
        </div>
      ) : list.length === 0 ? (
        <p className="text-base text-neutral-600">{t("payments_none")}</p>
      ) : (
        list.map((payment) => (
          <PaymentCard
            key={payment.id}
            payment={payment}
            people={people}
            voidable={mayVoid && canVoidPayment(order, payment)}
            onVoid={() => onVoid(payment.id)}
            onPhoto={onPhoto}
          />
        ))
      )}
    </section>
  );
}

function PaymentCard({
  payment,
  people,
  voidable,
  onVoid,
  onPhoto,
}: {
  payment: PaymentView;
  people: People;
  voidable: boolean;
  onVoid: () => void;
  onPhoto: (paymentId: number, fileId: number) => void;
}) {
  const t = useTranslations("orderRecord");
  const p = useTranslations("adminPayments");
  const dates = useDateFormat();
  const channelName = useChannelName();
  const person = usePersonName(people);
  const headingId = useId();
  const photo = photoOf(payment);
  const tail = referenceTail(payment.reference_no);

  return (
    <article aria-labelledby={headingId} className="space-y-3 rounded-lg border border-neutral-200 bg-white p-4">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0 space-y-1.5">
          <h3 id={headingId} className="text-base font-semibold text-neutral-900">
            {t("payment", { id: payment.id })}
          </h3>
          <p className="text-lg font-semibold text-neutral-900">
            <Money amount={payment.amount} currency={payment.currency} />
            <span className="font-normal text-neutral-700"> · {channelName(payment.channel)}</span>
          </p>
          <PaymentStatusCell payment={payment} />
        </div>
        <div className="shrink-0 text-right">
          {photo.kind === "signed" ? (
            <PhotoThumb key={photo.url} label={p("list.photo_open", { id: payment.id })} fileId={photo.fileId} url={photo.url} onOpen={() => onPhoto(payment.id, photo.fileId)} />
          ) : (
            <p className="max-w-32 text-sm text-neutral-600">{photo.kind === "gone" ? t("photo_gone") : t("photo_none")}</p>
          )}
        </div>
      </div>
      <dl className="grid grid-cols-[8rem_minmax(0,1fr)] gap-x-3 gap-y-1">
        <dt className="text-sm font-medium text-neutral-600">{t("reference")}</dt>
        <dd className="text-base text-neutral-900">{tail ? <span className="font-mono">{tail}</span> : t("no_reference")}</dd>
        <dt className="text-sm font-medium text-neutral-600">{t("taken_by")}</dt>
        <dd className="text-base text-neutral-900">{payment.received_by.name || person(payment.received_by.id)}</dd>
        <dt className="text-sm font-medium text-neutral-600">{t("received")}</dt>
        <dd className="text-base text-neutral-900">{addisStamp(payment.received_at, dates)}</dd>
        {payment.tendered_amount !== null && payment.change_amount !== null ? (
          <>
            <dt className="sr-only">{p("detail.tendered")}</dt>
            <dd className="col-span-2 text-sm text-neutral-700">{t("cash_line", { tendered: payment.tendered_amount, change: payment.change_amount, currency: payment.currency })}</dd>
          </>
        ) : null}
        {payment.disputed_at ? (
          <>
            <dt className="text-sm font-medium text-neutral-600">{t("disputed_at")}</dt>
            <dd className="text-base text-neutral-900">
              {addisStamp(payment.disputed_at, dates)}
              {payment.dispute_reason ? <span className="block text-sm text-neutral-700">{t("reason", { reason: payment.dispute_reason })}</span> : null}
            </dd>
          </>
        ) : null}
        {payment.verified_at ? (
          <>
            <dt className="text-sm font-medium text-neutral-600">{t("verified_at")}</dt>
            <dd className="text-base text-neutral-900">{addisStamp(payment.verified_at, dates)}</dd>
          </>
        ) : null}
        {payment.voided_at ? (
          <>
            <dt className="text-sm font-medium text-neutral-600">{t("voided_at")}</dt>
            <dd className="text-base text-neutral-900">
              {addisStamp(payment.voided_at, dates)}
              {payment.void_reason ? <span className="block text-sm text-neutral-700">{t("reason", { reason: payment.void_reason })}</span> : null}
            </dd>
          </>
        ) : null}
        {payment.refunded_amount !== "0.00" ? (
          <>
            <dt className="text-sm font-medium text-neutral-600">{t("refunded")}</dt>
            <dd className="text-base text-neutral-900">
              <Money amount={payment.refunded_amount} currency={payment.currency} />
            </dd>
          </>
        ) : null}
      </dl>
      <div className="flex flex-wrap gap-2">
        <Link
          href={{ pathname: "/admin/payments", query: { payment: String(payment.id) } }}
          className={`inline-flex min-h-12 items-center rounded-md border border-neutral-300 bg-white px-3 text-base font-medium text-neutral-900 hover:bg-neutral-100 ${focusRing}`}
        >
          {t("open_payment", { id: payment.id })}
        </Link>
        <HistoryLink kind="payment" id={payment.id} label={t("history_payment", { id: payment.id })} />
        {voidable ? (
          <Button type="button" variant="secondary" compact onClick={onVoid}>
            {t("void_payment", { id: payment.id })}
          </Button>
        ) : null}
      </div>
    </article>
  );
}

// --- the receipt and the refund -----------------------------------------------------------------------------------------------------------

function Receipt({ order }: { order: OrderDetail }) {
  const t = useTranslations("orderRecord");
  const headingId = useId();
  const available = receiptAvailable(order);
  return (
    <section aria-labelledby={headingId} className="space-y-3 rounded-lg border border-neutral-200 bg-white p-4">
      <h2 id={headingId} className="text-lg font-semibold text-neutral-900">
        {t("receipt")}
      </h2>
      {available ? (
        <>
          <p className="text-base text-neutral-600">{t("receipt_intro")}</p>
          <ReceiptActions order={order} />
        </>
      ) : (
        <p className="text-base text-neutral-600">{order.status === "cancelled" || order.status === "voided" ? t("receipt_none") : t("receipt_unpaid")}</p>
      )}
    </section>
  );
}

/**
 * The place of the refund action. The plan (section 6, item 5, 4.5d) puts "the refund action" on this page; refunds are steps 4.1b and
 * 4.3d, on hold for Ramin's answer on R-C (the disposition of each line), so this only says they are not available yet, for an order paid
 * in full. The same marked place is in 4.5b's VoidDialog (its own `RefundPlaceholder`).
 *
 * TODO(p4): 4.3d: the refund of a closed order here: the lines to refund with their dispositions (10.2 R-C), the reason, the manager's PIN,
 * POST /orders/{order}/refund with its own Idempotency-Key and client_uuid; then the timeline's refund kinds (lib/orderRecord.ts ENTRY_KINDS).
 */
function RefundPlaceholder({ order }: { order: OrderDetail }) {
  const t = useTranslations("orderRecord");
  const headingId = useId();
  return (
    <section aria-labelledby={headingId} className="space-y-3 rounded-lg border border-neutral-200 bg-white p-4">
      <h2 id={headingId} className="text-lg font-semibold text-neutral-900">
        {t("refund")}
      </h2>
      <p className="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-base text-amber-900">{t("refund_not_yet")}</p>
      {order.status !== "closed" && order.status !== "paid" ? <p className="text-base text-neutral-600">{t("refund_void_instead")}</p> : null}
    </section>
  );
}
