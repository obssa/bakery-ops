"use client";

import { AlarmClock, Hourglass, Timer, X, type LucideIcon } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useId, useState } from "react";
import type { Line, Locale, RejectReason, Ticket } from "@/lib/api/types";
import { REJECT_REASONS } from "@/lib/api/types";
import { trimDecimal } from "@/lib/decimal";
import { ageOf, secondsSince, type TicketAge } from "@/lib/kds";
import { useWaitText } from "@/lib/useWaitText";
import { orderNoText } from "@/lib/orderNo";
import { useNow } from "@/lib/useNow";
import { queueAcceptLine, queueReadyLine, queueRejectLine, useOutbox } from "@/lib/outbox/store";
import { focusRing } from "../styles";
import { Button, Field, IconButton, TextInput } from "../ui";

// A ticket of the rail (step 6.3b, fresh-counter.md 3.3): a night card with the order's number, its table or takeaway and the waiter, a timer
// chip, the order's note, and each line of this station: "2×" in the accent, the name, its options and its note in the note colour, and
// what can be done to it, 52 px. A new line is accepted or rejected, an accepted one marked ready or rejected; a ready one shows the
// "Ready for pickup" strip with the waiter's name. Accept, ready and reject go through the outbox. The timer's colour says fresh, waiting
// or late (the thresholds of lib/kds.ts), and each state has its own icon, the late and slow ones a word too, so colour is never the only
// signal.

const TIMER: Record<TicketAge, { box: string; Icon: LucideIcon; word: "slow" | "late" | null }> = {
  fresh: { box: "bg-night-fresh text-night-fresh-text", Icon: Timer, word: null },
  amber: { box: "bg-night-waiting text-night-waiting-text", Icon: Hourglass, word: "slow" },
  red: { box: "bg-night-late text-night-late-text", Icon: AlarmClock, word: "late" },
};

function TimerChip({ sentAt, seconds, age }: { sentAt: string | null; seconds: number; age: TicketAge }) {
  const t = useTranslations("kds");
  // "5:07", "1 h 05" or "3 d" (lib/durations.ts): a ticket left on the rail for days reads in days, never "5709:54"
  const wait = useWaitText()(seconds);
  const { box, Icon, word } = TIMER[age];
  return (
    <p className={`inline-flex h-9 shrink-0 items-center gap-1.5 rounded-[10px] px-3 text-[17px] font-semibold tabular-nums ${box}`}>
      <Icon className="size-[18px]" strokeWidth={2} aria-hidden="true" />
      <time dateTime={sentAt ?? undefined} aria-label={t("elapsed", { time: wait })}>
        {wait}
      </time>
      {word ? <span className="text-sm font-bold">{t(word)}</span> : null}
    </p>
  );
}

function RejectPanel({ line, itemName, onDone }: { line: Line; itemName: string; onDone: () => void }) {
  const t = useTranslations("kds");
  const [reason, setReason] = useState<RejectReason | null>(null);
  const [note, setNote] = useState("");
  const needsNote = reason === "other";
  const ready = reason !== null && (!needsNote || note.trim() !== "");

  return (
    <form
      className="space-y-3 rounded-2xl border border-neutral-300 bg-night-ground p-3"
      onSubmit={(event) => {
        event.preventDefault();
        if (!reason || !ready) return;
        queueRejectLine(line.id, { reason, note: note.trim() === "" ? null : note.trim() });
        onDone();
      }}
    >
      <fieldset className="space-y-1">
        <legend className="mb-1 text-[15px] font-semibold text-night-text">{t("reject_why", { name: itemName })}</legend>
        {REJECT_REASONS.map((value) => (
          <label key={value} className="flex min-h-12 cursor-pointer items-center gap-3 rounded-xl px-2 text-base text-night-text hover:bg-night-divider">
            <input
              type="radio"
              name={`reason-${line.id}`}
              value={value}
              checked={reason === value}
              onChange={() => setReason(value)}
              className={`size-5 accent-night-accent ${focusRing}`}
            />
            {t(`reason_${value}`)}
          </label>
        ))}
      </fieldset>
      <Field label={t("reject_note")} optionalLabel={needsNote ? undefined : t("optional")}>
        {(props) => <TextInput {...props} value={note} onChange={(e) => setNote(e.target.value)} required={needsNote} maxLength={500} />}
      </Field>
      <div className="flex flex-wrap gap-2">
        <Button type="submit" size="lg" disabled={!ready}>
          {t("reject_confirm")}
        </Button>
        <Button type="button" variant="secondary" size="lg" onClick={onDone}>
          {t("reject_cancel")}
        </Button>
      </div>
    </form>
  );
}


