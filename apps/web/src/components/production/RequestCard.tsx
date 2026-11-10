"use client";

import { useEffect, useRef } from "react";
import { useLocale, useTranslations } from "next-intl";
import { uuid } from "@/lib/api/idempotency";
import { acceptRequest, batchGroup, confirmBatch, submitStartBatch } from "@/lib/api/production";
import type { Locale, ProductImageView, ProductionRequest } from "@/lib/api/types";
import { whenText, type DateFormatLike } from "@/lib/dates";
import { trimDecimal, compareDecimal } from "@/lib/decimal";
import { useErrorText } from "@/lib/errors";
import { cardMove, cardQty, type CardMove } from "@/lib/live/production";
import { onOutboxApplied, useWaiting } from "@/lib/outbox/store";
import { useSession } from "@/lib/session";
import { addisClock } from "@/lib/shifts";
import { useDateFormat } from "@/lib/useDates";
import { useNow } from "@/lib/useNow";
import { useWaitText } from "@/lib/useWaitText";
import { Button, Chip, ProductImage } from "../ui";
import { focusRing, type ButtonVariant } from "../styles";
import { useRequestRun } from "./useRequestRun";

// One card of the board (fresh-counter.md 3.5, the Production board): the product's 56 x 42 thumb beside its name (the Amharic name under
// it on the English page) and the quantity, large; the chip that says why it was asked or what was made; the meta line (who has it, since
// when, what it waits for); and the column's one action at 48 px, for the person whose move it is. The name opens the request card
// (RequestDetail) with the recipe and every other move. The words carry the status; colour only adds to them.

/** A due time or a publish time: "27 Oct, 14:05", in the person's calendar with their date format (lib/dates.ts whenText, step 4.5f). */
export function formatWhen(iso: string, fmt: DateFormatLike): string {
  return whenText(iso, fmt);
}

const MOVE_LOOK: Record<CardMove, { variant: ButtonVariant; label: "action_accept" | "action_start" | "action_finish" | "action_confirm" }> = {
  accept: { variant: "dark", label: "action_accept" },
  start: { variant: "dark", label: "action_start" },
  finish: { variant: "secondary", label: "action_finish" },
  confirm: { variant: "primary", label: "action_confirm" },
};

/** How long a batch has been in, ticking every second: " · 22:14 so far", "1 h 05 so far" (lib/durations.ts). */
function Waited({ since }: { since: string }) {
  const t = useTranslations("production");
  const now = useNow(1000);
  const wait = useWaitText();
  const started = Date.parse(since);
  if (Number.isNaN(started)) return null;
  return (
    <span className="tabular-nums">
      {" · "}
      {t("meta_waited", { time: wait((now - started) / 1000) })}
    </span>
  );
}

