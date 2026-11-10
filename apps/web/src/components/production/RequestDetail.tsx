"use client";

import { useQueryClient } from "@tanstack/react-query";
import { X } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useEffect, useRef, useState } from "react";
import { ApiError } from "@/lib/api/client";
import { uuid } from "@/lib/api/idempotency";
import { acceptRequest, batchGroup, cancelRequest, confirmBatch, sendBackBatch, submitFinishBatch, submitStartBatch, useProductionCard } from "@/lib/api/production";
import type { CardLine, IngredientShort, Locale, ProductImageView, ProductionRequest, RequestStatus } from "@/lib/api/types";
import { compareDecimal, isQuantityText, trimDecimal } from "@/lib/decimal";
import { useErrorText } from "@/lib/errors";
import { canCancel, canSignOff } from "@/lib/live/production";
import { onOutboxApplied, useWaiting } from "@/lib/outbox/store";
import { can, useSession } from "@/lib/session";
import { useDateFormat } from "@/lib/useDates";
import { ICON_STROKE } from "../icons";
import { RequestTransferSheet } from "../inventory/RequestTransferSheet";
import { tableNum } from "../styles";
import { Alert, Badge, Button, Field, IconButton, ProductImage, Table, TextInput, type Tone } from "../ui";
import { formatWhen } from "./RequestCard";
import { useRequestRun } from "./useRequestRun";

// The request card of the board (docs/architecture.md section 6), in a drawer from the right since step 6.3c: the product's photo, its
// name and what to make, the facts (why, where, who, when), the recipe scaled to the quantity with what is on hand against what is needed
// (the shortfalls in red, with a way to ask the store), the steps, and the one next move for this person: Accept, Start, Done with produced
// and waste, Confirm or Send back for an overseer, Cancel where allowed. Every write is online with an Idempotency-Key (start and done go
// through the outbox, step 5.2a); a refusal (a short ingredient, the same person confirming, a 403) shows inline, in the page language,
// from the API's own bilingual message. The section keeps its name, "Request card: <product>".

const STATUS_TONE: Record<RequestStatus, Tone> = {
  requested: "neutral",
  accepted: "blue",
  in_progress: "blue",
  pending_confirmation: "amber",
  done: "positive",
  cancelled: "neutral",
};

function shortfallOf(error: unknown): IngredientShort[] {
  if (!(error instanceof ApiError) || error.key !== "production.batch.ingredient_short") return [];
  const list = error.body.shortfall;
  return Array.isArray(list) ? (list as IngredientShort[]) : [];
}

function RecipeTable({ lines, locale, onTransfer, canTransfer }: { lines: CardLine[]; locale: Locale; onTransfer: (line: CardLine) => void; canTransfer: boolean }) {
  const t = useTranslations("production");
  return (
    <Table>
      <thead>
        <tr>
          <th scope="col">{t("ingredient")}</th>
          <th scope="col" className={tableNum}>
            {t("needed")}
          </th>
          <th scope="col" className={tableNum}>
            {t("on_hand")}
          </th>
          <th scope="col">{t("stock")}</th>
        </tr>
      </thead>
      <tbody>
        {lines.map((line) => {
          const name = locale === "am" ? line.ingredient.name_am : line.ingredient.name_en;
          const short = line.short === true;
          const reserved = line.on_hand !== null && line.available !== null && compareDecimal(line.on_hand, line.available) !== 0;
          return (
            <tr key={line.ingredient.id} className={`align-top ${short ? "bg-red-50 text-red-900" : ""}`}>
              <th scope="row" className="py-2.5 text-left">
                {name}
              </th>
              <td className={`py-2.5 ${tableNum}`}>
                {trimDecimal(line.needed)} {line.ingredient.unit}
              </td>
              <td className={`py-2.5 ${tableNum}`}>
                {line.on_hand === null ? (
                  "–"
                ) : (
                  <>
                    {trimDecimal(line.on_hand)} {line.ingredient.unit}
                    {reserved && line.available !== null ? <span className="block text-[13px] text-neutral-600">{t("available", { qty: trimDecimal(line.available), unit: line.ingredient.unit })}</span> : null}
                  </>
                )}
              </td>
              <td className="space-y-1.5 py-2.5">
                {line.short === null ? (
                  "–"
                ) : short ? (
                  <>
                    <Badge tone="red">{t("short")}</Badge>
                    <span className="block text-[13px] font-medium">{t("missing", { qty: trimDecimal(line.short_qty ?? "0"), unit: line.ingredient.unit })}</span>
                    {canTransfer && line.transfer_hint ? (
                      <Button type="button" variant="secondary" size="md" onClick={() => onTransfer(line)}>
                        {/* named by its text for readers, "Request transfer of <ingredient>" (the p2 shortfall spec reads it) */}
                        <span aria-hidden="true">{t("request_transfer")}</span>
                        <span className="sr-only">{t("request_transfer_of", { name })}</span>
                      </Button>
                    ) : null}
                  </>
                ) : (
                  <Badge tone="positive">{t("enough")}</Badge>
                )}
              </td>
            </tr>
          );
        })}
      </tbody>
    </Table>
  );
}

