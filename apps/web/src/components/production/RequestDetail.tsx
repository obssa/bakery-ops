"use client";

import { useQueryClient } from "@tanstack/react-query";
import { useLocale, useTranslations } from "next-intl";
import { useRef, useState } from "react";
import { ApiError } from "@/lib/api/client";
import { uuid, useIdempotencyKey } from "@/lib/api/idempotency";
import { acceptRequest, cancelRequest, confirmBatch, finishBatch, sendBackBatch, startBatch, useProductionCard } from "@/lib/api/production";
import type { CardLine, IngredientShort, Locale, ProductionRequest, RequestStatus } from "@/lib/api/types";
import { compareDecimal, isQuantityText, trimDecimal } from "@/lib/decimal";
import { useErrorText } from "@/lib/errors";
import { canCancel, canSignOff } from "@/lib/live/production";
import { can, useSession } from "@/lib/session";
import { RequestTransferSheet } from "../inventory/RequestTransferSheet";
import { Alert, Badge, Button, Field, TextInput } from "../ui";
import { formatWhen } from "./RequestCard";

// The request card of the board (docs/architecture.md section 6): the recipe scaled to the quantity, what is on hand
// against what is needed with the shortfalls in red (and a way to ask the store), the steps, and the one next move for
// this person: Accept, Start, Done with produced and waste, Confirm or Send back for an overseer, Cancel where allowed.
// Every write is online with an Idempotency-Key; a refusal (a short ingredient, the same person confirming, a 403) shows
// inline, in the page language, from the API's own bilingual message.

const STATUS_TONE: Record<RequestStatus, "neutral" | "blue" | "green" | "amber" | "red"> = {
  requested: "neutral",
  accepted: "blue",
  in_progress: "blue",
  pending_confirmation: "amber",
  done: "green",
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
    <table className="w-full border-collapse text-left text-base">
      <thead>
        <tr className="border-b border-neutral-300 text-sm text-neutral-700">
          <th scope="col" className="py-2 pr-2 font-medium">
            {t("ingredient")}
          </th>
          <th scope="col" className="py-2 pr-2 font-medium">
            {t("needed")}
          </th>
          <th scope="col" className="py-2 pr-2 font-medium">
            {t("on_hand")}
          </th>
          <th scope="col" className="py-2 font-medium">
            {t("stock")}
          </th>
        </tr>
      </thead>
      <tbody>
        {lines.map((line) => {
          const name = locale === "am" ? line.ingredient.name_am : line.ingredient.name_en;
          const short = line.short === true;
          const reserved = line.on_hand !== null && line.available !== null && compareDecimal(line.on_hand, line.available) !== 0;
          return (
            <tr key={line.ingredient.id} className={`border-b border-neutral-200 align-top ${short ? "bg-red-50 text-red-900" : "text-neutral-900"}`}>
              <th scope="row" className="py-2 pr-2 font-medium">
                {name}
              </th>
              <td className="py-2 pr-2">
                {trimDecimal(line.needed)} {line.ingredient.unit}
              </td>
              <td className="py-2 pr-2">
                {line.on_hand === null ? (
                  "–"
                ) : (
                  <>
                    {trimDecimal(line.on_hand)} {line.ingredient.unit}
                    {reserved && line.available !== null ? <span className="block text-sm text-neutral-700">{t("available", { qty: trimDecimal(line.available), unit: line.ingredient.unit })}</span> : null}
                  </>
                )}
              </td>
              <td className="space-y-1 py-2">
                {line.short === null ? (
                  "–"
                ) : short ? (
                  <>
                    <Badge tone="red">{t("short")}</Badge>
                    <span className="block text-sm font-medium">{t("missing", { qty: trimDecimal(line.short_qty ?? "0"), unit: line.ingredient.unit })}</span>
                    {canTransfer && line.transfer_hint ? (
                      <Button type="button" variant="secondary" aria-label={t("request_transfer_of", { name })} onClick={() => onTransfer(line)}>
                        {t("request_transfer")}
                      </Button>
                    ) : null}
                  </>
                ) : (
                  <Badge tone="green">{t("enough")}</Badge>
                )}
              </td>
            </tr>
          );
        })}
      </tbody>
    </table>
  );
}

