"use client";

import { useQueryClient } from "@tanstack/react-query";
import { ChevronLeft, RefreshCw } from "lucide-react";
import { useSearchParams } from "next/navigation";
import { useLocale, useTranslations } from "next-intl";
import { useId, useMemo, useState, type ReactNode } from "react";
import { Link } from "@/i18n/navigation";
import { usePayment } from "@/lib/api/adminPayments";
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
import { RefundDialog } from "../refunds/RefundDialog";
import { VoidDialog } from "../till/VoidDialog";
import { Alert, Button, Chip, PageHeader, Panel, Skeleton, type Tone as ChipTone } from "../ui";
import { buttonClass } from "../styles";
import { CARD, Fact, Notice, ProductThumb, TableCard, useProductLook } from "./kit";

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
// outcome is unknown. The receipt's two actions are 4.5b's (ReceiptActions). The refund (step 4.5g) is the refund dialog of the till
// (components/refunds/RefundDialog.tsx), opened from the Refund section once the order is closed; the section lists the order's refunds,
// each payment card what was paid back of it (GET /payments/{payment} refunds, 4.5c's ["payment", id]), the header what came back.
//
// Step 6.4c (fresh-counter.md 4.4): the title row with its Amharic twin, "Back to the orders board" and Refresh as its actions; the details
// card (the facts in 13 px caps over their values, the balance as a figure with its state chip); then two columns from 1280 px: the lines
// (a table card with each product's photo) and the timeline on the left, the payments, the receipt and the refund on the right.

const STATUS_TONE: Record<OrderStatus, ChipTone> = {
  open: "amber",
  sent: "blue",
  in_progress: "blue",
  ready: "brand",
  served: "brand",
  paid: "green",
  closed: "green",
  cancelled: "neutral",
  voided: "red",
};

const LINE_TONE: Record<LineStatus, ChipTone> = { queued: "neutral", accepted: "blue", ready: "brand", served: "neutral", rejected: "red", voided: "neutral" };

const DOT: Record<Tone, string> = {
  neutral: "bg-neutral-500",
  blue: "bg-blue-700",
  green: "bg-green-700",
  amber: "bg-amber-700",
  red: "bg-red-700",
};

/** `titleAm`: the Amharic title with "{no}" where the order's number goes, set beside the English one on the English page. */
export function OrderRecordScreen({ titleAm }: { titleAm?: string }) {
  const t = useTranslations("orderRecord");
  const params = useSearchParams();
  const id = recordIdOf(params);
  if (id === null) {
    return (
      <>
        <PageHeader title={t("page_title")} actions={<BackToBoard />} />
        <Alert>{t("no_id")}</Alert>
      </>
    );
  }
  return <OrderRecord key={id} id={id} titleAm={titleAm} />;
}

function BackToBoard() {
  const t = useTranslations("orderRecord");
  return (
    <Link href="/admin/orders" className={buttonClass({ variant: "ghost", size: "md", compact: true })}>
      <ChevronLeft className="size-[18px]" strokeWidth={2} aria-hidden="true" />
      {t("back")}
    </Link>
  );
}

function OrderRecord({ id, titleAm }: { id: number; titleAm?: string }) {
  const t = useTranslations("orderRecord");
  const errorText = useErrorText();
  const detail = useOrderDetail(id, null);

  if (!detail.data) {
    if (detail.isError) {
      const missing = detail.error instanceof ApiError && detail.error.status === 404;
      return (
        <>
          <PageHeader title={t("page_title")} actions={<BackToBoard />} />
          <div className="space-y-4">
            <Alert>{missing ? t("not_found") : errorText.message(detail.error)}</Alert>
            {missing ? null : (
              <Button variant="secondary" size="md" onClick={() => void detail.refetch()}>
                {t("retry")}
              </Button>
            )}
          </div>
        </>
      );
    }
    return (
      <>
        <PageHeader title={t("page_title")} actions={<BackToBoard />} />
        <p role="status" className="mb-4 text-[15px] text-neutral-600">
          {t("loading")}
        </p>
        <div aria-hidden="true" className="space-y-5">
          <Skeleton className="h-40 rounded-2xl" />
          <div className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_26rem]">
            <Skeleton className="h-64 rounded-2xl" />
            <Skeleton className="h-64 rounded-2xl" />
          </div>
        </div>
      </>
    );
  }
  return <RecordBody order={detail.data} detail={detail} titleAm={titleAm} />;
}

type DetailQuery = ReturnType<typeof useOrderDetail>;

