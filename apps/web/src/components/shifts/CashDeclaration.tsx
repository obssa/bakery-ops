"use client";

import { useQueryClient } from "@tanstack/react-query";
import { useLocale, useTranslations } from "next-intl";
import { useEffect, useMemo, useRef, useState } from "react";
import { ApiError } from "@/lib/api/client";
import { putCash } from "@/lib/api/counts";
import type { CashBlock, Count, Locale, VarianceReason } from "@/lib/api/types";
import { cashBody, cashReview, channelsBody, parseTally, signedMoney, tallyKeys, tallyOf, tallyTotal, toBank, type CashParts, type ChannelFields } from "@/lib/cash";
import { compareDecimal, isQuantityText, multiplyDecimal, subtractDecimal } from "@/lib/decimal";
import { useErrorText } from "@/lib/errors";
import { Alert, Badge, Button, Field, Select, TextInput } from "../ui";
import { reasonName } from "./CountLines";
import { useKeys } from "./useKeys";

// The cash declaration of a till's drawer count (step 3.7d; phase-3 plan section 10.5). While the count is a draft the cashier types how
// many of each note and coin the drawer holds (every configured value is listed, an empty field is none); the total is worked out here
// as they type and by the server on every save. A closing also says what stays in the drawer for the next shift and declares the slips
// per channel; one reason (and a note where the reason asks for one) explains a cash variance or a channel difference. Every field is
// saved when it is left: the body always carries the whole tally and only the part that changed. All money is decimal strings.

function Figure({ label, value, currency, tone = "plain", strong = false }: { label: string; value: string; currency: string; tone?: "plain" | "bad" | "warn" | "good"; strong?: boolean }) {
  const color = tone === "bad" ? "text-red-700" : tone === "warn" ? "text-amber-800" : tone === "good" ? "text-green-800" : "text-neutral-900";
  return (
    <div className="rounded-md border border-neutral-200 bg-white px-4 py-3">
      <dt className="text-sm text-neutral-600">{label}</dt>
      <dd className={`mt-1 text-xl ${strong ? "font-semibold" : "font-medium"} ${color}`}>
        {value} <span className="text-sm font-normal text-neutral-600">{currency}</span>
      </dd>
    </div>
  );
}

export function varianceTone(value: string): "plain" | "bad" | "warn" {
  const sign = compareDecimal(value, "0", 2);
  return sign < 0 ? "bad" : sign > 0 ? "warn" : "plain";
}

/** The opening float a closing is measured from: what is expected minus the cash taken in the shift. */
export function openingFloatOf(cash: CashBlock): string | null {
  return cash.cash_sales_total === null ? null : subtractDecimal(cash.expected_total, cash.cash_sales_total, 2);
}

