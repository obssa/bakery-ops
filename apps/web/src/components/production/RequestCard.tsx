"use client";

import { useEffect, useRef } from "react";
import { AlertTriangle, ClipboardCheck, Timer } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { uuid } from "@/lib/api/idempotency";
import { acceptRequest, batchGroup, confirmBatch, submitStartBatch } from "@/lib/api/production";
import type { Locale, ProductImageView, ProductionRequest } from "@/lib/api/types";
import { whenText, type DateFormatLike } from "@/lib/dates";
import { trimDecimal, compareDecimal } from "@/lib/decimal";
import { useErrorText } from "@/lib/errors";
import { cardMove, cardQty, columnOf, type CardMove } from "@/lib/live/production";
import { onOutboxApplied, useWaiting } from "@/lib/outbox/store";
import { ovenAge } from "@/lib/productionBoard";
import { useSession } from "@/lib/session";
import { useClock, useDateFormat } from "@/lib/useDates";
import { useNow } from "@/lib/useNow";
import { useWaitText } from "@/lib/useWaitText";
import { ICON_STROKE } from "../icons";
import { Button, Chip, ProductImage } from "../ui";
import { focusRing } from "../styles";
import { useRequestRun } from "./useRequestRun";

// One card of the board (fresh-counter.md 3.5, the Production board; redrawn by phase-6/production-board, Ramin 2026-11-10: "too basic").
// A status-coloured edge on the left (the column's colour; amber for a batch left in the oven over a day), the product's 80 px photo beside
// its name (the Amharic name under it on the English page) and the quantity, large, with its unit; the chips that say why it was asked
// (stock out, by hand, scheduled), how urgent and by when (Ethiopian time); then what the state needs: the baker and how long it has been
// baking (a batch in for more than a day says so in amber and asks for it to be finished and counted: a started batch is never cancelled),
// "Made 78 of 80 asked" while it waits for its overseer, or who confirmed it and when. Last, the column's one action, full width in
// charcoal, for the person whose move it is. The name opens the request card (RequestDetail) with the recipe and every other move. The
// words carry the status; colour only adds to them.

/** A due time or a publish time: "27 Oct, 1:50 ጠዋት", in the person's calendar with their date format (lib/dates.ts whenText). */
export function formatWhen(iso: string, fmt: DateFormatLike): string {
  return whenText(iso, fmt);
}

const MOVE_LABEL: Record<CardMove, "action_accept" | "action_start" | "action_finish" | "action_confirm"> = {
  accept: "action_accept",
  start: "action_start",
  finish: "action_finish",
  confirm: "action_confirm",
};

/** The card's left edge, by its column; a stale batch's is amber. */
const EDGE = {
  requested: "bg-ink-300",
  in_progress: "bg-blue-600",
  pending_confirmation: "bg-amber-500",
  done_today: "bg-positive-600",
  stale: "bg-amber-500",
} as const;

