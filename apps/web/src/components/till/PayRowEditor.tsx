"use client";

import { Calculator, Camera, Delete } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useId, useRef, useState, type ChangeEvent, type ReactNode } from "react";
import type { Locale, PaymentChannel } from "@/lib/api/types";
import { compareDecimal } from "@/lib/decimal";
import { pictureOf } from "@/lib/payDrafts";
import { CASH, pressKey, QUICK_CASH, tenderedOf, typedCash, type KeypadKey } from "@/lib/payment";
import { canUseChannel, changeDue, checkReference, moneyOf, restOf, rowAmount, type PayRow } from "@/lib/paySheet";
import { ICON_STROKE } from "../icons";
import { buttonClass, focusRing } from "../styles";
import { Alert, Button, Field, MoneyInput, TextInput } from "../ui";

// One payment of the pay sheet (step 4.5b; step 6.3b, the Till board of fresh-counter.md 3.4). With one payment the row is the sheet: the
// "Payment method" line with the amount beside it, the grid of 64 px channel buttons (three to a row, each with what it needs: "Photo of the
// slip", "Reference number"), then for cash the "Cash received" field and the change in an ink block, and under them the quick cash keys
// (300, 500, 1,000 and "Exact amount", phase-6 plan 10.2 Q6) with "Add a payment" for a split at the end of the line. The keypad of the
// earlier till is behind its "Keypad" button: the field takes the tablet's own number keys. With two payments or more each row is a card
// with its title, the amount and "Rest of the balance". A channel with requires_reference shows a reference field with the channel's hint,
// checked against its pattern as it is typed (the API's ReferencePattern decides); a channel with requires_photo the camera button, a plain
// file input (accept="image/*", capture="environment"). A photo taken offline, or whose upload failed on the network, is kept on the tablet
// (step 5.2b): the row says so, and the photo goes up before the payment.

const KEYS: KeypadKey[] = ["7", "8", "9", "4", "5", "6", "1", "2", "3", "00", "0", "."];

const keyClass = `inline-flex min-h-13 items-center justify-center rounded-xl border border-neutral-300 bg-white text-xl font-semibold tabular-nums text-neutral-900 hover:bg-neutral-100 disabled:cursor-not-allowed disabled:text-neutral-400 motion-safe:transition-colors motion-safe:duration-150 ${focusRing}`;

/** What the row shows of a photo it cannot use: the API's refusal of the upload, or the quota of photos waiting on the tablet. */
export interface PhotoFailure {
  message: string;
}

/** What a channel needs besides the amount, in a few words under its name (hidden from readers: the fields below say it). */
function needsOf(t: ReturnType<typeof useTranslations>, channel: PaymentChannel): string | null {
  if (channel.requires_photo && channel.requires_reference) return t("channel_needs_both");
  if (channel.requires_photo) return t("channel_needs_photo");
  if (channel.requires_reference) return t("channel_needs_reference");
  return null;
}

