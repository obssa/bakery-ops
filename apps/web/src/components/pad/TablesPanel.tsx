"use client";

import { useQuery } from "@tanstack/react-query";
import { X } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import type { Collection, DiningTable, Locale, OrderType, TableState } from "@/lib/api/types";
import { authed } from "@/lib/session";
import { useErrorText } from "@/lib/errors";
import { Alert, Drawer, IconButton, SegmentedControl } from "../ui";
import { focusRing } from "../styles";

// The table of an order (fresh-counter.md 3.2, step 6.3a). The order panel's head says it, as the design board has it: "New order" in
// caps, the table (22 / 600) with its other-language name beside it on the English page, and "Change". "Change", or the top bar's
// "Tables" on the pad, opens the "Tables" sheet: dine-in or takeaway, and for dine-in the table grid from GET /dining-tables with each
// table's state, in a word as well as a colour (colour is never the only signal). Choosing a table or takeaway closes it. An order the
// API already holds keeps its type and table, so its head has no "Change".

// Status colours keep their meaning (fresh-counter.md 1.3): blue in progress, amber waiting, brand teal served, neutral free or the bill.
const STATE_STYLE: Record<TableState, string> = {
  free: "border-neutral-300 bg-white text-neutral-900",
  ordering: "border-blue-200 bg-blue-50 text-blue-800",
  waiting: "border-amber-200 bg-amber-50 text-amber-900",
  served: "border-brand-100 bg-brand-50 text-brand-800",
  bill: "border-neutral-300 bg-neutral-100 text-neutral-900",
};

const SELECTED = "border-neutral-900 bg-neutral-900 text-white";

export function useTables() {
  return useQuery({
    queryKey: ["dining-tables"],
    queryFn: async () => (await authed<Collection<DiningTable>>("/dining-tables")).data.filter((table) => table.is_active),
    refetchInterval: 5000,
  });
}

/** The head of the order panel: what the order is for, and "Change" (it opens the "Tables" sheet). */
export function OrderHead({
  type,
  tableId,
  locked,
  sent,
  onChange,
}: {
  type: OrderType;
  tableId: number | null;
  /** an order the API already holds keeps its type and table */
  locked: boolean;
  /** the cart is an order the API already holds (a refused send being put right), not a new one */
  sent: boolean;
  onChange: () => void;
}) {
  const t = useTranslations("pad");
  const locale = useLocale() as Locale;
  const tables = useTables();
  const table = tableId === null ? undefined : tables.data?.find((row) => row.id === tableId);
  const needsTable = type === "dine_in" && tableId === null;
  const title =
    type === "takeaway" ? t("type_takeaway") : table ? t("table_title", { code: table.code }) : tableId !== null ? t("table_title", { code: "…" }) : t("no_table");
  // the board's "Table 7 · ጠረጴዛ 7": on the English page the table's Amharic name beside it, in its own language
  const twin = locale === "en" && type === "dine_in" && table ? table.name_am : null;

  return (
    <div className="flex items-center justify-between gap-3">
      <div className="flex min-w-0 flex-col gap-0.5">
        <span className="text-[13px] font-semibold uppercase tracking-[0.08em] text-neutral-600">{sent ? t("order_unsent") : t("tab_new_order")}</span>
        <span className={`truncate text-[22px] font-semibold leading-tight ${needsTable ? "text-neutral-600" : "text-neutral-900"}`}>
          {title}
          {twin ? (
            <span lang="am" className="text-base font-medium text-neutral-600">
              {" "}
              <span aria-hidden="true">· </span>
              {twin}
            </span>
          ) : null}
        </span>
      </div>
      {locked ? null : (
        <button
          type="button"
          aria-label={t("change_table_aria")}
          aria-haspopup="dialog"
          onClick={onChange}
          className={`inline-flex h-11 shrink-0 items-center rounded-xl border border-neutral-300 bg-white px-3.5 text-sm font-semibold text-neutral-900 hover:bg-neutral-100 ${focusRing}`}
        >
          {t("change_table")}
        </button>
      )}
    </div>
  );
}

/** The "Tables" sheet: dine-in or takeaway, and the table grid. */
export function TablesSheet({
  type,
  tableId,
  onType,
  onTable,
  onClose,
}: {
  type: OrderType;
  tableId: number | null;
  onType: (type: OrderType) => void;
  onTable: (id: number) => void;
  onClose: () => void;
}) {
  const t = useTranslations("pad");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const tables = useTables();

  return (
    <Drawer label={t("tables")} onClose={onClose}>
      <div className="flex items-center justify-between gap-3">
        <h2 className="text-xl font-semibold text-neutral-900">{t("tables_title")}</h2>
        <IconButton label={t("close")} size="md" onClick={onClose}>
          <X className="size-5" strokeWidth={2} aria-hidden="true" />
        </IconButton>
      </div>
      <SegmentedControl
        label={t("type_label")}
        tone="station"
        value={type}
        options={[
          { value: "dine_in", label: t("type_dine_in") },
          { value: "takeaway", label: t("type_takeaway") },
        ]}
        onChange={(value) => {
          onType(value);
          // a takeaway needs no table: the sheet has nothing more to ask
          if (value === "takeaway") onClose();
        }}
        className="w-full [&>button]:flex-1"
      />
      {type === "takeaway" ? (
        <p className="text-[15px] text-neutral-600">{t("takeaway_note")}</p>
      ) : tables.isError ? (
        <Alert>{errorText.message(tables.error)}</Alert>
      ) : tables.data && tables.data.length === 0 ? (
        <p className="text-[15px] text-neutral-600">{t("no_tables")}</p>
      ) : (
        <ul className="grid grid-cols-3 gap-3 sm:grid-cols-4">
          {(tables.data ?? []).map((row) => {
            const selected = row.id === tableId;
            const state = t(`state_${row.state}`);
            return (
              <li key={row.id}>
                <button
                  type="button"
                  aria-pressed={selected}
                  aria-label={t("table_button", { code: row.code, state })}
                  onClick={() => {
                    onTable(row.id);
                    onClose();
                  }}
                  className={`flex min-h-18 w-full flex-col items-center justify-center rounded-2xl border px-1 py-2 text-center motion-safe:transition-colors motion-safe:duration-150 ${
                    selected ? SELECTED : `${STATE_STYLE[row.state]} hover:border-neutral-500`
                  } ${focusRing}`}
                >
                  <span className="max-w-full truncate text-lg font-semibold leading-tight">{row.code}</span>
                  <span className="max-w-full truncate text-[13px]" title={locale === "am" ? row.name_am : row.name_en}>
                    {state}
                  </span>
                </button>
              </li>
            );
          })}
        </ul>
      )}
    </Drawer>
  );
}
