"use client";

import { useQuery } from "@tanstack/react-query";
import { useLocale, useTranslations } from "next-intl";
import { useState } from "react";
import type { Collection, DiningTable, Locale, OrderType, TableState } from "@/lib/api/types";
import { authed } from "@/lib/session";
import { useErrorText } from "@/lib/errors";
import { Alert, Button, SegmentedControl } from "../ui";
import { focusRing } from "../styles";

// The head of the order panel (fresh-counter.md 3.2, step 6.3a): "New order" in caps, the table the order is for (22 / 600) or
// "Takeaway", and "Change". Under it, while the order has no table yet or the waiter asked to change it, the picker: dine-in or takeaway,
// and for dine-in the table grid from GET /dining-tables with each table's state, in a word as well as a colour (colour is never the only
// signal). Choosing a table closes the picker. An order the API already holds keeps its type and table.

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

export function TablesPanel({
  type,
  tableId,
  locked,
  sent,
  onType,
  onTable,
}: {
  type: OrderType;
  tableId: number | null;
  /** an order the API already holds keeps its type and table */
  locked: boolean;
  /** the cart is an order the API already holds (a refused send being put right), not a new one */
  sent: boolean;
  onType: (type: OrderType) => void;
  onTable: (id: number) => void;
}) {
  const t = useTranslations("pad");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const tables = useTables();
  const [changing, setChanging] = useState(false);

  const table = tableId === null ? undefined : tables.data?.find((row) => row.id === tableId);
  const needsTable = type === "dine_in" && tableId === null;
  const open = !locked && (changing || needsTable);
  const title = type === "takeaway" ? t("type_takeaway") : table ? t("table_title", { code: table.code }) : tableId !== null ? t("table_title", { code: "…" }) : t("no_table");
  const subtitle = type === "dine_in" && table ? (locale === "am" ? table.name_am : table.name_en) : null;

  return (
    <section aria-label={t("tables")} className="space-y-4">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-[13px] font-semibold uppercase tracking-[0.08em] text-neutral-600">{sent ? t("order_unsent") : t("tab_new_order")}</p>
          <p className={`truncate text-[22px] font-semibold leading-tight ${needsTable ? "text-neutral-600" : "text-neutral-900"}`}>{title}</p>
          {subtitle && subtitle !== title ? <p className="truncate text-[13px] text-neutral-600">{subtitle}</p> : null}
        </div>
        {locked || needsTable ? null : (
          <Button type="button" variant="secondary" size="md" aria-expanded={changing} aria-label={t("change_table_aria")} onClick={() => setChanging((now) => !now)}>
            {t("change_table")}
          </Button>
        )}
      </div>

      {open ? (
        <div className="space-y-3">
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
              // a takeaway needs no table: the picker has nothing more to ask
              if (value === "takeaway") setChanging(false);
              else setChanging(true);
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
            <ul className="grid grid-cols-4 gap-2">
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
                        setChanging(false);
                      }}
                      className={`flex min-h-14 w-full flex-col items-center justify-center rounded-xl border px-1 py-1 text-center motion-safe:transition-colors motion-safe:duration-150 ${
                        selected ? SELECTED : `${STATE_STYLE[row.state]} hover:border-neutral-500`
                      } ${focusRing}`}
                    >
                      <span className="max-w-full truncate text-base font-semibold leading-tight">{row.code}</span>
                      <span className="max-w-full truncate text-xs" title={locale === "am" ? row.name_am : row.name_en}>
                        {state}
                      </span>
                    </button>
                  </li>
                );
              })}
            </ul>
          )}
        </div>
      ) : null}
    </section>
  );
}
