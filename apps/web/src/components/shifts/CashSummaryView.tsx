"use client";

import { useLocale, useTranslations } from "next-intl";
import type { CashBlock, Count, Locale } from "@/lib/api/types";
import { signedMoney, tallyKeys, toBank } from "@/lib/cash";
import { compareDecimal, multiplyDecimal } from "@/lib/decimal";
import { reasonName } from "./CountLines";
import { openingFloatOf, varianceTone } from "./CashDeclaration";

// The cash of a drawer count as a record (step 3.7d): what a handed-in or locked count says, side by side for the approver (expected,
// counted, variance, the float left, what goes to the bank, the channels declared against taken, the reason and the note). The figures
// are the stored ones from the submit; for a locked count they are the ones the hash covers.

const TONE = { plain: "text-neutral-900", bad: "text-red-700", warn: "text-amber-800" } as const;

export function CashSummaryView({ count, cash }: { count: Count; cash: CashBlock }) {
  const t = useTranslations("counts");
  const locale = useLocale() as Locale;
  const closing = count.type === "closing";
  const currency = cash.currency;
  const opening = openingFloatOf(cash);
  const bank = closing ? toBank(cash.counted_total, cash.float_left_total) : null;
  const held = tallyKeys(cash.denominations).filter((key) => cash.denominations[key] > 0);
  const differs = compareDecimal(cash.variance_total, "0", 2) !== 0;

  return (
    <div className="space-y-5">
      <div className="overflow-x-auto">
        <table className="w-full min-w-[36rem] border-collapse text-left">
          <caption className="sr-only">{t("cash_side_caption")}</caption>
          <thead>
            <tr className="border-b border-neutral-300 text-sm text-neutral-700">
              <th scope="col" className="px-3 py-2 font-medium">
                {t("cash_col_what")}
              </th>
              <th scope="col" className="px-3 py-2 text-right font-medium">
                {t("cash_col_amount_in", { currency })}
              </th>
            </tr>
          </thead>
          <tbody className="divide-y divide-neutral-200">
            {closing && opening !== null ? (
              <tr>
                <th scope="row" className="px-3 py-3 text-left text-base font-normal text-neutral-700">
                  {t("cash_opening_float")}
                </th>
                <td className="px-3 py-3 text-right text-base">{opening}</td>
              </tr>
            ) : null}
            {closing && cash.cash_sales_total !== null ? (
              <tr>
                <th scope="row" className="px-3 py-3 text-left text-base font-normal text-neutral-700">
                  {t("cash_sales")}
                </th>
                <td className="px-3 py-3 text-right text-base">{cash.cash_sales_total}</td>
              </tr>
            ) : null}
            {!closing && cash.carried_float_total !== null ? (
              <tr>
                <th scope="row" className="px-3 py-3 text-left text-base font-normal text-neutral-700">
                  {t("cash_carried")}
                </th>
                <td className="px-3 py-3 text-right text-base">{cash.carried_float_total}</td>
              </tr>
            ) : null}
            <tr>
              <th scope="row" className="px-3 py-3 text-left text-base font-medium text-neutral-900">
                {t("cash_expected")}
              </th>
              <td className="px-3 py-3 text-right text-base font-medium">{cash.expected_total}</td>
            </tr>
            <tr>
              <th scope="row" className="px-3 py-3 text-left text-base font-medium text-neutral-900">
                {t("cash_counted")}
              </th>
              <td className="px-3 py-3 text-right text-base font-medium">{cash.counted_total}</td>
            </tr>
            <tr>
              <th scope="row" className="px-3 py-3 text-left text-base font-medium text-neutral-900">
                {t("cash_variance")}
              </th>
              <td className={`px-3 py-3 text-right text-base font-semibold ${TONE[varianceTone(cash.variance_total)]}`}>{signedMoney(cash.variance_total)}</td>
            </tr>
            {closing ? (
              <>
                <tr>
                  <th scope="row" className="px-3 py-3 text-left text-base font-normal text-neutral-700">
                    {t("cash_float_left")}
                  </th>
                  <td className="px-3 py-3 text-right text-base">{cash.float_left_total ?? "—"}</td>
                </tr>
                <tr>
                  <th scope="row" className="px-3 py-3 text-left text-base font-normal text-neutral-700">
                    {t("cash_to_bank")}
                  </th>
                  <td className="px-3 py-3 text-right text-base">{bank ?? "—"}</td>
                </tr>
              </>
            ) : null}
          </tbody>
        </table>
      </div>

      <section aria-label={t("cash_denominations")} className="space-y-2">
        <h3 className="text-base font-semibold text-neutral-900">{t("cash_denominations")}</h3>
        {held.length === 0 ? (
          <p className="text-base text-neutral-600">{t("cash_empty_drawer")}</p>
        ) : (
          <ul className="flex flex-wrap gap-2">
            {held.map((key) => (
              <li key={key} className="rounded-md bg-neutral-100 px-3 py-2 text-base text-neutral-900">
                {t("cash_tally", { count: cash.denominations[key], value: key, amount: multiplyDecimal(key, String(cash.denominations[key]), 2) })}
              </li>
            ))}
          </ul>
        )}
      </section>

      {closing && cash.channels.length > 0 ? (
        <section aria-label={t("cash_channels_title")} className="space-y-2">
          <h3 className="text-base font-semibold text-neutral-900">{t("cash_channels_title")}</h3>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[36rem] border-collapse text-left">
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
                    {t("cash_col_declared")}
                  </th>
                  <th scope="col" className="px-3 py-2 font-medium">
                    {t("cash_col_difference")}
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-neutral-200">
                {cash.channels.map((row) => (
                  <tr key={row.code}>
                    <th scope="row" className="px-3 py-3 text-left text-base font-medium text-neutral-900">
                      {locale === "am" ? row.name_am || row.name_en : row.name_en}
                    </th>
                    <td className="px-3 py-3 text-base">{t("cash_taken", { receipts: row.expected_receipts, sum: row.expected_sum })}</td>
                    <td className="px-3 py-3 text-base">
                      {row.declared_sum === null ? <span className="text-neutral-500">{t("cash_not_declared_channel")}</span> : t("cash_taken", { receipts: row.declared_receipts ?? 0, sum: row.declared_sum })}
                    </td>
                    <td className={`px-3 py-3 text-base font-medium ${row.difference_sum === null ? "text-neutral-500" : TONE[varianceTone(row.difference_sum)]}`}>
                      {row.difference_sum === null ? "—" : signedMoney(row.difference_sum)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      ) : null}

      {cash.reason || differs ? (
        <p className="text-base text-neutral-800">
          <span className="font-semibold">{t("cash_reason")}: </span>
          {cash.reason ? reasonName(cash.reason, locale) : <span className="text-neutral-500">—</span>}
          {cash.note ? <span className="block text-sm text-neutral-700">{cash.note}</span> : null}
        </p>
      ) : null}
    </div>
  );
}
