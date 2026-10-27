"use client";

import { useLocale, useTranslations } from "next-intl";
import { useId, useRef, type ChangeEvent } from "react";
import type { Locale, PaymentChannel } from "@/lib/api/types";
import { pictureOf } from "@/lib/payDrafts";
import { CASH, pressKey, tenderedOf, type KeypadKey } from "@/lib/payment";
import { canUseChannel, changeDue, checkReference, moneyOf, restOf, rowAmount, type PayRow } from "@/lib/paySheet";
import { focusRing } from "../styles";
import { Alert, Button, Field, TextInput } from "../ui";

// One payment of the pay sheet (step 4.5b): the channel, the amount, and what the channel asks for. Cash shows the keypad for the cash
// handed over and the change; a channel with requires_reference a reference field with the channel's hint, checked against its pattern as
// it is typed (the API's ReferencePattern decides); a channel with requires_photo the camera button. The camera button is a plain file
// input (accept="image/*", capture="environment"): a phone opens its camera, a device without one opens the file chooser instead.

const KEYS: KeypadKey[] = ["7", "8", "9", "4", "5", "6", "1", "2", "3", "00", "0", "."];

const keyClass = `inline-flex min-h-14 items-center justify-center rounded-md border border-neutral-300 bg-white text-2xl font-semibold text-neutral-900 hover:bg-neutral-100 disabled:cursor-not-allowed disabled:text-neutral-300 ${focusRing}`;