/** How long the batch has been in the oven, ticking every second ("Baking for 22:14", "1 h 05"), or the stale warning past a day. */
function Oven({ request, starter }: { request: ProductionRequest; starter: string | null }) {
  const t = useTranslations("production");
  const now = useNow(1000);
  const wait = useWaitText();
  const age = ovenAge(request, now);
  if (!age) return starter ? <p className="text-[13px] text-neutral-600">{starter}</p> : null;
  if (age.stale) {
    return (
      <div className="flex gap-2.5 rounded-xl border border-amber-300 bg-amber-50 px-3 py-2.5 text-amber-900">
        <AlertTriangle className="mt-0.5 size-[18px] shrink-0" strokeWidth={ICON_STROKE} aria-hidden="true" />
        <div className="flex min-w-0 flex-col gap-0.5">
          <p className="text-sm font-semibold">{t("stale_title", { days: age.days })}</p>
          <p className="text-[13px]">{t("stale_hint")}</p>
          {starter ? <p className="text-[13px] text-amber-800">{starter}</p> : null}
        </div>
      </div>
    );
  }
  return (
    <div className="flex flex-col gap-1">
      <p className="flex items-center gap-2 text-[15px] font-semibold text-blue-700">
        <Timer className="size-[18px] shrink-0" strokeWidth={ICON_STROKE} aria-hidden="true" />
        <span className="tabular-nums">{t("baking_for", { time: wait(age.seconds) })}</span>
      </p>
      {starter ? <p className="text-[13px] text-neutral-600">{starter}</p> : null}
    </div>
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
  const clock = useClock();
  const t = useTranslations("production");
  const o = useTranslations("outbox");
  const locale = useLocale() as Locale;
  const dates = useDateFormat();
  const errorText = useErrorText();
  const me = useSession((s) => s.me);
  const now = useNow(60_000);
  const { busy, error, run } = useRequestRun(request.id, onChanged);
  // the batch's client UUID, made once per card so a retry of Start reaches the same batch (ADR 0003)
  const batchUuid = useRef(uuid());

  const name = locale === "am" ? request.product.name_am : request.product.name_en;
  const other = locale === "am" ? null : request.product.name_am;
  const unit = request.product.unit;
  const batch = request.batch;
  const move = cardMove(me, request);
  const column = columnOf(request) ?? "requested";
  const edge = ovenAge(request, now)?.stale ? EDGE.stale : EDGE[column];

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

  // the chips while it waits for a baker: why it was asked, how urgent, and by when
  const chips: { tone: "amber" | "blue" | "red" | "neutral"; text: string }[] = [];
  if (request.status === "requested" || request.status === "accepted") {
    if (request.priority !== "normal") chips.push({ tone: request.priority === "urgent" ? "red" : "amber", text: t(`priority_${request.priority}` as "priority_high") });
    if (request.reason === "stock_out") chips.push({ tone: "amber", text: t("chip_stock_out") });
    else if (request.reason === "manual") chips.push({ tone: "blue", text: request.requested_by ? t("asked_by", { name: request.requested_by.name }) : t("reason_manual") });
    else chips.push({ tone: "blue", text: t("reason_schedule") });
    if (request.due_at) chips.push({ tone: "neutral", text: t("due", { time: formatWhen(request.due_at, dates) }) });
  }

  // the meta line: who has it, or who confirmed it and when
  const meta: string[] = [];
  if (request.status === "requested" || request.status === "accepted") {
    if (request.assigned_to) meta.push(request.status === "accepted" ? t("accepted_by", { name: request.assigned_to.name }) : t("baker", { name: request.assigned_to.name }));
    if (request.note) meta.push(request.note);
  } else if (request.status === "pending_confirmation" && batch) {
    meta.push(t("meta_overseer", { name: batch.finished_by?.name ?? request.assigned_to?.name ?? "" }));
  } else if (request.status === "done") {
    const at = batch?.confirmed_at ?? request.done_at;
    if (at) meta.push(batch?.confirmed_by ? t("meta_confirmed", { time: clock(at) }) : t("meta_done", { time: clock(at) }));
    const who = batch?.confirmed_by ?? batch?.finished_by ?? null;
    if (who) meta.push(who.name);
  }
  const starter =
    request.status === "in_progress" && batch
      ? request.assigned_to
        ? t("meta_started", { name: request.assigned_to.name, time: clock(batch.started_at) })
        : t("meta_started_anon", { time: clock(batch.started_at) })
      : null;

  // what was made, while the batch waits for its overseer
  let made: string | null = null;
  if (request.status === "pending_confirmation" && batch?.produced_qty != null) {
    const waste = batch.waste_qty ?? "0";
    const line = t("chip_made", { produced: trimDecimal(batch.produced_qty), asked: trimDecimal(request.requested_qty) });
    made = compareDecimal(waste, "0", 4) > 0 ? `${line} · ${t("chip_wasted", { waste: trimDecimal(waste) })}` : line;
  }

  return (
    <article
      className={`relative flex flex-col gap-3 overflow-hidden rounded-2xl border bg-white py-3.5 pl-5 pr-3.5 motion-safe:transition-colors motion-safe:duration-150 ${
        selected ? "border-ink-900 inset-ring-1 inset-ring-ink-900" : "border-neutral-200"
      }`}
    >
      <span aria-hidden="true" className={`absolute inset-y-0 left-0 w-1.5 ${edge}`} />
      {/* The button is named by its own text, "Open <product>" (the name the p2 specs read), which readers hear; what the card draws (the
          photo, both names, the quantity) is hidden from them, so the name holds every word it shows (WCAG 2.5.3, axe's
          label-content-name-mismatch) without an aria-label. */}
      <button type="button" aria-pressed={selected} onClick={() => onOpen(request)} className={`flex w-full items-center gap-3 rounded-xl text-left ${focusRing}`}>
        <span className="sr-only">{t("open", { name })}</span>
        <span aria-hidden="true" className="flex w-full min-w-0 items-center gap-3">
          <ProductImage image={image} name={name} category={category} size="thumb" className="w-20 shrink-0 rounded-xl" />
          <span className="flex min-w-0 flex-1 flex-col gap-0.5">
            <span className="line-clamp-2 text-base font-semibold leading-[1.3] text-neutral-900">{name}</span>
            {other ? (
              <span lang="am" className="truncate text-[13px] text-neutral-600">
                {other}
              </span>
            ) : null}
          </span>
          <span className="flex shrink-0 flex-col items-end leading-none">
            <span className="text-[28px] font-bold tabular-nums tracking-tight text-neutral-900">{trimDecimal(cardQty(request))}</span>
            <span className="mt-1 text-xs font-medium text-neutral-600">{unit}</span>
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

      {/* the oven's progress bar needs a planned duration, which no batch carries yet (phase-6 plan 10.2 Q5): the time baking shows instead */}
      {request.status === "in_progress" ? <Oven request={request} starter={starter} /> : null}
      {made ? (
        <p className="flex items-center gap-2 text-[15px] font-semibold text-neutral-900">
          <ClipboardCheck className="size-[18px] shrink-0 text-amber-700" strokeWidth={ICON_STROKE} aria-hidden="true" />
          {made}
        </p>
      ) : null}
      {meta.length > 0 ? <p className="text-[13px] text-neutral-600">{meta.join(" · ")}</p> : null}
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
      {move ? (
        <Button type="button" variant="primary" size="lg" loading={busy !== null} disabled={waiting} onClick={() => act(move)} className="w-full">
          {/* named by its text, "<action>: <product>", for readers; the action alone is what it shows */}
          <span aria-hidden="true">{t(MOVE_LABEL[move])}</span>
          <span className="sr-only">{t("action_of", { action: t(MOVE_LABEL[move]), name })}</span>
        </Button>
      ) : null}
    </article>
  );
}
