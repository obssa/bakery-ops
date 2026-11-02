"use client";

import { useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";
import { useMemo, useRef, useState } from "react";
import { ApiError } from "@/lib/api/client";
import { uuid } from "@/lib/api/idempotency";
import { requestPrint, usePrintJobs } from "@/lib/api/till";
import type { PrintJob } from "@/lib/api/types";
import { useErrorText } from "@/lib/errors";
import { keepsKey } from "@/lib/paySheet";
import { failureReason, inFlight, latestJob, PRINT_FALLBACK_REFUSALS, printPhase, qrModules, showsLink } from "@/lib/print";
import { can, useSession } from "@/lib/session";
import { OnlineOnlyButton } from "../OnlineOnly";

// The till's Print (step 5.3c, phase-5 plan 4.3, ADR 0019): POST /orders/{order}/print makes a job for the branch's print bridge, and the
// screen says what became of it from GET /print-jobs?order_id= (polled with its ETag while the job waits or prints): waiting for the
// printer, printing (a bridge claimed it), printed, or not printed (failed after three tries, with why; expired after 15 minutes). When
// the receipt cannot be printed (that job failed or expired, or the print was refused because no printer is connected or the order is
// not paid), the receipt link and its QR code take over: ReceiptActions makes the link and the customer scans it.
//
// Online only, unlike the other station writes without a PIN (plan 10.2 D10): the outbox could send the print an hour later, when the
// customer has gone and the receipt lands in front of the next one; the API also expires a job after 15 minutes. The bridge needs the
// internet as much as the till does, so a print queued offline would wait for both anyway. Offline the button says it needs a connection.

/** The Print button's state and action, used by ReceiptActions (the pay sheet's receipt and the order record). */
export function usePrintReceipt(orderId: number) {
  const canPrint = useSession((s) => can(s.me, "print.create"));
  const canView = useSession((s) => can(s.me, "print.view"));
  const client = useQueryClient();
  const jobs = usePrintJobs(orderId, canPrint && canView);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const [refusal, setRefusal] = useState<string | null>(null);
  // this screen asked for a print: only then does a failure bring the link up by itself (an old failed job on the record does not)
  const [asked, setAsked] = useState(false);
  const [answered, setAnswered] = useState<PrintJob | null>(null);
  // one key and client_uuid per press; kept for a retry only when the outcome is unknown
  const key = useRef(uuid());
  const clientUuid = useRef(uuid());
  const renew = () => {
    key.current = uuid();
    clientUuid.current = uuid();
  };

  const latest = canView ? latestJob(jobs.data ?? []) : answered;
  const fallback = asked && showsLink(latest, refusal);

  async function print() {
    setBusy(true);
    setError(null);
    setRefusal(null);
    try {
      const job = await requestPrint(orderId, { client_uuid: clientUuid.current }, key.current);
      renew();
      setAsked(true);
      setAnswered(job);
      // the answer starts the poll at once (printPollMs reads the queued job)
      client.setQueryData<PrintJob[]>(["print-jobs", orderId], (old) => [job, ...(old ?? []).filter((j) => j.id !== job.id)]);
    } catch (failure) {
      setError(failure);
      if (failure instanceof ApiError && PRINT_FALLBACK_REFUSALS.includes(failure.key)) {
        setAsked(true);
        setRefusal(failure.key);
      }
      if (!keepsKey(failure)) renew();
    } finally {
      setBusy(false);
    }
  }

  return { canPrint, busy, error, latest, fallback, inFlight: inFlight(latest), print };
}

export type PrintState = ReturnType<typeof usePrintReceipt>;

/** The Print button, beside the receipt's other actions. */
export function PrintButton({ state, disabled }: { state: PrintState; disabled: boolean }) {
  const t = useTranslations("till");
  return (
    <OnlineOnlyButton type="button" variant="secondary" className="min-h-14" disabled={disabled || state.busy || state.inFlight} onClick={() => void state.print()}>
      {state.busy ? t("print_busy") : state.latest ? t("print_again") : t("print")}
    </OnlineOnlyButton>
  );
}

/** What became of the latest print job, and the print's refusal or error. */
export function PrintStatus({ state }: { state: PrintState }) {
  const t = useTranslations("till");
  const errorText = useErrorText();
  const job = state.latest;
  let text: string | null = null;
  if (job) {
    const phase = printPhase(job.status);
    if (phase === "failed") text = t("print_failed", { reason: t(`print_reason_${failureReason(job.failure_key)}`) });
    else text = t(`print_${phase}`);
  }
  const tone = !job ? "" : job.status === "printed" ? "bg-green-50 text-green-800" : job.status === "failed" || job.status === "expired" ? "bg-amber-50 text-amber-900" : "bg-neutral-100 text-neutral-800";
  return (
    <>
      {state.error ? (
        <div role="alert" className="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-base text-red-800">
          {errorText.message(state.error)}
        </div>
      ) : null}
      {text ? (
        <p role="status" aria-live="polite" aria-label={t("print_status")} className={`rounded-md px-4 py-3 text-base ${tone}`}>
          {text}
        </p>
      ) : null}
    </>
  );
}

/** The QR code of the receipt link, for the customer to scan with a phone (a quiet zone of four modules around it). */
export function ReceiptQr({ url }: { url: string }) {
  const t = useTranslations("till");
  const { size, dark } = useMemo(() => qrModules(url), [url]);
  const quiet = 4;
  const view = size + quiet * 2;
  const path = useMemo(() => dark.map(([x, y]) => `M${x + quiet} ${y + quiet}h1v1h-1z`).join(""), [dark]);
  return (
    <figure className="space-y-1">
      <svg role="img" aria-label={t("receipt_qr")} viewBox={`0 0 ${view} ${view}`} width={232} height={232} shapeRendering="crispEdges" className="rounded-md border border-neutral-200">
        <rect width={view} height={view} fill="#ffffff" />
        <path d={path} fill="#000000" />
      </svg>
      <figcaption className="text-sm text-neutral-600">{t("receipt_qr_hint")}</figcaption>
    </figure>
  );
}