/** What the row shows of a failed upload: the API's message, and whether trying the same photo again can help. */
export interface PhotoFailure {
  message: string;
  retryable: boolean;
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
  onRetryPhoto,
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
  onRetryPhoto: () => void;
}) {
  const t = useTranslations("till");
  const locale = useLocale() as Locale;
  const n = index + 1;
  const titleId = useId();
  const fileInput = useRef<HTMLInputElement>(null);
  const channel = channels.find((c) => c.code === row.channel);
  const name = (c: PaymentChannel) => (locale === "am" ? c.name_am : c.name_en);

  const amountText = row.amount.trim();
  const amountError =
    amountText !== "" && !/^\d+\.$/.test(amountText) && rowAmount(row) === null ? (moneyOf(amountText) === null ? t("amount_invalid") : t("amount_zero")) : undefined;
  const rest = restOf(balance, rows, index);
  const isCash = row.channel === CASH;
  const tendered = tenderedOf(row.tendered);
  const change = changeDue(row);
  const referenceState = channel ? checkReference(channel, row.reference) : "ok";
  const referenceHint = channel ? (locale === "am" ? channel.reference_hint_am : channel.reference_hint_en) : null;
  const photoHint = channel ? (locale === "am" ? channel.photo_hint_am : channel.photo_hint_en) : null;
  const picture = pictureOf(row.id);

  function picked(event: ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];
    // cleared, so picking the same picture again is a change too
    event.target.value = "";
    if (file) onPhoto(file);
  }

  return (
    <fieldset aria-labelledby={titleId} className="space-y-4 rounded-lg border border-neutral-200 p-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 id={titleId} className="text-base font-semibold text-neutral-900">
          {t("row_title", { n })}
        </h3>
        {onRemove ? (
          <Button type="button" variant="secondary" aria-label={t("row_remove_aria", { n })} onClick={onRemove}>
            {t("row_remove")}
          </Button>
        ) : null}
      </div>

      <div role="group" aria-label={t("method")} className="flex flex-wrap gap-2">
        {channels.map((c) => {
          const selected = row.channel === c.code;
          const usable = canUseChannel(rows, index, c);
          return (
            <button
              key={c.code}
              type="button"
              aria-pressed={selected}
              disabled={!usable}
              onClick={() => onChange({ channel: c.code })}
              className={`flex min-h-14 flex-col items-start justify-center rounded-md border px-4 text-left text-base font-medium ${
                selected ? "border-blue-700 bg-blue-700 text-white" : "border-neutral-300 bg-white text-neutral-900 hover:bg-neutral-100"
              } disabled:cursor-not-allowed disabled:border-neutral-200 disabled:bg-neutral-100 disabled:text-neutral-400 ${focusRing}`}
            >
              <span>{name(c)}</span>
              {c.requires_photo || c.requires_reference ? (
                <span aria-hidden="true" className={`text-sm font-normal ${selected ? "text-blue-100" : "text-neutral-600"}`}>
                  {[c.requires_photo ? t("needs_photo") : null, c.requires_reference ? t("needs_reference") : null].filter(Boolean).join(" · ")}
                </span>
              ) : null}
            </button>
          );
        })}
      </div>

      <div className="flex flex-wrap items-end gap-2">
        <div className="min-w-48 flex-1">
          <Field label={t("amount")} error={amountError}>
            {(props) => (
              <TextInput
                {...props}
                inputMode="decimal"
                autoComplete="off"
                value={row.amount}
                onChange={(event) => onChange({ amount: event.target.value.replace(/[^\d.]/g, "") })}
              />
            )}
          </Field>
        </div>
        <Button type="button" variant="secondary" disabled={rest === null || rest === rowAmount(row)} onClick={() => rest !== null && onChange({ amount: rest })}>
          {t("rest_of_balance")}
        </Button>
      </div>

      {isCash ? (
        <div className="space-y-2">
          <p className="flex items-baseline justify-between text-base text-neutral-700">
            <span>{t("cash_received")}</span>
            <output aria-live="polite" className="text-2xl font-semibold text-neutral-900">
              {row.tendered === "" ? "0" : row.tendered} {currency}
            </output>
          </p>
          <div role="group" aria-label={t("keypad")} className="grid grid-cols-3 gap-2">
            {KEYS.map((key) => (
              <button key={key} type="button" className={keyClass} onClick={() => onChange({ tendered: pressKey(row.tendered, key) })}>
                {key}
              </button>
            ))}
            <button type="button" aria-label={t("key_back")} className={keyClass} onClick={() => onChange({ tendered: pressKey(row.tendered, "back") })}>
              ⌫
            </button>
            <button type="button" className={`${keyClass} text-base`} onClick={() => onChange({ tendered: "" })}>
              {t("key_clear")}
            </button>
            <button type="button" className={`${keyClass} text-base`} disabled={rowAmount(row) === null} onClick={() => onChange({ tendered: rowAmount(row) ?? "" })}>
              {t("exact")}
            </button>
          </div>
          <p className={`text-xl font-semibold ${change === null ? "text-neutral-600" : "text-green-800"}`}>
            {change !== null ? t("change_due", { amount: change, currency }) : tendered !== null ? t("cash_short") : ""}
          </p>
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
          <p className="text-sm font-medium text-neutral-800">{t("photo")}</p>
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
          {row.photo.status === "uploaded" ? (
            <div className="flex flex-wrap items-center gap-4">
              {picture?.url ? (
                // a local object URL of the shrunk picture: next/image has nothing to optimise in a static export
                // eslint-disable-next-line @next/next/no-img-element
                <img src={picture.url} alt={t("photo_thumb_alt", { n })} className="h-24 w-24 rounded-md border border-neutral-200 object-cover" />
              ) : null}
              <p className="text-base font-medium text-green-800">{t("photo_attached")}</p>
              <Button type="button" variant="secondary" onClick={() => fileInput.current?.click()}>
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
              <div className="flex flex-wrap gap-2">
                <Button type="button" className="min-h-14" onClick={() => fileInput.current?.click()}>
                  {t("photo_take")}
                </Button>
                {row.photo.status === "failed" && (photoFailure?.retryable ?? true) ? (
                  <Button type="button" variant="secondary" className="min-h-14" onClick={onRetryPhoto}>
                    {t("photo_retry")}
                  </Button>
                ) : null}
              </div>
            </div>
          )}
        </div>
      ) : null}

      {error ? <Alert>{error}</Alert> : null}
    </fieldset>
  );
}