function RecordBody({ order, detail, titleAm }: { order: OrderDetail; detail: DetailQuery; titleAm?: string }) {
  const t = useTranslations("orderRecord");
  const dates = useDateFormat();
  const client = useQueryClient();
  const no = useOrderNo(order);
  const payments = useOrderPayments(order.id);
  const roster = useRosterPeople();
  const me = useSession((s) => s.me);
  const mayVoid = can(me, "orders.void_request");
  const [voiding, setVoiding] = useState<{ payment?: number } | null>(null);
  const [refunding, setRefunding] = useState(false);
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
      <PageHeader
        title={t("title", { no })}
        intro={t("intro")}
        twin={titleAm ? { text: titleAm.replace("{no}", no), lang: "am" } : undefined}
        actions={
          <>
            <BackToBoard />
            <Button variant="secondary" size="md" disabled={fetching} onClick={refresh}>
              <RefreshCw className={`size-[18px] ${fetching ? "motion-safe:animate-spin" : ""}`} strokeWidth={2} aria-hidden="true" />
              {fetching ? t("refreshing") : t("refresh")}
            </Button>
          </>
        }
      />
      <div className="flex flex-col gap-5">
        <p className="-mt-2 text-[13px] text-neutral-600">{t("read_at", { time: addisStamp(new Date(readAt).toISOString(), dates) })}</p>
        {detail.isRefetchError || payments.isRefetchError ? <Alert>{t("stale")}</Alert> : null}

        <Details order={order} payments={payments.data ?? null} people={people} mayVoid={mayVoid} onVoid={() => setVoiding({})} />

        <div className="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_26rem]">
          <div className="flex min-w-0 flex-col gap-5">
            <Lines order={order} />
            <Timeline order={order} payments={payments} people={people} />
          </div>
          <div className="flex min-w-0 flex-col gap-5">
            <Payments order={order} payments={payments} people={people} mayVoid={mayVoid} onVoid={(payment) => setVoiding({ payment })} onPhoto={openPhoto} />
            <Receipt order={order} />
            {receiptAvailable(order) ? <RefundSection order={order} people={people} mayRefund={mayVoid} onRefund={() => setRefunding(true)} /> : null}
          </div>
        </div>
      </div>
      {voiding ? <VoidDialog order={order} payment={voiding.payment} onClose={() => setVoiding(null)} onVoided={afterVoid} /> : null}
      {refunding ? <RefundDialog order={order} onClose={() => setRefunding(false)} onRefunded={afterVoid} /> : null}
      {viewing ? <PhotoViewer paymentId={viewing.paymentId} fileId={viewing.fileId} onClose={() => setViewing(null)} /> : null}
    </>
  );
}

function usePersonName(people: People): (id: number) => string {
  const t = useTranslations("orderRecord");
  return (id) => nameOf(people, id) ?? t("person_no", { id });
}

// --- the header ---------------------------------------------------------------------------------------------------------------------------

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
  const stateTone: ChipTone = money.state === "due" ? "amber" : money.state === "settled" ? "green" : money.state === "over" ? "red" : "neutral";
  const voidable = mayVoid && canVoidOrder(order);

  return (
    <Panel
      title={t("details")}
      action={
        <div className="flex flex-wrap gap-2.5">
          <HistoryLink kind="order" id={order.id} />
          {voidable ? (
            <Button type="button" variant="danger" size="md" aria-label={o("void_aria", { no })} onClick={onVoid}>
              {o("void")}
            </Button>
          ) : null}
        </div>
      }
    >
      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_17rem]">
        <dl className="grid gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-3">
          <Fact term={t("status")}>
            <Chip tone={STATUS_TONE[order.status]} dot>
              {t(`status_${order.status}`)}
            </Chip>
          </Fact>
          <Fact term={t("business_day")}>{order.business_date ? dayText(order.business_date, dates, { withYear: true }) : "—"}</Fact>
          <Fact term={t("where")}>{where}</Fact>
          <Fact term={t("owner")}>{order.owner.name || person(order.owner.id)}</Fact>
          <Fact term={t("taken_at")}>{t(`via_${order.created_via}`)}</Fact>
          <Fact term={t("created")}>
            <span className="tabular-nums">{addisStamp(order.created_at, dates)}</span>
          </Fact>
          <Fact term={t("total")}>
            <span className="font-semibold">
              <Money amount={order.totals.total} currency={order.currency} />
            </span>
          </Fact>
          <Fact term={t("paid")}>
            <Money amount={order.totals.paid} currency={order.currency} />
          </Fact>
          {money.refunded !== "0.00" ? (
            <>
              <Fact term={t("refunded")}>
                <Money amount={money.refunded} currency={order.currency} />
              </Fact>
              <Fact term={t("net_paid")}>
                <Money amount={money.netPaid} currency={order.currency} />
              </Fact>
            </>
          ) : null}
        </dl>
        <div className="flex flex-col items-start gap-2 rounded-xl bg-neutral-50 p-4">
          <p className="text-[13px] font-semibold uppercase tracking-[0.08em] text-neutral-600">{t("balance")}</p>
          <p role="status" aria-label={t("balance")} className="text-3xl font-bold tracking-tight tabular-nums text-neutral-900">
            <Money amount={money.balance} currency={order.currency} />
          </p>
          <Chip tone={stateTone} dot>
            {stateText}
          </Chip>
        </div>
      </div>
      {!money.inStep ? (
        <div className="mt-4">
          <Notice tone="amber">{t("out_of_step")}</Notice>
        </div>
      ) : null}
    </Panel>
  );
}