export function CashDeclaration({
  count,
  cash,
  reasons,
  onChange,
  onBusy,
}: {
  count: Count;
  cash: CashBlock;
  reasons: VarianceReason[];
  onChange: (count: Count) => void;
  onBusy?: (busy: boolean) => void;
}) {
  const t = useTranslations("counts");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const client = useQueryClient();
  const keys = useKeys();
  const closing = count.type === "closing";

  const serverTally = useMemo(() => tallyOf(cash.denominations), [cash.denominations]);
  const serverFloat = cash.float_left_total ?? "";
  const serverChannels = useMemo(() => {
    const fields: Record<string, ChannelFields> = {};
    for (const row of cash.channels) fields[row.code] = { receipts: row.declared_receipts === null ? "" : String(row.declared_receipts), sum: row.declared_sum ?? "" };
    return fields;
  }, [cash.channels]);

  const [tally, setTally] = useState(serverTally);
  const [floatText, setFloatText] = useState(serverFloat);
  const [channels, setChannels] = useState(serverChannels);
  const [note, setNote] = useState(cash.note ?? "");
  const [invalid, setInvalid] = useState<Record<string, boolean>>({});
  const [failure, setFailure] = useState<unknown>(null);
  const [saving, setSaving] = useState(0);
  // what the server holds replaces a field unless the cashier is in the middle of typing in the declaration
  const typing = useRef(0);
  const [focusTick, setFocusTick] = useState(0);
  const sync = useRef(0);

  const serverKey = JSON.stringify([serverTally, serverFloat, serverChannels, cash.note ?? ""]);
  useEffect(() => {
    if (typing.current > 0) return;
    setTally(serverTally);
    setFloatText(serverFloat);
    setChannels(serverChannels);
    setNote(cash.note ?? "");
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [serverKey, focusTick]);

  useEffect(() => {
    onBusy?.(saving > 0);
  }, [saving, onBusy]);

  const focus = {
    onFocus: () => {
      typing.current += 1;
    },
    onBlurCapture: () => {
      typing.current = Math.max(0, typing.current - 1);
    },
  };

  async function save(parts: CashParts, nextTally: Record<string, string> = tally) {
    const body = cashBody(nextTally, parts);
    if (body === null) return;
    const name = `cash:${count.id}:${JSON.stringify(body)}`;
    const mine = ++sync.current;
    setSaving((n) => n + 1);
    setFailure(null);
    try {
      const next = await putCash(count.id, body, keys.keyFor(name));
      keys.done(name);
      onChange(next);
    } catch (error) {
      setFailure(error);
      if (!(error instanceof ApiError) || error.status !== 0) keys.done(name);
      void client.invalidateQueries({ queryKey: ["shift-counts", count.shift_id] });
    } finally {
      setSaving((n) => n - 1);
      if (mine === sync.current) setFocusTick((n) => n + 1);
    }
  }

  const keysDesc = tallyKeys(tally);
  const typedTotal = tallyTotal(tally);
  const counted = typedTotal ?? cash.counted_total;
  const review = cashReview(count.type, cash, reasons);
  const typedVariance = subtractDecimal(counted, cash.expected_total, 2);
  const opening = openingFloatOf(cash);
  const bank = closing ? toBank(counted, cash.float_left_total) : null;
  const currency = cash.currency;
  const chosen = reasons.find((reason) => reason.id === cash.reason?.id) ?? null;
  const reasonShown = review.needsReason || cash.reason !== null;

  function commitTally(key: string, text: string) {
    const next = { ...tally, [key]: text.trim() };
    setTally(next);
    if (parseTally(text) === null) {
      setInvalid((m) => ({ ...m, [key]: true }));
      return;
    }
    setInvalid((m) => ({ ...m, [key]: false }));
    if ((serverTally[key] ?? "") === text.trim() && cash.confirmed) return;
    void save({}, next);
  }

  function commitFloat() {
    const value = floatText.trim();
    if (value === serverFloat) {
      setInvalid((m) => ({ ...m, float: false }));
      return;
    }
    if (value === "") {
      setInvalid((m) => ({ ...m, float: false }));
      void save({ float_left_total: null });
      return;
    }
    if (!isQuantityText(value, 2)) {
      setInvalid((m) => ({ ...m, float: true }));
      return;
    }
    setInvalid((m) => ({ ...m, float: false }));
    void save({ float_left_total: value });
  }

  function commitChannels(next: Record<string, ChannelFields>) {
    const { rows, incomplete } = channelsBody(next);
    setInvalid((m) => ({ ...m, channels: incomplete.length > 0 }));
    if (incomplete.length > 0) return;
    const same = JSON.stringify(rows) === JSON.stringify(channelsBody(serverChannels).rows);
    if (same) return;
    void save({ channels: rows.length === 0 ? null : rows });
  }

  const channelRows = cash.channels;
  const typedTotals = typedTotal !== null;

  return (
    <div className="space-y-6" {...focus}>
      <section aria-label={t("cash_denominations")} className="space-y-3">
        <h3 className="text-base font-semibold text-neutral-900">{t("cash_denominations")}</h3>
        <p className="text-sm text-neutral-600">{t("cash_denominations_hint")}</p>
        <div className="overflow-x-auto">
          <table className="w-full max-w-xl border-collapse text-left">
            <caption className="sr-only">{t("cash_denominations")}</caption>
            <thead>
              <tr className="border-b border-neutral-300 text-sm text-neutral-700">
                <th scope="col" className="px-3 py-2 font-medium">
                  {t("cash_col_value", { currency })}
                </th>
                <th scope="col" className="px-3 py-2 font-medium">
                  {t("cash_col_count")}
                </th>
                <th scope="col" className="px-3 py-2 text-right font-medium">
                  {t("cash_col_amount")}
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-neutral-200">
              {keysDesc.map((key) => {
                const n = parseTally(tally[key] ?? "");
                const amount = n === null ? null : multiplyDecimal(key, String(n), 2);
                return (
                  <tr key={key}>
                    <th scope="row" className="px-3 py-2 text-left text-base font-medium text-neutral-900">
                      {key}
                    </th>
                    <td className="px-3 py-2">
                      <div className="w-32">
                        <TextInput
                          inputMode="numeric"
                          aria-label={t("cash_count_of", { value: key })}
                          aria-invalid={invalid[key] || undefined}
                          placeholder="0"
                          value={tally[key] ?? ""}
                          onChange={(event) => setTally((m) => ({ ...m, [key]: event.target.value }))}
                          onBlur={(event) => commitTally(key, event.target.value)}
                          onKeyDown={(event) => {
                            if (event.key === "Enter") event.currentTarget.blur();
                          }}
                        />
                        {invalid[key] ? <p className="mt-1 text-sm font-medium text-red-700">{t("cash_count_invalid")}</p> : null}
                      </div>
                    </td>
                    <td className="px-3 py-2 text-right text-base text-neutral-800">{amount ?? "—"}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </section>

      <section aria-label={t("cash_figures")} className="space-y-3">
        <dl className="grid gap-3 sm:grid-cols-3">
          <Figure label={t("cash_counted")} value={counted} currency={currency} strong />
          <Figure label={t("cash_expected")} value={cash.expected_total} currency={currency} />
          <Figure label={t("cash_variance")} value={signedMoney(typedTotals ? typedVariance : cash.variance_total)} currency={currency} tone={varianceTone(typedTotals ? typedVariance : cash.variance_total)} strong />
        </dl>
        <p className="text-sm text-neutral-600">
          {closing
            ? t("cash_expected_closing", { opening: opening ?? "0.00", sales: cash.cash_sales_total ?? "0.00", currency })
            : cash.carried_float_total !== null
              ? t("cash_expected_opening", { carried: cash.carried_float_total, currency })
              : t("cash_expected_first")}
        </p>
        <div className="flex flex-wrap items-center gap-3">
          {!cash.confirmed ? <Badge tone="amber">{t("cash_not_declared")}</Badge> : <Badge tone="green">{t("cash_declared")}</Badge>}
          {!cash.confirmed ? (
            <Button type="button" variant="secondary" disabled={saving > 0 || typedTotal === null} onClick={() => void save({})}>
              {t("cash_confirm")}
            </Button>
          ) : null}
        </div>
      </section>

      {closing ? (
        <section aria-label={t("cash_float_title")} className="space-y-3">
          <h3 className="text-base font-semibold text-neutral-900">{t("cash_float_title")}</h3>
          <div className="flex flex-wrap items-start gap-4">
            <div className="w-64">
              <Field label={t("cash_float_left")} hint={t("cash_float_hint")} error={invalid.float ? t("cash_money_invalid") : review.floatTooLarge ? t("cash_float_too_large") : undefined}>
                {(props) => (
                  <TextInput
                    {...props}
                    inputMode="decimal"
                    placeholder="0.00"
                    value={floatText}
                    onChange={(event) => setFloatText(event.target.value)}
                    onBlur={commitFloat}
                    onKeyDown={(event) => {
                      if (event.key === "Enter") event.currentTarget.blur();
                    }}
                  />
                )}
              </Field>
            </div>
            <div className="rounded-md border border-neutral-200 bg-neutral-50 px-4 py-3">
              <p className="text-sm text-neutral-600">{t("cash_to_bank")}</p>
              <p className="mt-1 text-xl font-medium text-neutral-900">
                {bank === null ? "—" : bank} <span className="text-sm font-normal text-neutral-600">{currency}</span>
              </p>
            </div>
          </div>
        </section>
      ) : null}

      {closing ? (
        <section aria-label={t("cash_channels_title")} className="space-y-3">
          <h3 className="text-base font-semibold text-neutral-900">{t("cash_channels_title")}</h3>
          <p className="text-sm text-neutral-600">{t("cash_channels_hint")}</p>
          {channelRows.length === 0 ? (
            <p className="text-base text-neutral-600">{t("cash_channels_none")}</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[40rem] border-collapse text-left">
                <caption className="sr-only">{t("cash_channels_title")}</caption>
                <thead>
                  <tr className="border-b border-neutral-300 text-sm text-neutral-700">
                    <th scope="col" className="px-3 py-2 font-medium">
                      {t("cash_col_channel")}
                    </th>
                    <th scope="col" className="px-3 py-2 font-medium">
                      {t("cash_col_taken")}
                    </th>
                    <th scope="col" className="px-3 py-2 font-medium">
                      {t("cash_col_declared_receipts")}
                    </th>
                    <th scope="col" className="px-3 py-2 font-medium">
                      {t("cash_col_declared_sum", { currency })}
                    </th>
                    <th scope="col" className="px-3 py-2 font-medium">
                      {t("cash_col_difference")}
                    </th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-neutral-200">
                  {channelRows.map((row) => {
                    const name = locale === "am" ? row.name_am || row.name_en : row.name_en;
                    const fields = channels[row.code] ?? { receipts: "", sum: "" };
                    const set = (patch: Partial<ChannelFields>) => setChannels((m) => ({ ...m, [row.code]: { ...fields, ...patch } }));
                    const diff = row.difference_sum;
                    return (
                      <tr key={row.code}>
                        <th scope="row" className="px-3 py-3 text-left align-top text-base font-medium text-neutral-900">
                          {name}
                        </th>
                        <td className="px-3 py-3 align-top text-base">
                          {t("cash_taken", { receipts: row.expected_receipts, sum: row.expected_sum })}
                        </td>
                        <td className="px-3 py-3 align-top">
                          <div className="w-28">
                            <TextInput
                              inputMode="numeric"
                              aria-label={t("cash_declared_receipts_for", { channel: name })}
                              value={fields.receipts}
                              onChange={(event) => set({ receipts: event.target.value })}
                              onBlur={(event) => commitChannels({ ...channels, [row.code]: { ...fields, receipts: event.target.value } })}
                              onKeyDown={(event) => {
                                if (event.key === "Enter") event.currentTarget.blur();
                              }}
                            />
                          </div>
                        </td>
                        <td className="px-3 py-3 align-top">
                          <div className="w-36">
                            <TextInput
                              inputMode="decimal"
                              aria-label={t("cash_declared_sum_for", { channel: name })}
                              value={fields.sum}
                              onChange={(event) => set({ sum: event.target.value })}
                              onBlur={(event) => commitChannels({ ...channels, [row.code]: { ...fields, sum: event.target.value } })}
                              onKeyDown={(event) => {
                                if (event.key === "Enter") event.currentTarget.blur();
                              }}
                            />
                          </div>
                        </td>
                        <td className={`px-3 py-3 align-top text-base font-medium ${diff === null ? "text-neutral-500" : varianceTone(diff) === "bad" ? "text-red-700" : varianceTone(diff) === "warn" ? "text-amber-800" : "text-neutral-700"}`}>
                          {diff === null ? t("cash_not_declared_channel") : signedMoney(diff)}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
          {invalid.channels ? <p className="text-sm font-medium text-red-700">{t("cash_channels_incomplete")}</p> : null}
        </section>
      ) : null}

      {reasonShown ? (
        <section aria-label={t("cash_reason_title")} className="space-y-3 rounded-md border border-amber-300 bg-amber-50/60 px-4 py-4">
          <h3 className="text-base font-semibold text-neutral-900">{t("cash_reason_title")}</h3>
          <p className="text-sm text-neutral-700">{review.needsReason ? t("cash_reason_hint") : t("cash_reason_not_needed")}</p>
          <div className="flex flex-wrap items-start gap-4">
            <div className="w-64">
              <Field label={t("cash_reason")}>
                {(props) => (
                  <Select
                    {...props}
                    value={cash.reason?.id ?? ""}
                    onChange={(event) => void save({ reason_id: event.target.value === "" ? null : Number(event.target.value) })}
                  >
                    <option value="">{t("reason_choose")}</option>
                    {reasons.map((reason) => (
                      <option key={reason.id} value={reason.id}>
                        {reasonName(reason, locale)}
                      </option>
                    ))}
                  </Select>
                )}
              </Field>
            </div>
            {cash.reason !== null ? (
              <div className="min-w-64 flex-1">
                <Field label={t("cash_note")}>
                  {(props) => (
                    <TextInput
                      {...props}
                      maxLength={255}
                      placeholder={chosen?.requires_note ? t("note_required") : t("note_optional")}
                      value={note}
                      onChange={(event) => setNote(event.target.value)}
                      onBlur={() => {
                        if (note.trim() !== (cash.note ?? "").trim()) void save({ reason_note: note.trim() === "" ? null : note.trim() });
                      }}
                      onKeyDown={(event) => {
                        if (event.key === "Enter") event.currentTarget.blur();
                      }}
                    />
                  )}
                </Field>
              </div>
            ) : null}
          </div>
        </section>
      ) : null}

      {saving > 0 ? (
        <p role="status" className="text-sm text-neutral-600">
          {t("saving")}
        </p>
      ) : null}
      {failure ? <Alert>{errorText.message(failure)}</Alert> : null}
    </div>
  );
}