function LineRow({ line, waiter }: { line: Line; waiter: string }) {
  const t = useTranslations("kds");
  const locale = useLocale() as Locale;
  const pending = useOutbox((s) => s.items.some((item) => item.subjectId === line.id));
  const [rejecting, setRejecting] = useState(false);
  const name = locale === "am" ? line.item.name_am : line.item.name_en;
  const modifiers = line.modifiers.map((m) => (locale === "am" ? m.name_am : m.name_en));
  const ready = line.status === "ready";
  const status = line.status === "queued" ? t("status_queued") : line.status === "accepted" ? t("status_accepted") : t("status_ready");

  return (
    <li className="space-y-3 py-3 first:pt-0 last:pb-0">
      <div className="flex items-baseline gap-2.5">
        <span className="min-w-8 shrink-0 text-lg font-bold tabular-nums text-night-accent">{trimDecimal(line.qty)}×</span>
        <div className="min-w-0 flex-1 space-y-0.5">
          <p className="text-[17px] font-semibold text-night-text">{name}</p>
          {modifiers.length > 0 ? <p className="text-sm text-night-text-muted">{modifiers.join(", ")}</p> : null}
          {line.notes ? <p className="text-sm text-night-note">{t("line_notes", { notes: line.notes })}</p> : null}
        </div>
        {/* the line's state in words for a reader (the column and the line's own button show it on screen); "Sending…" while it waits */}
        {pending ? (
          <span className="shrink-0 rounded-full bg-night-divider px-2.5 py-1 text-[13px] font-semibold text-neutral-700">{t("sending")}</span>
        ) : (
          <span className="sr-only">{status}</span>
        )}
      </div>
      {ready ? (
        // the Kitchen board's Ready strip: in the fresh colours, with the waiter who picks it up
        <p className="flex min-h-11 flex-wrap items-center justify-between gap-x-3 rounded-xl bg-night-fresh px-3.5 py-2 text-[15px] font-semibold text-night-fresh-text">
          <span>{t("status_ready")}</span>
          {waiter ? <span className="font-medium text-night-text-muted">{waiter}</span> : null}
        </p>
      ) : rejecting ? (
        <RejectPanel line={line} itemName={name} onDone={() => setRejecting(false)} />
      ) : line.status === "queued" ? (
        <div className="flex gap-2">
          <Button size="lg" className="flex-1 font-bold" aria-label={t("accept_line", { name })} disabled={pending} onClick={() => queueAcceptLine(line.id)}>
            {t("accept")}
          </Button>
          <Button size="lg" variant="secondary" aria-label={t("reject_line", { name })} disabled={pending} onClick={() => setRejecting(true)}>
            {t("reject")}
          </Button>
        </div>
      ) : (
        // Cooking: "Ready" across the card as the board has it; a line found spoiled while being made is still rejected, from the small button
        <div className="flex gap-2">
          <Button size="lg" className="flex-1 font-bold" aria-label={t("ready_line", { name })} disabled={pending} onClick={() => queueReadyLine(line.id)}>
            {t("ready")}
          </Button>
          <IconButton size="lg" variant="secondary" label={t("reject_line", { name })} title={t("reject")} disabled={pending} onClick={() => setRejecting(true)}>
            <X className="size-5" strokeWidth={2} aria-hidden="true" />
          </IconButton>
        </div>
      )}
    </li>
  );
}

export function TicketCard({ ticket }: { ticket: Ticket }) {
  const t = useTranslations("kds");
  const now = useNow();
  const titleId = useId();
  const { order } = ticket;
  const seconds = secondsSince(order.sent_at, now);
  const age = ageOf(seconds);
  const where = order.table_code ? t("table", { code: order.table_code }) : t("takeaway");
  const waiter = order.owner.name ?? "";

  return (
    <article aria-labelledby={titleId} className="space-y-3 rounded-[18px] border border-night-border bg-night-surface p-4">
      <header className="flex items-center justify-between gap-3">
        <div className="min-w-0 space-y-0.5">
          <h3 id={titleId} className="text-xl font-bold text-night-text">
            {t("order_no", { no: orderNoText(order.order_no) })}
          </h3>
          {/* "Table 4 · Meron", as the board has it: the table (or takeaway) and the waiter */}
          <p className="text-sm text-night-text-muted">
            {where}
            {waiter ? (
              <>
                <span aria-hidden="true"> · </span>
                {waiter}
              </>
            ) : null}
          </p>
        </div>
        <TimerChip sentAt={order.sent_at} seconds={seconds} age={age} />
      </header>
      {order.notes ? <p className="text-sm font-medium text-night-note">{t("order_notes", { notes: order.notes })}</p> : null}
      <ul className="divide-y divide-night-divider">
        {ticket.lines.map((line) => (
          <LineRow key={line.id} line={line} waiter={waiter} />
        ))}
      </ul>
    </article>
  );
}