function Lines({ order }: { order: OrderDetail }) {
  const t = useTranslations("orderRecord");
  const o = useTranslations("adminOrders");
  const locale = useLocale() as Locale;
  const no = useOrderNo(order);
  const look = useProductLook();
  return (
    <TableCard caption={t("lines_table", { no })} title={t("lines")} minWidth="min-w-[560px]">
      <thead>
        <tr>
          <th scope="col">{t("col_item")}</th>
          <th scope="col" className="text-right">
            {t("col_qty")}
          </th>
          <th scope="col">{t("col_station")}</th>
          <th scope="col">{t("col_status")}</th>
          <th scope="col" className="text-right">
            {t("col_total")}
          </th>
        </tr>
      </thead>
      <tbody>
        {order.lines.length === 0 ? (
          <tr>
            <td colSpan={5} className="text-[15px] text-neutral-600">
              {t("lines_none")}
            </td>
          </tr>
        ) : (
          order.lines.map((line) => {
            const name = locale === "am" ? line.item.name_am || line.item.name_en : line.item.name_en || line.item.name_am;
            const gone = line.status === "voided" || line.status === "rejected";
            return (
              <tr key={line.id} className={gone ? "text-neutral-500" : "text-neutral-900"}>
                <th scope="row" className="font-normal">
                  <span className="flex items-center gap-3">
                    <ProductThumb itemId={line.item.id} name={name} look={look} className={`w-12 rounded-lg ${gone ? "opacity-60 grayscale" : ""}`} />
                    <span className="font-semibold">{name}</span>
                  </span>
                </th>
                <td className="text-right tabular-nums">{trimDecimal(line.qty)}</td>
                <td className="text-neutral-700">{line.station ? (locale === "am" ? line.station.name_am : line.station.name_en) : o("display_case")}</td>
                <td>
                  <Chip tone={LINE_TONE[line.status]}>{t(`line_${line.status}`)}</Chip>
                </td>
                <td className="whitespace-nowrap text-right font-semibold tabular-nums">
                  <Money amount={line.line_total} currency={order.currency} />
                </td>
              </tr>
            );
          })
        )}
      </tbody>
    </TableCard>
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
    <section aria-labelledby={headingId} className={`${CARD} space-y-4 p-5`}>
      <div className="space-y-1">
        <h2 id={headingId} className="text-[17px] font-semibold text-neutral-900">
          {t("timeline")}
        </h2>
        <p className="text-[13px] text-neutral-600">{t("timeline_intro")}</p>
      </div>
      {!settled ? (
        <p role="status" className="text-[15px] text-neutral-600">
          {t("loading")}
        </p>
      ) : entries.length === 0 ? (
        <p className="text-[15px] text-neutral-600">{t("timeline_empty")}</p>
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
      <span aria-hidden className={`absolute -left-[7px] top-1.5 size-3 rounded-full ring-2 ring-white ${DOT[tone]}`} />
      <div className="flex flex-wrap items-baseline justify-between gap-x-3">
        <span className="text-[15px] font-semibold text-neutral-900">{label}</span>
        <time dateTime={entry.at} className="text-[13px] tabular-nums text-neutral-600">
          {addisStamp(entry.at, dates)}
        </time>
      </div>
      {line ? (
        <p className="text-[15px] text-neutral-700">
          {trimDecimal(line.qty)} × {locale === "am" ? line.item.name_am || line.item.name_en : line.item.name_en || line.item.name_am}
        </p>
      ) : null}
      {spec?.payment && entry.paymentId !== null ? (
        <p className="text-[15px] text-neutral-700">{t("entry_payment", { id: entry.paymentId, channel, amount: entry.amount ?? payment?.amount ?? "—", currency })}</p>
      ) : null}
      {entry.actorId !== null ? (
        <p className="text-[13px] text-neutral-700">
          {t("by", { name: person(entry.actorId) })}
          {entry.accountId !== null ? ` · ${t("on_account", { name: person(entry.accountId) })}` : null}
        </p>
      ) : decision ? (
        <p className="text-[13px] text-neutral-600">{t("who_in_history")}</p>
      ) : null}
      {entry.approverId !== null ? <p className="text-[13px] font-semibold text-neutral-900">{t("approved_by", { name: person(entry.approverId) })}</p> : null}
      {entry.reason ? <p className="text-[13px] text-neutral-700">{t("reason", { reason: entry.reason })}</p> : null}
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
  const list = payments.data?.data ?? [];
  return (
    <Panel title={t("payments")}>
      {payments.isPending ? (
        <p role="status" className="text-[15px] text-neutral-600">
          {t("payments_loading")}
        </p>
      ) : payments.isError && !payments.data ? (
        <div className="space-y-3">
          <Alert>{errorText.message(payments.error)}</Alert>
          <Button variant="secondary" size="md" onClick={() => void payments.refetch()}>
            {t("retry")}
          </Button>
        </div>
      ) : list.length === 0 ? (
        <p className="text-[15px] text-neutral-600">{t("payments_none")}</p>
      ) : (
        <div className="-mb-5 divide-y divide-neutral-200 border-t border-neutral-200">
          {list.map((payment) => (
            <PaymentCard
              key={payment.id}
              payment={payment}
              people={people}
              voidable={mayVoid && canVoidPayment(order, payment)}
              onVoid={() => onVoid(payment.id)}
              onPhoto={onPhoto}
            />
          ))}
        </div>
      )}
    </Panel>
  );
}

function PayFact({ term, children }: { term: string; children: ReactNode }) {
  return (
    <>
      <dt className="text-[13px] text-neutral-600">{term}</dt>
      <dd className="text-[15px] text-neutral-900">{children}</dd>
    </>
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
    <article aria-labelledby={headingId} className="space-y-3 py-4">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0 space-y-1.5">
          <h3 id={headingId} className="text-[13px] font-semibold uppercase tracking-[0.08em] text-neutral-600">
            {t("payment", { id: payment.id })}
          </h3>
          <p className="text-[17px] font-semibold text-neutral-900">
            <Money amount={payment.amount} currency={payment.currency} />
            <span className="font-normal text-neutral-600"> · {channelName(payment.channel)}</span>
          </p>
          <PaymentStatusCell payment={payment} />
        </div>
        <div className="shrink-0 text-right">
          {photo.kind === "signed" ? (
            <PhotoThumb key={photo.url} label={p("list.photo_open", { id: payment.id })} fileId={photo.fileId} url={photo.url} onOpen={() => onPhoto(payment.id, photo.fileId)} />
          ) : (
            <p className="max-w-32 text-[13px] text-neutral-600">{photo.kind === "gone" ? t("photo_gone") : t("photo_none")}</p>
          )}
        </div>
      </div>
      <dl className="grid grid-cols-[7.5rem_minmax(0,1fr)] gap-x-3 gap-y-1.5">
        <PayFact term={t("reference")}>{tail ? <span className="font-mono text-sm">{tail}</span> : t("no_reference")}</PayFact>
        <PayFact term={t("received_by")}>{payment.received_by.name || person(payment.received_by.id)}</PayFact>
        <PayFact term={t("received")}>
          <span className="tabular-nums">{addisStamp(payment.received_at, dates)}</span>
        </PayFact>
        {payment.tendered_amount !== null && payment.change_amount !== null ? (
          <>
            <dt className="sr-only">{p("detail.tendered")}</dt>
            <dd className="col-span-2 text-[13px] text-neutral-700">{t("cash_line", { tendered: payment.tendered_amount, change: payment.change_amount, currency: payment.currency })}</dd>
          </>
        ) : null}
        {payment.disputed_at ? (
          <PayFact term={t("disputed_at")}>
            {addisStamp(payment.disputed_at, dates)}
            {payment.dispute_reason ? <span className="block text-[13px] text-neutral-700">{t("reason", { reason: payment.dispute_reason })}</span> : null}
          </PayFact>
        ) : null}
        {payment.verified_at ? <PayFact term={t("verified_at")}>{addisStamp(payment.verified_at, dates)}</PayFact> : null}
        {payment.voided_at ? (
          <PayFact term={t("voided_at")}>
            {addisStamp(payment.voided_at, dates)}
            {payment.void_reason ? <span className="block text-[13px] text-neutral-700">{t("reason", { reason: payment.void_reason })}</span> : null}
          </PayFact>
        ) : null}
        {payment.refunded_amount !== "0.00" ? (
          <PayFact term={t("refunded")}>
            <Money amount={payment.refunded_amount} currency={payment.currency} />
            <PaymentRefunds payment={payment} />
          </PayFact>
        ) : null}
      </dl>
      <div className="flex flex-wrap gap-2">
        <Link href={{ pathname: "/admin/payments", query: { payment: String(payment.id) } }} className={buttonClass({ variant: "secondary", size: "md", compact: true })}>
          {t("open_payment", { id: payment.id })}
        </Link>
        <HistoryLink kind="payment" id={payment.id} label={t("history_payment", { id: payment.id })} />
        {voidable ? (
          <Button type="button" variant="danger" size="md" compact onClick={onVoid}>
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
  const available = receiptAvailable(order);
  return (
    <Panel title={t("receipt")}>
      {available ? (
        <div className="space-y-3">
          <p className="text-[15px] text-neutral-600">{t("receipt_intro")}</p>
          <ReceiptActions order={order} layout="stack" />
        </div>
      ) : (
        <p className="text-[15px] text-neutral-600">{order.status === "cancelled" || order.status === "voided" ? t("receipt_none") : t("receipt_unpaid")}</p>
      )}
    </Panel>
  );
}

/** What was paid back of one payment, row by row (GET /payments/{payment} refunds, 4.5c's ["payment", id]; payments.view). */
function PaymentRefunds({ payment }: { payment: PaymentView }) {
  const t = useTranslations("orderRecord");
  const dates = useDateFormat();
  const detail = usePayment(payment.id);
  const rows = detail.data?.refunds ?? [];
  if (rows.length === 0) return null;
  return (
    <ul aria-label={t("payment_refunds", { id: payment.id })} className="mt-1 space-y-0.5 text-[13px] text-neutral-700">
      {rows.map((row) => (
        <li key={row.id} className="tabular-nums">
          {t("payment_refund_row", { amount: row.amount, currency: payment.currency, when: addisStamp(row.refunded_at, dates) })}
        </li>
      ))}
    </ul>
  );
}

/**
 * The refund (step 4.5g): once the order is closed, its refunds so far (when, how much, who asked and whose PIN approved) and the refund
 * dialog for the lines left; before it closes, a wrong payment is voided instead. A cash refund is asked at the till, by someone working
 * its open shift (payments.shift.required otherwise), which the section says.
 */
function RefundSection({ order, people, mayRefund, onRefund }: { order: OrderDetail; people: People; mayRefund: boolean; onRefund: () => void }) {
  const t = useTranslations("orderRecord");
  const dates = useDateFormat();
  const person = usePersonName(people);
  const refunds = order.refunds ?? [];
  const left = order.lines.some((line) => line.status !== "rejected" && line.status !== "voided" && !line.refunded_at);
  const closed = order.status === "closed";
  return (
    <Panel title={t("refund")}>
      <div className="space-y-3">
        {refunds.length > 0 ? (
          <ul aria-label={t("refunds")} className="divide-y divide-neutral-200 rounded-xl border border-neutral-200">
            {refunds.map((refund) => (
              <li key={refund.id} className="space-y-0.5 px-4 py-3">
                <p className="text-[15px] font-semibold tabular-nums text-neutral-900">{t("refund_row", { amount: refund.amount, currency: order.currency, when: addisStamp(refund.at, dates) })}</p>
                <p className="text-[13px] text-neutral-700">
                  {refund.is_full ? t("refund_whole") : t("refund_lines", { count: refund.lines.length })} · {t("reason", { reason: refund.reason })}
                </p>
                <p className="text-[13px] text-neutral-600">{t("refund_people", { asker: person(refund.requested_by), approver: person(refund.approved_by) })}</p>
              </li>
            ))}
          </ul>
        ) : null}
        {!closed ? (
          <p className="text-[15px] text-neutral-600">{t("refund_void_instead")}</p>
        ) : !left ? (
          <p className="text-[15px] text-neutral-600">{t("refund_none_left")}</p>
        ) : (
          <>
            <p className="text-[15px] text-neutral-600">{t("refund_intro")}</p>
            {mayRefund ? (
              <Button type="button" variant="secondary" size="md" onClick={onRefund}>
                {t("refund_open")}
              </Button>
            ) : null}
          </>
        )}
      </div>
    </Panel>
  );
}
