"use client";

import { useTranslations } from "next-intl";
import { useState, type FormEvent, type ReactNode } from "react";
import { useDecision, usePayment } from "@/lib/api/adminPayments";
import { ApiError } from "@/lib/api/client";
import { useIdempotencyKey } from "@/lib/api/idempotency";
import type { PaymentDetail } from "@/lib/api/types";
import { canDispute, canVerify, reasonIssue, REASON_MAX } from "@/lib/adminPayments";
import { useErrorText } from "@/lib/errors";
import { keepsKey } from "@/lib/paySheet";
import { useDateFormat } from "@/lib/useDates";
import { RecordLink } from "../admin/RecordLink";
import { HistoryLink } from "../audit/HistoryLink";
import { Alert, Button, Field, Modal, Textarea, TextInput } from "../ui";
import { PhotoThumb, PhotoViewer } from "./PhotoViewer";
import { addisStamp, Money, PaymentStatusCell, useChannelName, useOrderLabel } from "./shared";

// One payment over the list (step 4.5c): what was taken, by whom and when, its receipt photo (a fresh signed URL in the viewer), and for a
// checker (payments.verify) the two decisions of step 4.3c: verify (recorded or disputed -> verified, an optional note kept in the audit
// trail) and dispute (recorded -> disputed, a reason of 3 to 255 characters; the admins and managers are told). Each decision carries its
// own Idempotency-Key: the same one when the answer was lost (the retry replays it), a new one after a refusal (the API would replay the
// refusal under the old key). It opens from a row and from the address (?payment={id}, the payment_disputed notification's link).

function Row({ term, children }: { term: string; children: ReactNode }) {
  return (
    <div className="grid grid-cols-[10rem_minmax(0,1fr)] gap-3 py-1.5">
      <dt className="text-sm font-medium text-neutral-600">{term}</dt>
      <dd className="text-base text-neutral-900">{children}</dd>
    </div>
  );
}

export function PaymentDialog({
  id,
  canDecide,
  onClose,
  onChanged,
}: {
  id: number;
  canDecide: boolean;
  onClose: () => void;
  onChanged: (payment: PaymentDetail) => void;
}) {
  const t = useTranslations("adminPayments");
  const errorText = useErrorText();
  const payment = usePayment(id);
  const [viewing, setViewing] = useState(false);
  const title = t("detail.title", { id });

  // Escape closes only the viewer while it is open (both dialogs listen for it)
  const close = () => {
    if (!viewing) onClose();
  };

  let body: ReactNode;
  if (payment.isPending) {
    body = (
      <p role="status" className="py-6 text-base text-neutral-600">
        {t("detail.loading")}
      </p>
    );
  } else if (payment.isError) {
    const missing = payment.error instanceof ApiError && payment.error.status === 404;
    body = (
      <div className="space-y-3">
        <Alert>{missing ? t("detail.not_found") : errorText.message(payment.error)}</Alert>
        {missing ? null : (
          <Button variant="secondary" onClick={() => void payment.refetch()}>
            {t("viewer.retry")}
          </Button>
        )}
      </div>
    );
  } else {
    body = <Details payment={payment.data} canDecide={canDecide} onPhoto={() => setViewing(true)} onChanged={onChanged} />;
  }

  return (
    <Modal label={title} size="lg" onClose={close}>
      <div className="flex items-start justify-between gap-3">
        <h2 className="text-xl font-semibold text-neutral-900">{title}</h2>
        <Button variant="secondary" onClick={onClose}>
          {t("close")}
        </Button>
      </div>
      {body}
      {viewing && payment.data?.receipt ? <PhotoViewer paymentId={id} fileId={payment.data.receipt.file_id} onClose={() => setViewing(false)} /> : null}
    </Modal>
  );
}