export function RequestDetail({
  summary,
  image,
  category,
  focus,
  onClose,
  onChanged,
}: {
  summary: ProductionRequest;
  image: ProductImageView | null;
  category: string | null;
  /** `finish`: opened from the card's "Finish · count made", so the Done form takes the focus */
  focus?: "finish";
  onClose: () => void;
  onChanged: () => void;
}) {
  const dates = useDateFormat();
  const t = useTranslations("production");
  const o = useTranslations("outbox");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const queryClient = useQueryClient();
  const me = useSession((s) => s.me);
  const batchUuid = useRef(uuid());
  const producedField = useRef<HTMLDivElement>(null);

  const card = useProductionCard(summary.id, summary.version, summary.batch?.version ?? 0);
  const request: ProductionRequest = card.data ?? summary;
  const name = locale === "am" ? request.product.name_am : request.product.name_en;
  const batch = request.batch;
  const unit = request.product.unit;
  const mine = !!me && request.assigned_to?.id === me.user.id;
  const fulfils = can(me, "production.fulfil");
  const { busy, error, run: runWrite } = useRequestRun(request.id, onChanged);

  const [produced, setProduced] = useState(() => trimDecimal(batch?.planned_qty ?? request.requested_qty));
  const [waste, setWaste] = useState("0");
  const [invalid, setInvalid] = useState<"produced" | "waste" | "empty" | null>(null);
  const [form, setForm] = useState<"send_back" | "cancel" | null>(null);
  const [note, setNote] = useState("");
  const [noteMissing, setNoteMissing] = useState(false);
  const [reason, setReason] = useState("");
  const [transfer, setTransfer] = useState<CardLine | null>(null);
  const [transferred, setTransferred] = useState(false);

  // a batch step saved offline waits in the outbox (step 5.2a): the request's other steps wait for it, and the card is read again once
  // the outbox has sent it
  const group = batchGroup(request.id);
  const waiting = useWaiting((item) => item.group === group);
  const blocked = busy !== null || waiting;
  useEffect(
    () =>
      onOutboxApplied((item) => {
        if (item.group !== group) return;
        void queryClient.invalidateQueries({ queryKey: ["production-card", request.id] });
        onChanged();
      }),
    [group, queryClient, request.id, onChanged],
  );

  async function run(kind: string, payload: unknown, call: (key: string) => Promise<unknown>) {
    if (await runWrite(kind, payload, call)) setForm(null);
  }

  function done() {
    if (!batch) return;
    const p = produced.trim();
    const w = waste.trim();
    if (!isQuantityText(p)) return setInvalid("produced");
    if (!isQuantityText(w)) return setInvalid("waste");
    if (compareDecimal(p, "0") === 0 && compareDecimal(w, "0") === 0) return setInvalid("empty");
    setInvalid(null);
    void run("done", { p, w }, (key) => submitFinishBatch(request.id, batch.id, { produced_qty: p, waste_qty: w }, key));
  }

  function sendBack() {
    if (!batch) return;
    if (!note.trim()) return setNoteMissing(true);
    setNoteMissing(false);
    void run("send_back", note.trim(), (key) => sendBackBatch(batch.id, note.trim(), key));
  }

  const status = request.status;
  const canAccept = status === "requested" && fulfils && (request.assigned_to === null || mine);
  const canStart = status === "accepted" && fulfils && mine;
  const canFinish = status === "in_progress" && fulfils && mine && batch?.status === "started";
  const signOff = status === "pending_confirmation" && batch !== null && canSignOff(me, request.station.id);
  const cancellable = (status === "requested" || status === "accepted") && canCancel(me, request);
  const recipe = card.data?.recipe ?? null;
  const consume = card.data?.consume_location ?? null;
  const output = card.data?.output_location ?? null;
  const shortages = shortfallOf(error);
  const place = (p: { name_en: string; name_am: string }) => (locale === "am" ? p.name_am : p.name_en);

  // opened from the card's "Finish · count made": the figure of what was made is the first thing to type (after the drawer has taken the
  // focus for itself on opening)
  useEffect(() => {
    if (focus !== "finish" || !canFinish) return;
    const timer = window.setTimeout(() => producedField.current?.querySelector("input")?.focus(), 0);
    return () => window.clearTimeout(timer);
  }, [focus, canFinish]);

  const facts: { key: string; text: string; warn?: boolean }[] = [
    { key: "reason", text: t(`reason_${request.reason}` as "reason_stock_out") },
    { key: "station", text: t("station_is", { name: place(request.station) }) },
  ];
  if (request.requested_by) facts.push({ key: "asked", text: t("asked_by", { name: request.requested_by.name }) });
  if (request.assigned_to) facts.push({ key: "baker", text: t("baker", { name: request.assigned_to.name }) });
  if (request.due_at) facts.push({ key: "due", text: t("due", { time: formatWhen(request.due_at, dates) }) });
  if (request.note) facts.push({ key: "note", text: t("note_is", { note: request.note }) });
  if (batch?.finished_by)
    facts.push({
      key: "made",
      text: `${t("made", { produced: trimDecimal(batch.produced_qty ?? "0"), waste: trimDecimal(batch.waste_qty ?? "0"), unit })} · ${t("finished_by", { name: batch.finished_by.name })}`,
    });
  if (batch?.confirmed_by) facts.push({ key: "confirmed", text: t("confirmed_by", { name: batch.confirmed_by.name }) });
  if (batch?.send_back_note && status === "in_progress") facts.push({ key: "sent_back", text: t("sent_back", { note: batch.send_back_note }), warn: true });
  if (status === "cancelled" && request.cancel_reason) facts.push({ key: "cancelled", text: t("cancelled_because", { reason: request.cancel_reason }) });

  return (
    <section aria-label={t("card_label", { name })} className="space-y-5">
      <div className="flex items-start gap-4">
        <ProductImage image={image} name={name} category={category} size="card" className="w-32 shrink-0 rounded-2xl" />
        <div className="min-w-0 flex-1 space-y-1.5">
          <h2 className="text-[22px] font-semibold leading-tight text-neutral-900">{name}</h2>
          <p className="text-base text-neutral-700">{t("to_make", { qty: trimDecimal(request.requested_qty), unit })}</p>
          <Badge tone={STATUS_TONE[status]}>{t(`status_${status}` as "status_requested")}</Badge>
        </div>
        <IconButton label={t("close_card")} title={t("close_card")} variant="ghost" onClick={onClose} className="-mr-2 -mt-2 shrink-0">
          <X className="size-5" strokeWidth={ICON_STROKE} aria-hidden="true" />
        </IconButton>
      </div>

      <ul className="space-y-1 rounded-2xl bg-neutral-50 px-4 py-3 text-[15px] text-neutral-700">
        {facts.map((fact) => (
          <li key={fact.key} className={fact.warn ? "font-medium text-amber-800" : undefined}>
            {fact.text}
          </li>
        ))}
      </ul>

      {card.isError ? <Alert>{errorText.message(card.error)}</Alert> : null}
      {card.isPending ? (
        <p role="status" className="text-base text-neutral-600">
          {t("loading_card")}
        </p>
      ) : null}

      {card.data ? (
        recipe ? (
          <div className="space-y-3">
            <div className="space-y-1">
              <h3 className="text-[17px] font-semibold text-neutral-900">{t("recipe", { version: recipe.version })}</h3>
              <p className="text-[13px] text-neutral-600">{t("recipe_scaled", { yield: trimDecimal(recipe.yield.qty), target: trimDecimal(recipe.target_qty), unit: recipe.yield.unit })}</p>
              {consume ? <p className="text-[13px] text-neutral-600">{t("ingredients_from", { place: place(consume) })}</p> : <p className="text-[13px] font-medium text-amber-800">{t("no_stock_place")}</p>}
            </div>
            <RecipeTable lines={recipe.lines} locale={locale} canTransfer={can(me, "transfers.request") && consume !== null} onTransfer={setTransfer} />
            {transferred ? (
              <p role="status" className="rounded-xl bg-positive-50 px-4 py-3 text-base font-medium text-positive-700">
                {t("transfer_sent")}
              </p>
            ) : null}
            {output ? <p className="text-[13px] text-neutral-600">{t("goes_to", { place: place(output) })}</p> : null}
            {recipe.steps.length > 0 ? (
              <div className="space-y-2 pt-1">
                <h3 className="text-[17px] font-semibold text-neutral-900">{t("steps")}</h3>
                <ol className="list-decimal space-y-1 pl-6 text-base text-neutral-900 marker:text-neutral-500">
                  {[...recipe.steps]
                    .sort((a, b) => a.order - b.order)
                    .map((step) => (
                      <li key={step.order}>{locale === "am" ? step.text_am : step.text_en}</li>
                    ))}
                </ol>
              </div>
            ) : null}
          </div>
        ) : (
          <p className="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-base text-amber-900">{t("no_recipe")}</p>
        )
      ) : null}

      {waiting ? (
        <p role="status" className="rounded-xl bg-blue-50 px-4 py-3 text-base font-medium text-blue-800">
          {o("queued")}
        </p>
      ) : null}
      {error ? (
        <Alert>
          <p>{errorText.message(error)}</p>
          {shortages.length > 0 ? (
            <ul className="mt-2 list-disc space-y-0.5 pl-5">
              {shortages.map((line) => (
                <li key={line.ingredient.id}>
                  {t("short_line", { name: locale === "am" ? line.ingredient.name_am : line.ingredient.name_en, short: trimDecimal(line.short), unit: line.ingredient.unit })}
                </li>
              ))}
            </ul>
          ) : null}
        </Alert>
      ) : null}

      {canFinish ? (
        <form
          aria-label={t("done_form")}
          noValidate
          onSubmit={(event) => {
            event.preventDefault();
            done();
          }}
          className="space-y-4 rounded-2xl border border-neutral-200 p-4"
        >
          <div className="space-y-1">
            <h3 className="text-[17px] font-semibold text-neutral-900">{t("done_form")}</h3>
            <p className="text-[15px] text-neutral-600">{t("done_hint", { unit })}</p>
          </div>
          <div ref={producedField} className="grid gap-3 sm:grid-cols-2">
            <Field label={t("produced")} error={invalid === "produced" ? t("need_qty") : errorText.field(error, "produced_qty")}>
              {(props) => <TextInput {...props} controlSize="lg" inputMode="decimal" className="tabular-nums" value={produced} onChange={(event) => setProduced(event.target.value)} />}
            </Field>
            <Field label={t("waste")} error={invalid === "waste" ? t("need_qty") : errorText.field(error, "waste_qty")}>
              {(props) => <TextInput {...props} controlSize="lg" inputMode="decimal" className="tabular-nums" value={waste} onChange={(event) => setWaste(event.target.value)} />}
            </Field>
          </div>
          {invalid === "empty" ? <p className="text-sm font-medium text-red-700">{t("need_something")}</p> : null}
          <Button type="submit" size="lg" className="w-full" disabled={blocked}>
            {busy === "done" ? t("working") : t("done")}
          </Button>
        </form>
      ) : null}

      {status === "in_progress" && !canFinish && request.assigned_to ? <p className="text-base text-neutral-700">{t("being_made", { name: request.assigned_to.name })}</p> : null}
      {status === "pending_confirmation" && !signOff ? <p className="text-base text-neutral-700">{t("waiting_overseer")}</p> : null}

      {form === "send_back" ? (
        <div className="space-y-3 rounded-2xl border border-neutral-200 p-4">
          <Field label={t("send_back_note")} error={noteMissing ? t("send_back_need_note") : errorText.field(error, "note")}>
            {(props) => <TextInput {...props} controlSize="lg" value={note} maxLength={255} onChange={(event) => setNote(event.target.value)} />}
          </Field>
          <div className="flex flex-wrap gap-3">
            <Button type="button" size="lg" disabled={blocked} onClick={sendBack}>
              {busy === "send_back" ? t("working") : t("send_back_submit")}
            </Button>
            <Button type="button" variant="secondary" size="lg" onClick={() => setForm(null)}>
              {t("keep")}
            </Button>
          </div>
        </div>
      ) : null}

      {form === "cancel" ? (
        <div className="space-y-3 rounded-2xl border border-neutral-200 p-4">
          <Field label={t("cancel_reason")} optionalLabel={t("optional")}>
            {(props) => <TextInput {...props} controlSize="lg" value={reason} maxLength={255} onChange={(event) => setReason(event.target.value)} />}
          </Field>
          <div className="flex flex-wrap gap-3">
            <Button type="button" variant="danger" size="lg" disabled={blocked} onClick={() => void run("cancel", reason.trim(), (key) => cancelRequest(request.id, reason.trim(), key))}>
              {busy === "cancel" ? t("working") : t("cancel_submit")}
            </Button>
            <Button type="button" variant="secondary" size="lg" onClick={() => setForm(null)}>
              {t("keep")}
            </Button>
          </div>
        </div>
      ) : null}

      <div className="flex flex-wrap gap-3">
        {canAccept ? (
          <Button type="button" variant="dark" size="lg" disabled={blocked} onClick={() => void run("accept", null, (key) => acceptRequest(request.id, key))}>
            {busy === "accept" ? t("working") : t("accept")}
          </Button>
        ) : null}
        {canStart ? (
          <Button type="button" variant="dark" size="lg" disabled={blocked} onClick={() => void run("start", batchUuid.current, (key) => submitStartBatch(request.id, batchUuid.current, key))}>
            {busy === "start" ? t("working") : t("start")}
          </Button>
        ) : null}
        {signOff && batch ? (
          <>
            <Button type="button" size="lg" disabled={blocked} onClick={() => void run("confirm", null, (key) => confirmBatch(batch.id, key))}>
              {busy === "confirm" ? t("working") : t("confirm")}
            </Button>
            <Button type="button" variant="secondary" size="lg" disabled={blocked} onClick={() => setForm(form === "send_back" ? null : "send_back")}>
              {t("send_back")}
            </Button>
          </>
        ) : null}
        {cancellable ? (
          <Button type="button" variant="ghost" size="lg" disabled={blocked} onClick={() => setForm(form === "cancel" ? null : "cancel")}>
            {t("cancel")}
          </Button>
        ) : null}
      </div>

      {transfer && card.data?.consume_location && transfer.transfer_hint ? (
        <RequestTransferSheet
          subject={{
            itemId: transfer.ingredient.id,
            name: locale === "am" ? transfer.ingredient.name_am : transfer.ingredient.name_en,
            unit: transfer.ingredient.unit,
            toLocationId: card.data.consume_location.id,
            toName: place(card.data.consume_location),
            fromLocationId: transfer.transfer_hint.from_location_id,
            sourceAvailable: transfer.transfer_hint.store_available,
            suggested: transfer.short_qty ?? transfer.needed,
            productionRequestId: request.id,
          }}
          onClose={() => setTransfer(null)}
          onRequested={() => {
            setTransferred(true);
            void queryClient.invalidateQueries({ queryKey: ["production-card", request.id] });
          }}
        />
      ) : null}
    </section>
  );
}