export function PayRowEditor({
  index,
  row,
  rows,
  channels,
  balance,
  currency,
  error,
  photoFailure,
  onChange,
  onRemove,
  onPhoto,
  extra,
}: {
  index: number;
  row: PayRow;
  rows: PayRow[];
  channels: PaymentChannel[];
  balance: string;
  currency: string;
  /** the API's refusal of this row (payments.batch.row_invalid), already in the page's language */
  error: string | null;
  photoFailure: PhotoFailure | null;
  onChange: (patch: Partial<PayRow>) => void;
  onRemove: (() => void) | null;
  onPhoto: (file: File) => void;
  /** with one payment: the sheet's "Add a payment", set at the end of the quick cash line as the board has it */
  extra?: ReactNode;
}) {
  const t = useTranslations("till");
  const locale = useLocale() as Locale;
  const n = index + 1;
  const single = rows.length === 1;
  const titleId = useId();
  const methodId = useId();
  const amountId = useId();
  const receivedId = useId();
  const fileInput = useRef<HTMLInputElement>(null);
  const [keypad, setKeypad] = useState(false);
  const channel = channels.find((c) => c.code === row.channel);
  const name = (c: PaymentChannel) => (locale === "am" ? c.name_am : c.name_en);

  const amountText = row.amount.trim();
  const amountError =
    amountText !== "" && !/^\d+\.$/.test(amountText) && rowAmount(row) === null ? (moneyOf(amountText) === null ? t("amount_invalid") : t("amount_zero")) : undefined;
  const amount = rowAmount(row);
  const rest = restOf(balance, rows, index);
  const isCash = row.channel === CASH;
  const tendered = tenderedOf(row.tendered);
  const change = changeDue(row);
  const referenceState = channel ? checkReference(channel, row.reference) : "ok";
  const referenceHint = channel ? (locale === "am" ? channel.reference_hint_am : channel.reference_hint_en) : null;
  const photoHint = channel ? (locale === "am" ? channel.photo_hint_am : channel.photo_hint_en) : null;
  const picture = pictureOf(row.id);
  const setAmount = (event: ChangeEvent<HTMLInputElement>) => onChange({ amount: event.target.value.replace(/[^\d.]/g, "") });

  function picked(event: ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];
    // cleared, so picking the same picture again is a change too
    event.target.value = "";
    if (file) onPhoto(file);
  }

  return (
    <fieldset aria-labelledby={titleId} className={`space-y-4 ${single ? "" : "rounded-2xl border border-neutral-200 bg-white p-4"}`}>
      <div className={single ? "sr-only" : "flex flex-wrap items-center justify-between gap-2"}>
        <h3 id={titleId} className="text-base font-semibold text-neutral-900">
          {t("row_title", { n })}
        </h3>
        {onRemove ? (
          <Button type="button" variant="ghost" size="md" aria-label={t("row_remove_aria", { n })} onClick={onRemove}>
            {t("row_remove")}
          </Button>
        ) : null}
      </div>

      <div className="space-y-2.5">
        <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
          <p id={methodId} className="text-[15px] font-semibold text-neutral-900">
            {t("method")}
          </p>
          {single ? (
            // one payment: its amount (the whole balance unless changed) sits on the method's line, out of the board's way
            <div className="flex items-center gap-2.5">
              <label htmlFor={amountId} className="text-sm font-semibold text-neutral-600">
                {t("amount")}
              </label>
              <MoneyInput
                id={amountId}
                currency={currency}
                controlSize="md"
                className="w-44"
                aria-invalid={amountError ? true : undefined}
                aria-describedby={amountError ? `${amountId}-error` : undefined}
                value={row.amount}
                onChange={setAmount}
              />
            </div>
          ) : null}
        </div>
        {single && amountError ? (
          <p id={`${amountId}-error`} className="text-right text-sm font-medium text-red-700">
            {amountError}
          </p>
        ) : null}
        <div role="group" aria-labelledby={methodId} className="grid grid-cols-2 gap-2.5 sm:grid-cols-3">
          {channels.map((c) => {
            const selected = row.channel === c.code;
            const usable = canUseChannel(rows, index, c);
            const needs = needsOf(t, c);
            return (
              <button
                key={c.code}
                type="button"
                aria-pressed={selected}
                disabled={!usable}
                onClick={() => onChange({ channel: c.code })}
                className={`flex min-h-16 flex-col items-center justify-center gap-0.5 rounded-[14px] px-3 py-2 text-center text-base font-semibold motion-safe:transition-colors motion-safe:duration-150 ${
                  selected ? "border-2 border-brand-600 bg-brand-50 text-brand-700" : "border border-neutral-300 bg-white text-neutral-900 hover:bg-neutral-100"
                } disabled:cursor-not-allowed disabled:border-neutral-200 disabled:bg-neutral-50 disabled:text-neutral-400 ${focusRing}`}
              >
                <span>{name(c)}</span>
                {needs ? (
                  <span aria-hidden="true" className={`text-xs font-medium ${selected ? "text-brand-700" : "text-neutral-600"}`}>
                    {needs}
                  </span>
                ) : null}
              </button>
            );
          })}
        </div>
      </div>

      {single ? null : (
        <div className="flex flex-wrap items-end gap-2">
          <div className="min-w-48 flex-1">
            <Field label={t("amount")} error={amountError}>
              {(props) => <MoneyInput {...props} currency={currency} controlSize="lg" value={row.amount} onChange={setAmount} />}
            </Field>
          </div>
          <Button type="button" variant="secondary" size="lg" disabled={rest === null || rest === amount} onClick={() => rest !== null && onChange({ amount: rest })}>
            {t("rest_of_balance")}
          </Button>
        </div>
      )}

      {isCash ? (
        <div className="space-y-3">
          <div className="grid grid-cols-2 gap-3">
            <div className="flex flex-col gap-2">
              <label htmlFor={receivedId} className="text-[15px] font-semibold text-neutral-900">
                {t("cash_received")}
              </label>
              <div className="flex min-h-15 items-center gap-2.5 rounded-[14px] border-2 border-ink-900 bg-white px-4 focus-within:ring-4 focus-within:ring-brand-600/35 focus-within:ring-offset-1">
                <span className="shrink-0 text-[15px] text-neutral-600">{currency}</span>
                <input
                  id={receivedId}
                  type="text"
                  inputMode="decimal"
                  autoComplete="off"
                  placeholder="0.00"
                  value={row.tendered}
                  onChange={(event) => onChange({ tendered: typedCash(event.target.value) })}
                  className="min-w-0 flex-1 self-stretch bg-transparent text-2xl font-semibold tabular-nums text-neutral-900 outline-none placeholder:font-normal placeholder:text-neutral-500"
                />
              </div>
            </div>
            {change !== null ? (
              // "Change due:" over the ink block with the amount, one sentence for a reader
              <p className="flex flex-col gap-2 text-[15px] font-semibold text-neutral-900">
                {t.rich("change_due", {
                  amount: change,
                  currency,
                  em: (chunks) => <span className="flex min-h-15 items-center rounded-[14px] bg-neutral-900 px-4 text-2xl font-bold tabular-nums text-white">{chunks}</span>,
                })}
              </p>
            ) : (
              <div className="flex flex-col gap-2">
                <span className="text-[15px] font-semibold text-neutral-900">{t("change_label")}</span>
                <p
                  className={`flex min-h-15 items-center rounded-[14px] px-4 text-sm font-medium ${
                    tendered !== null ? "bg-amber-50 text-amber-900" : "bg-neutral-100 text-neutral-600"
                  }`}
                >
                  {tendered !== null ? t("cash_short") : t("cash_hint")}
                </p>
              </div>
            )}
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <div role="group" aria-label={t("quick_cash")} className="flex flex-wrap gap-2">
              {QUICK_CASH.map((key) => (
                <button
                  key={key.amount}
                  type="button"
                  // a note that does not cover the payment is no quick answer
                  disabled={amount === null || compareDecimal(key.amount, amount, 2) < 0}
                  onClick={() => onChange({ tendered: key.amount })}
                  className={`${buttonClass({ variant: "secondary", size: "md" })} tabular-nums`}
                >
                  {key.label}
                </button>
              ))}
              <Button type="button" variant="secondary" size="md" disabled={amount === null} onClick={() => onChange({ tendered: amount ?? "" })}>
                {t("exact")}
              </Button>
            </div>
            <Button type="button" variant="ghost" size="md" aria-expanded={keypad} onClick={() => setKeypad((open) => !open)}>
              <Calculator className="size-5" strokeWidth={ICON_STROKE} aria-hidden="true" />
              {t("keypad")}
            </Button>
            {extra ? <div className="ml-auto">{extra}</div> : null}
          </div>
          {keypad ? (
            <div role="group" aria-label={t("keypad")} className="grid max-w-sm grid-cols-3 gap-2">
              {KEYS.map((key) => (
                <button key={key} type="button" className={keyClass} onClick={() => onChange({ tendered: pressKey(row.tendered, key) })}>
                  {key}
                </button>
              ))}
              <button type="button" aria-label={t("key_back")} className={keyClass} onClick={() => onChange({ tendered: pressKey(row.tendered, "back") })}>
                <Delete className="size-6" strokeWidth={ICON_STROKE} aria-hidden="true" />
              </button>
              <button type="button" className={`${keyClass} col-span-2 text-base`} onClick={() => onChange({ tendered: "" })}>
                {t("key_clear")}
              </button>
            </div>
          ) : null}
        </div>
      ) : null}

      {channel?.requires_reference ? (
        <Field
          label={t("reference")}
          hint={referenceHint ?? t("reference_hint_default")}
          error={referenceState === "invalid" ? t("reference_invalid", { channel: name(channel) }) : undefined}
        >
          {(props) => (
            <TextInput
              {...props}
              controlSize="lg"
              autoComplete="off"
              autoCapitalize="characters"
              spellCheck={false}
              maxLength={80}
              value={row.reference}
              onChange={(event) => onChange({ reference: event.target.value })}
            />
          )}
        </Field>
      ) : null}

      {channel?.requires_photo ? (
        <div className="space-y-2">
          <p className="text-sm font-semibold text-neutral-900">{t("photo")}</p>
          {photoHint ? <p className="text-sm text-neutral-600">{photoHint}</p> : null}
          <input
            ref={fileInput}
            type="file"
            accept="image/*"
            capture="environment"
            aria-label={t("photo_file")}
            tabIndex={-1}
            className="sr-only"
            onChange={picked}
          />
          {row.photo.status === "uploaded" || row.photo.status === "kept" ? (
            <div className="flex flex-wrap items-center gap-4">
              {picture?.url ? (
                // a local object URL of the shrunk picture: next/image has nothing to optimise in a static export
                // eslint-disable-next-line @next/next/no-img-element
                <img src={picture.url} alt={t("photo_thumb_alt", { n })} className="size-24 rounded-xl border border-neutral-200 object-cover" />
              ) : null}
              {row.photo.status === "kept" ? (
                <p className="text-base font-medium text-blue-800">{t("photo_kept")}</p>
              ) : (
                <p className="text-base font-medium text-green-800">{t("photo_attached")}</p>
              )}
              <Button type="button" variant="secondary" size="lg" onClick={() => fileInput.current?.click()}>
                {t("photo_replace")}
              </Button>
            </div>
          ) : row.photo.status === "uploading" ? (
            <p role="status" className="text-base text-neutral-700">
              {t("photo_uploading")}
            </p>
          ) : (
            <div className="space-y-2">
              {row.photo.status === "failed" ? <Alert>{photoFailure?.message ?? t("photo_failed")}</Alert> : null}
              <Button type="button" size="lg" onClick={() => fileInput.current?.click()}>
                <Camera className="size-5" strokeWidth={ICON_STROKE} aria-hidden="true" />
                {t("photo_take")}
              </Button>
            </div>
          )}
        </div>
      ) : null}

      {extra && !isCash ? <div>{extra}</div> : null}
      {error ? <Alert>{error}</Alert> : null}
    </fieldset>
  );
}