function Details({ payment, canDecide, onPhoto, onChanged }: { payment: PaymentDetail; canDecide: boolean; onPhoto: () => void; onChanged: (payment: PaymentDetail) => void }) {
  const t = useTranslations("adminPayments");
  const dates = useDateFormat();
  const channelName = useChannelName();
  const orderLabel = useOrderLabel();
  const order = payment.order;
  return (
    <div className="space-y-5">
      <div className="grid gap-5 md:grid-cols-[minmax(0,1fr)_auto]">
        <dl className="divide-y divide-neutral-100">
          <Row term={t("detail.status")}>
            <PaymentStatusCell payment={payment} />
          </Row>
          <Row term={t("detail.amount")}>
            <Money amount={payment.amount} currency={payment.currency} />
          </Row>
          <Row term={t("detail.channel")}>{channelName(payment.channel)}</Row>
          <Row term={t("detail.reference")}>{payment.reference_no ? <span className="font-mono">{payment.reference_no}</span> : t("list.no_reference")}</Row>
          {payment.tendered_amount !== null ? (
            <Row term={t("detail.tendered")}>
              <Money amount={payment.tendered_amount} currency={payment.currency} />
            </Row>
          ) : null}
          {payment.change_amount !== null ? (
            <Row term={t("detail.change")}>
              <Money amount={payment.change_amount} currency={payment.currency} />
            </Row>
          ) : null}
          <Row term={t("detail.order")}>
            {t("list.order_label", { no: orderLabel(order) })}
            {order?.table_code ? ` · ${t("list.table_code", { table: order.table_code })}` : ""}
          </Row>
          {order ? (
            <Row term={t("detail.order_total")}>
              <Money amount={order.total} currency={payment.currency} />
            </Row>
          ) : null}
          <Row term={t("detail.received")}>{addisStamp(payment.received_at, dates)}</Row>
          <Row term={t("detail.received_by")}>{payment.received_by.name ?? "—"}</Row>
          {payment.verified_at ? <Row term={t("detail.verified_at")}>{addisStamp(payment.verified_at, dates)}</Row> : null}
          {payment.disputed_at ? <Row term={t("detail.disputed_at")}>{addisStamp(payment.disputed_at, dates)}</Row> : null}
          {payment.dispute_reason ? <Row term={t("detail.dispute_reason")}>{payment.dispute_reason}</Row> : null}
          {payment.voided_at ? <Row term={t("detail.voided_at")}>{addisStamp(payment.voided_at, dates)}</Row> : null}
          {payment.void_reason ? <Row term={t("detail.void_reason")}>{payment.void_reason}</Row> : null}
          {payment.refunded_amount !== "0.00" ? (
            <Row term={t("detail.refunded")}>
              <Money amount={payment.refunded_amount} currency={payment.currency} />
            </Row>
          ) : null}
        </dl>
        <div className="space-y-2">
          <p className="text-sm font-medium text-neutral-600">{t("detail.photo")}</p>
          {payment.receipt ? (
            <PhotoThumb key={payment.receipt.url ?? "none"} large label={t("detail.photo_open")} fileId={payment.receipt.file_id} url={payment.receipt.url} onOpen={onPhoto} />
          ) : (
            <p className="text-base text-neutral-700">{t("detail.photo_none")}</p>
          )}
          <div className="flex flex-col items-start gap-2 pt-2">
            <HistoryLink kind="payment" id={payment.id} />
            {order ? <RecordLink orderId={order.id} label={t("detail.open_record")} /> : null}
          </div>
        </div>
      </div>
      {canDecide ? <Decisions payment={payment} onChanged={onChanged} /> : <p className="text-base text-neutral-600">{t("detail.view_only")}</p>}
    </div>
  );
}