export function RequestDetail({ summary, onClose, onChanged }: { summary: ProductionRequest; onClose: () => void; onChanged: () => void }) {
  const t = useTranslations("production");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const queryClient = useQueryClient();
  const me = useSession((s) => s.me);
  const keys = useIdempotencyKey();
  const batchUuid = useRef(uuid());

  const card = useProductionCard(summary.id, summary.version, summary.batch?.version ?? 0);
  const request: ProductionRequest = card.data ?? summary;
  const name = locale === "am" ? request.product.name_am : request.product.name_en;
  const batch = request.batch;
  const unit = request.product.unit;
  const mine = !!me && request.assigned_to?.id === me.user.id;
  const fulfils = can(me, "production.fulfil");

  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<unknown>(null);
  const [produced, setProduced] = useState(() => trimDecimal(batch?.planned_qty ?? request.requested_qty));
  const [waste, setWaste] = useState("0");
  const [invalid, setInvalid] = useState<"produced" | "waste" | "empty" | null>(null);
  const [form, setForm] = useState<"send_back" | "cancel" | null>(null);
  const [note, setNote] = useState("");
  const [noteMissing, setNoteMissing] = useState(false);
  const [reason, setReason] = useState("");
  const [transfer, setTransfer] = useState<CardLine | null>(null);
  const [transferred, setTransferred] = useState(false);

  async function run(kind: string, payload: unknown, call: (key: string) => Promise<unknown>) {
    setBusy(kind);
    setError(null);
    try {
      await call(keys.keyFor({ kind, id: request.id, payload }));
      keys.done();
      setForm(null);
      await queryClient.invalidateQueries({ queryKey: ["production-card", request.id] });
      onChanged();
    } catch (failure) {
      setError(failure);
    } finally {
      setBusy(null);
    }
  }

  function done() {
    if (!batch) return;
    const p = produced.trim();
    const w = waste.trim();
    if (!isQuantityText(p)) return setInvalid("produced");
    if (!isQuantityText(w)) return setInvalid("waste");
    if (compareDecimal(p, "0") === 0 && compareDecimal(w, "0") === 0) return setInvalid("empty");
    setInvalid(null);
    void run("done", { p, w }, (key) => finishBatch(batch.id, { produced_qty: p, waste_qty: w }, key));
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

  return (
    <section aria-label={t("card_label", { name })} className="space-y-4 rounded-lg border border-neutral-200 bg-white p-4 xl:sticky xl:top-4 xl:self-start">
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0 space-y-1">
          <h2 className="text-xl font-semibold text-neutral-900">{name}</h2>
          <p className="text-base text-neutral-800">{t("to_make", { qty: trimDecimal(request.requested_qty), unit })}</p>
        </div>
        <div className="flex shrink-0 flex-col items-end gap-2">
          <Badge tone={STATUS_TONE[status]}>{t(`status_${status}` as "status_requested")}</Badge>
          <Button type="button" variant="secondary" onClick={onClose}>
            {t("close_card")}
          </Button>
        </div>
      </div>

      <ul className="space-y-0.5 text-base text-neutral-700">
        <li>{t(`reason_${request.reason}` as "reason_stock_out")}</li>
        <li>{t("station_is", { name: place(request.station) })}</li>
        {request.requested_by ? <li>{t("asked_by", { name: request.requested_by.name })}</li> : null}
        {request.assigned_to ? <li>{t("baker", { name: request.assigned_to.name })}</li> : null}
        {request.due_at ? <li>{t("due", { time: formatWhen(request.due_at, locale) })}</li> : null}
        {request.note ? <li>{t("note_is", { note: request.note })}</li> : null}
        {batch?.finished_by ? (
          <li>
            {t("made", { produced: trimDecimal(batch.produced_qty ?? "0"), waste: trimDecimal(batch.waste_qty ?? "0"), unit })} · {t("finished_by", { name: batch.finished_by.name })}
          </li>
        ) : null}
        {batch?.confirmed_by ? <li>{t("confirmed_by", { name: batch.confirmed_by.name })}</li> : null}
        {batch?.send_back_note && status === "in_progress" ? <li className="font-medium text-amber-900">{t("sent_back", { note: batch.send_back_note })}</li> : null}
        {status === "cancelled" && request.cancel_reason ? <li>{t("cancelled_because", { reason: request.cancel_reason })}</li> : null}
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
            <h3 className="text-lg font-semibold text-neutral-900">{t("recipe", { version: recipe.version })}</h3>
            <p className="text-sm text-neutral-700">{t("recipe_scaled", { yield: trimDecimal(recipe.yield.qty), target: trimDecimal(recipe.target_qty), unit: recipe.yield.unit })}</p>
            {consume ? <p className="text-sm text-neutral-700">{t("ingredients_from", { place: place(consume) })}</p> : <p className="text-sm text-amber-900">{t("no_stock_place")}</p>}
            <RecipeTable lines={recipe.lines} locale={locale} canTransfer={can(me, "transfers.request") && consume !== null} onTransfer={setTransfer} />
            {transferred ? (
              <p role="status" className="rounded-md bg-green-50 px-4 py-3 text-base text-green-800">
                {t("transfer_sent")}
              </p>
            ) : null}
            {output ? <p className="text-sm text-neutral-700">{t("goes_to", { place: place(output) })}</p> : null}
            {recipe.steps.length > 0 ? (
              <div className="space-y-2">
                <h3 className="text-lg font-semibold text-neutral-900">{t("steps")}</h3>
                <ol className="list-decimal space-y-1 pl-6 text-base text-neutral-900">
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
          <p className="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-base text-amber-900">{t("no_recipe")}</p>
        )
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
          className="space-y-3 rounded-md bg-neutral-50 p-3"
        >
          <p className="text-base text-neutral-700">{t("done_hint", { unit })}</p>
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label={t("produced")} error={invalid === "produced" ? t("need_qty") : errorText.field(error, "produced_qty")}>
              {(props) => <TextInput {...props} inputMode="decimal" value={produced} onChange={(event) => setProduced(event.target.value)} />}
            </Field>
            <Field label={t("waste")} error={invalid === "waste" ? t("need_qty") : errorText.field(error, "waste_qty")}>
              {(props) => <TextInput {...props} inputMode="decimal" value={waste} onChange={(event) => setWaste(event.target.value)} />}
            </Field>
          </div>
          {invalid === "empty" ? <p className="text-sm font-medium text-red-700">{t("need_something")}</p> : null}
          <Button type="submit" disabled={busy !== null}>
            {busy === "done" ? t("working") : t("done")}
          </Button>
        </form>
      ) : null}

      {status === "in_progress" && !canFinish && request.assigned_to ? <p className="text-base text-neutral-700">{t("being_made", { name: request.assigned_to.name })}</p> : null}
      {status === "pending_confirmation" && !signOff ? <p className="text-base text-neutral-700">{t("waiting_overseer")}</p> : null}

      {form === "send_back" ? (
        <div className="space-y-3 rounded-md bg-neutral-50 p-3">
          <Field label={t("send_back_note")} error={noteMissing ? t("send_back_need_note") : errorText.field(error, "note")}>
            {(props) => <TextInput {...props} value={note} maxLength={255} onChange={(event) => setNote(event.target.value)} />}
          </Field>
          <div className="flex flex-wrap gap-3">
            <Button type="button" disabled={busy !== null} onClick={sendBack}>
              {busy === "send_back" ? t("working") : t("send_back_submit")}
            </Button>
            <Button type="button" variant="secondary" onClick={() => setForm(null)}>
              {t("keep")}
            </Button>
          </div>
        </div>
      ) : null}

      {form === "cancel" ? (
        <div className="space-y-3 rounded-md bg-neutral-50 p-3">
          <Field label={t("cancel_reason")} optionalLabel={t("optional")}>
            {(props) => <TextInput {...props} value={reason} maxLength={255} onChange={(event) => setReason(event.target.value)} />}
          </Field>
          <div className="flex flex-wrap gap-3">
            <Button type="button" disabled={busy !== null} onClick={() => void run("cancel", reason.trim(), (key) => cancelRequest(request.id, reason.trim(), key))}>
              {busy === "cancel" ? t("working") : t("cancel_submit")}
            </Button>
            <Button type="button" variant="secondary" onClick={() => setForm(null)}>
              {t("keep")}
            </Button>
          </div>
        </div>
      ) : null}

      <div className="flex flex-wrap gap-3">
        {canAccept ? (
          <Button type="button" disabled={busy !== null} onClick={() => void run("accept", null, (key) => acceptRequest(request.id, key))}>
            {busy === "accept" ? t("working") : t("accept")}
          </Button>
        ) : null}
        {canStart ? (
          <Button type="button" disabled={busy !== null} onClick={() => void run("start", batchUuid.current, (key) => startBatch(request.id, batchUuid.current, key))}>
            {busy === "start" ? t("working") : t("start")}
          </Button>
        ) : null}
        {signOff && batch ? (
          <>
            <Button type="button" disabled={busy !== null} onClick={() => void run("confirm", null, (key) => confirmBatch(batch.id, key))}>
              {busy === "confirm" ? t("working") : t("confirm")}
            </Button>
            <Button type="button" variant="secondary" disabled={busy !== null} onClick={() => setForm(form === "send_back" ? null : "send_back")}>
              {t("send_back")}
            </Button>
          </>
        ) : null}
        {cancellable ? (
          <Button type="button" variant="secondary" disabled={busy !== null} onClick={() => setForm(form === "cancel" ? null : "cancel")}>
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
