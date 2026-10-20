"use client";

import { useLocale, useTranslations } from "next-intl";
import { useId, useState } from "react";
import type { Line, Locale, RejectReason, Ticket } from "@/lib/api/types";
import { REJECT_REASONS } from "@/lib/api/types";
import { trimDecimal } from "@/lib/decimal";
import { ageOf, clock, secondsSince, type TicketAge } from "@/lib/kds";
import { useNow } from "@/lib/useNow";
import { queueAcceptLine, queueReadyLine, queueRejectLine, useOutbox } from "@/lib/outbox/store";
import { Button, Field, TextInput } from "../ui";

// A ticket of the rail: the order, a live timer that turns amber and red (thresholds in lib/kds.ts), the notes, and
// each line of this station with what can be done to it. Accept and ready go through the outbox.

const AGE_STYLE: Record<TicketAge, string> = {
  fresh: "border-neutral-200 bg-white",
  amber: "border-amber-400 bg-amber-50",
  red: "border-red-500 bg-red-50",
};
const TIMER_STYLE: Record<TicketAge, string> = {
  fresh: "text-neutral-700",
  amber: "font-semibold text-amber-800",
  red: "font-semibold text-red-700",
};

function RejectPanel({ line, itemName, onDone }: { line: Line; itemName: string; onDone: () => void }) {
  const t = useTranslations("kds");
  const [reason, setReason] = useState<RejectReason | null>(null);
  const [note, setNote] = useState("");
  const needsNote = reason === "other";
  const ready = reason !== null && (!needsNote || note.trim() !== "");

  return (
    <form
      className="mt-3 space-y-3 rounded-md border border-neutral-300 bg-white p-3"
      onSubmit={(event) => {
        event.preventDefault();
        if (!reason || !ready) return;
        queueRejectLine(line.id, { reason, note: note.trim() === "" ? null : note.trim() });
        onDone();
      }}
    >
      <fieldset className="space-y-1">
        <legend className="text-sm font-medium text-neutral-800">{t("reject_why", { name: itemName })}</legend>
        {REJECT_REASONS.map((value) => (
          <label key={value} className="flex min-h-12 cursor-pointer items-center gap-3 rounded-md px-2 text-base hover:bg-neutral-100">
            <input type="radio" name={`reason-${line.id}`} value={value} checked={reason === value} onChange={() => setReason(value)} className="size-5" />
            {t(`reason_${value}`)}
          </label>
        ))}
      </fieldset>
      <Field label={t("reject_note")} optionalLabel={needsNote ? undefined : t("optional")}>
        {(props) => <TextInput {...props} value={note} onChange={(e) => setNote(e.target.value)} required={needsNote} maxLength={500} />}
      </Field>
      <div className="flex gap-2">
        <Button type="submit" disabled={!ready}>
          {t("reject_confirm")}
        </Button>
        <Button type="button" variant="secondary" onClick={onDone}>
          {t("reject_cancel")}
        </Button>
      </div>
    </form>
  );
}

function LineRow({ line }: { line: Line }) {
  const t = useTranslations("kds");
  const locale = useLocale() as Locale;
  const pending = useOutbox((s) => s.items.some((item) => item.subjectId === line.id));
  const [rejecting, setRejecting] = useState(false);
  const name = locale === "am" ? line.item.name_am : line.item.name_en;
  const modifiers = line.modifiers.map((m) => (locale === "am" ? m.name_am : m.name_en));
  const status = line.status === "queued" ? t("status_queued") : line.status === "accepted" ? t("status_accepted") : t("status_ready");

  return (
    <li className="py-3">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-lg font-semibold text-neutral-900">
            <span>{trimDecimal(line.qty)}</span> × <span>{name}</span>
          </p>
          {modifiers.length > 0 ? <p className="text-base text-neutral-700">{modifiers.join(", ")}</p> : null}
          {line.notes ? <p className="text-base italic text-neutral-700">{t("line_notes", { notes: line.notes })}</p> : null}
        </div>
        <span
          className={`shrink-0 rounded-full px-3 py-1 text-sm font-medium ${
            line.status === "ready" ? "bg-green-100 text-green-800" : line.status === "accepted" ? "bg-blue-100 text-blue-800" : "bg-neutral-100 text-neutral-800"
          }`}
        >
          {pending ? t("sending") : status}
        </span>
      </div>
      {line.status !== "ready" && !rejecting ? (
        <div className="mt-2 flex flex-wrap gap-2">
          {line.status === "queued" ? (
            <Button aria-label={t("accept_line", { name })} disabled={pending} onClick={() => queueAcceptLine(line.id)}>
              {t("accept")}
            </Button>
          ) : null}
          <Button aria-label={t("ready_line", { name })} disabled={pending} onClick={() => queueReadyLine(line.id)}>
            {t("ready")}
          </Button>
          <Button variant="secondary" aria-label={t("reject_line", { name })} disabled={pending} onClick={() => setRejecting(true)}>
            {t("reject")}
          </Button>
        </div>
      ) : null}
      {rejecting ? <RejectPanel line={line} itemName={name} onDone={() => setRejecting(false)} /> : null}
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

  return (
    <article aria-labelledby={titleId} className={`rounded-lg border-2 p-4 ${AGE_STYLE[age]}`}>
      <header className="flex items-start justify-between gap-3">
        <div>
          <h2 id={titleId} className="text-xl font-semibold text-neutral-900">
            {t("order_no", { no: String(order.order_no) })}
          </h2>
          <p className="text-base text-neutral-700">{order.table_code ? t("table", { code: order.table_code }) : t("takeaway")}</p>
          <p className="text-base text-neutral-700">{t("waiter", { name: order.owner.name ?? "" })}</p>
        </div>
        <p className={`text-2xl tabular-nums ${TIMER_STYLE[age]}`}>
          <time dateTime={order.sent_at ?? undefined} aria-label={t("elapsed", { time: clock(seconds) })}>
            {clock(seconds)}
          </time>
          {age !== "fresh" ? <span className="ml-2 text-sm">{age === "red" ? t("late") : t("slow")}</span> : null}
        </p>
      </header>
      {order.notes ? <p className="mt-2 rounded-md bg-white/70 px-3 py-2 text-base text-neutral-800">{t("order_notes", { notes: order.notes })}</p> : null}
      <ul className="mt-2 divide-y divide-neutral-200">
        {ticket.lines.map((line) => (
          <LineRow key={line.id} line={line} />
        ))}
      </ul>
    </article>
  );
}