function Decisions({ payment, onChanged }: { payment: PaymentDetail; onChanged: (payment: PaymentDetail) => void }) {
  const t = useTranslations("adminPayments");
  const errorText = useErrorText();
  const decision = useDecision();
  const verifyKey = useIdempotencyKey();
  const disputeKey = useIdempotencyKey();
  const [note, setNote] = useState("");
  const [reason, setReason] = useState("");
  const [reasonError, setReasonError] = useState(false);
  const [done, setDone] = useState<"verify" | "dispute" | null>(null);
  const [failure, setFailure] = useState<{ kind: "verify" | "dispute"; error: unknown } | null>(null);

  function decide(kind: "verify" | "dispute", text: string) {
    setDone(null);
    setFailure(null);
    const keys = kind === "verify" ? verifyKey : disputeKey;
    const idempotencyKey = keys.keyFor({ id: payment.id, kind, text: text.trim() });
    decision.mutate(
      { id: payment.id, kind, text, idempotencyKey },
      {
        onSuccess: (answer) => {
          keys.done();
          setDone(kind);
          if (kind === "dispute") setReason("");
          else setNote("");
          onChanged(answer);
        },
        onError: (error) => {
          // a refusal wrote nothing and would be replayed under the same key: the next try is a new request
          if (!keepsKey(error)) keys.done();
          setFailure({ kind, error });
        },
      },
    );
  }

  function onVerify(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    decide("verify", note);
  }

  function onDispute(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (reasonIssue(reason) !== null) {
      setReasonError(true);
      return;
    }
    setReasonError(false);
    decide("dispute", reason);
  }

  /** A refused reason is 422 validation.failed on `reason`; its text is the dictionary's twin of payments.dispute.reason_required. */
  function failureText(kind: "verify" | "dispute"): string | null {
    if (!failure || failure.kind !== kind) return null;
    const error = failure.error;
    if (kind === "dispute" && error instanceof ApiError && error.key === "validation.failed" && error.fieldErrors.reason?.length) return t("detail.reason_short");
    return errorText.message(error);
  }

  const verifiable = canVerify(payment);
  const disputable = canDispute(payment);
  const pending = decision.isPending;
  return (
    <div className="space-y-4 border-t border-neutral-200 pt-4">
      <p role="status" aria-live="polite" className={done ? "rounded-md bg-green-50 px-4 py-3 text-base text-green-800" : "sr-only"}>
        {done === "verify" ? t("detail.verified") : done === "dispute" ? t("detail.disputed") : null}
      </p>
      {!verifiable && !disputable ? <p className="text-base text-neutral-700">{t("detail.settled", { status: t(`status.${payment.status}`) })}</p> : null}
      <div className="grid gap-5 md:grid-cols-2">
        {verifiable ? (
          <form onSubmit={onVerify} className="space-y-3 rounded-lg border border-neutral-200 p-4" noValidate>
            <h3 className="text-lg font-semibold text-neutral-900">{t("detail.verify_title")}</h3>
            <p className="text-base text-neutral-600">{t("detail.verify_intro")}</p>
            <Field label={t("detail.note")} optionalLabel={t("optional")} hint={t("detail.note_hint")}>
              {(props) => <TextInput {...props} value={note} maxLength={255} onChange={(event) => setNote(event.target.value)} />}
            </Field>
            {failureText("verify") ? <Alert>{failureText("verify")}</Alert> : null}
            <Button type="submit" disabled={pending} className="min-h-14 w-full">
              {pending && decision.variables?.kind === "verify" ? t("working") : t("detail.verify")}
            </Button>
          </form>
        ) : null}
        {disputable ? (
          <form onSubmit={onDispute} className="space-y-3 rounded-lg border border-neutral-200 p-4" noValidate>
            <h3 className="text-lg font-semibold text-neutral-900">{t("detail.dispute_title")}</h3>
            <p className="text-base text-neutral-600">{t("detail.dispute_intro")}</p>
            <Field label={t("detail.reason")} error={reasonError ? t("detail.reason_short") : undefined}>
              {(props) => <Textarea {...props} value={reason} maxLength={REASON_MAX} onChange={(event) => setReason(event.target.value)} />}
            </Field>
            {failureText("dispute") ? <Alert>{failureText("dispute")}</Alert> : null}
            <Button type="submit" variant="secondary" disabled={pending} className="min-h-14 w-full border-red-300 text-red-800 hover:bg-red-50">
              {pending && decision.variables?.kind === "dispute" ? t("working") : t("detail.dispute")}
            </Button>
          </form>
        ) : null}
      </div>
    </div>
  );
}