export function RequestCard({
  request,
  selected,
  image,
  category,
  onOpen,
  onChanged,
}: {
  request: ProductionRequest;
  selected: boolean;
  /** the product's photo from the catalog (useProductImages), or null for its placeholder */
  image: ProductImageView | null;
  /** the product's category name, for the placeholder's icon */
  category: string | null;
  /** opens the request card; `finish` opens it on its Done form */
  onOpen: (request: ProductionRequest, focus?: "finish") => void;
  onChanged: () => void;
}) {
  const t = useTranslations("production");
  const o = useTranslations("outbox");
  const locale = useLocale() as Locale;
  const dates = useDateFormat();
  const errorText = useErrorText();
  const me = useSession((s) => s.me);
  const { busy, error, run } = useRequestRun(request.id, onChanged);
  // the batch's client UUID, made once per card so a retry of Start reaches the same batch (ADR 0003)
  const batchUuid = useRef(uuid());

  const name = locale === "am" ? request.product.name_am : request.product.name_en;
  const other = locale === "am" ? null : request.product.name_am;
  const unit = request.product.unit;
  const batch = request.batch;
  const move = cardMove(me, request);

  // a Start saved offline waits in the outbox (step 5.2a): the card says so, and the board is read again once it was sent
  const group = batchGroup(request.id);
  const waiting = useWaiting((item) => item.group === group);
  useEffect(() => onOutboxApplied((item) => item.group === group && onChanged()), [group, onChanged]);

  function act(next: CardMove) {
    if (next === "finish") return onOpen(request, "finish");
    if (next === "accept") return void run("accept", null, (key) => acceptRequest(request.id, key));
    if (next === "start") return void run("start", batchUuid.current, (key) => submitStartBatch(request.id, batchUuid.current, key));
    if (batch) void run("confirm", null, (key) => confirmBatch(batch.id, key));
  }

  // the chips: why it was asked while it waits; what was made while it waits for its overseer
  const chips: { tone: "amber" | "blue" | "red"; text: string }[] = [];
  if (request.status === "requested" || request.status === "accepted") {
    if (request.reason === "stock_out") chips.push({ tone: "amber", text: t("chip_stock_out") });
    else if (request.reason === "manual") chips.push({ tone: "blue", text: request.requested_by ? t("asked_by", { name: request.requested_by.name }) : t("reason_manual") });
    else chips.push({ tone: "blue", text: t("reason_schedule") });
    if (request.priority !== "normal") chips.push({ tone: request.priority === "urgent" ? "red" : "amber", text: t(`priority_${request.priority}` as "priority_high") });
  }
  if (request.status === "pending_confirmation" && batch?.produced_qty != null) {
    const waste = batch.waste_qty ?? "0";
    const made = t("chip_made", { produced: trimDecimal(batch.produced_qty), asked: trimDecimal(request.requested_qty) });
    chips.push({ tone: "blue", text: compareDecimal(waste, "0", 4) > 0 ? `${made} · ${t("chip_wasted", { waste: trimDecimal(waste) })}` : made });
  }

  // the meta line: who has it and since when, or what it waits for
  const meta: string[] = [];
  if (request.status === "requested" || request.status === "accepted") {
    if (request.note) meta.push(request.note);
    if (request.due_at) meta.push(t("due", { time: formatWhen(request.due_at, dates) }));
    if (request.assigned_to) meta.push(request.status === "accepted" ? t("accepted_by", { name: request.assigned_to.name }) : t("baker", { name: request.assigned_to.name }));
  } else if (request.status === "in_progress" && batch) {
    const time = addisClock(batch.started_at);
    meta.push(request.assigned_to ? t("meta_started", { name: request.assigned_to.name, time }) : t("meta_started_anon", { time }));
  } else if (request.status === "pending_confirmation" && batch) {
    meta.push(t("meta_overseer", { name: batch.finished_by?.name ?? request.assigned_to?.name ?? "" }));
  } else if (request.status === "done") {
    const at = batch?.confirmed_at ?? request.done_at;
    if (at) meta.push(batch?.confirmed_by ? t("meta_confirmed", { time: addisClock(at) }) : t("meta_done", { time: addisClock(at) }));
    const who = batch?.confirmed_by ?? batch?.finished_by ?? null;
    if (who) meta.push(who.name);
  }

  const look = move ? MOVE_LOOK[move] : null;

  return (
    <article
      className={`flex flex-col gap-2.5 rounded-2xl border bg-white p-3.5 motion-safe:transition-colors motion-safe:duration-150 ${
        selected ? "border-ink-900 inset-ring-1 inset-ring-ink-900" : "border-neutral-200"
      }`}
    >
      {/* The button is named by its own text, "Open <product>" (the name the p2 specs read), which readers hear; what the card draws (the
          thumb, both names, the quantity) is hidden from them, so the name holds every word it shows (WCAG 2.5.3, axe's
          label-content-name-mismatch) without an aria-label. */}
      <button type="button" aria-pressed={selected} onClick={() => onOpen(request)} className={`flex w-full items-center gap-3 rounded-xl text-left ${focusRing}`}>
        <span className="sr-only">{t("open", { name })}</span>
        <span aria-hidden="true" className="flex w-full min-w-0 items-center gap-3">
          <ProductImage image={image} name={name} category={category} size="thumb" className="w-14 shrink-0 rounded-[10px]" />
          <span className="flex min-w-0 flex-1 flex-col gap-0.5">
            <span className="line-clamp-2 text-[15px] font-semibold leading-[1.3] text-neutral-900">{name}</span>
            {other ? (
              <span lang="am" className="truncate text-[13px] text-neutral-600">
                {other}
              </span>
            ) : null}
          </span>
          <span className="flex shrink-0 flex-col items-end leading-tight">
            <span className="text-[22px] font-bold tabular-nums text-neutral-900">{trimDecimal(cardQty(request))}</span>
            <span className="text-xs text-neutral-600">{unit}</span>
          </span>
        </span>
      </button>

      {chips.length > 0 ? (
        <div className="flex flex-wrap gap-1.5">
          {chips.map((chip) => (
            <Chip key={chip.text} tone={chip.tone} className="text-xs">
              {chip.text}
            </Chip>
          ))}
        </div>
      ) : null}

      {/* the oven's progress bar needs a planned duration, which no batch carries yet (phase-6 plan 10.2 Q5): the minutes in show instead */}
      {meta.length > 0 ? (
        <p className="text-[13px] text-neutral-600">
          {meta.join(" · ")}
          {request.status === "in_progress" && batch ? <Waited since={batch.started_at} /> : null}
        </p>
      ) : null}
      {batch?.send_back_note && request.status === "in_progress" ? <p className="text-[13px] font-medium text-amber-800">{t("sent_back", { note: batch.send_back_note })}</p> : null}

      {waiting ? (
        <p role="status" className="text-[13px] font-medium text-blue-700">
          {o("queued")}
        </p>
      ) : null}
      {error ? (
        <p role="alert" className="text-[13px] font-medium text-red-700">
          {errorText.message(error)}
        </p>
      ) : null}
      {move && look ? (
        <Button
          type="button"
          variant={look.variant}
          loading={busy !== null}
          disabled={waiting}
          onClick={() => act(move)}
          className="w-full"
        >
          {/* named by its text, "<action>: <product>", for readers; the action alone is what it shows */}
          <span aria-hidden="true">{t(look.label)}</span>
          <span className="sr-only">{t("action_of", { action: t(look.label), name })}</span>
        </Button>
      ) : null}
    </article>
  );
}
