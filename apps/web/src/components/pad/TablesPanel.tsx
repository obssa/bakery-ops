"use client";

import { useQuery } from "@tanstack/react-query";
import { useLocale, useTranslations } from "next-intl";
import type { Collection, DiningTable, Locale, OrderType, TableState } from "@/lib/api/types";
import { authed } from "@/lib/session";
import { useErrorText } from "@/lib/errors";
import { Alert } from "../ui";
import { focusRing } from "../styles";

// Dine-in or takeaway, and for dine-in the table grid from GET /dining-tables with its state colour (the word is
// always written too, colour is never the only signal). "bill" is styled but the API does not produce it in Phase 1.

const STATE_STYLE: Record<TableState, string> = {
  free: "border-green-300 bg-green-50 text-green-900",
  ordering: "border-blue-300 bg-blue-50 text-blue-900",
  waiting: "border-amber-400 bg-amber-50 text-amber-900",
  served: "border-violet-300 bg-violet-50 text-violet-900",
  bill: "border-neutral-500 bg-neutral-200 text-neutral-900",
};

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
  onType,
  onTable,
}: {
  type: OrderType;
  tableId: number | null;
  /** an order the API already holds keeps its type and table */
  locked: boolean;
  onType: (type: OrderType) => void;
  onTable: (id: number) => void;
}) {
  const t = useTranslations("pad");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const tables = useTables();

  return (
    <section aria-label={t("tables")} className="space-y-3">
      <div role="group" aria-label={t("type_label")} className="inline-flex overflow-hidden rounded-md border border-neutral-300 bg-white">
        {(["dine_in", "takeaway"] as const).map((value) => (
          <button
            key={value}
            type="button"
            aria-pressed={type === value}
            disabled={locked}
            onClick={() => onType(value)}
            className={`min-h-12 px-5 text-base font-medium disabled:cursor-not-allowed ${
              type === value ? "bg-neutral-900 text-white" : "text-neutral-800 hover:bg-neutral-100 disabled:text-neutral-400"
            } ${focusRing}`}
          >
            {value === "dine_in" ? t("type_dine_in") : t("type_takeaway")}
          </button>
        ))}
      </div>

      {type === "takeaway" ? (
        <p className="text-base text-neutral-600">{t("takeaway_note")}</p>
      ) : tables.isError ? (
        <Alert>{errorText.message(tables.error)}</Alert>
      ) : tables.data && tables.data.length === 0 ? (
        <p className="text-base text-neutral-600">{t("no_tables")}</p>
      ) : (
        <ul className="grid grid-cols-3 gap-2 sm:grid-cols-4 lg:grid-cols-6">
          {(tables.data ?? []).map((table) => {
            const selected = table.id === tableId;
            const state = t(`state_${table.state}`);
            return (
              <li key={table.id}>
                <button
                  type="button"
                  aria-pressed={selected}
                  aria-label={t("table_button", { code: table.code, state })}
                  disabled={locked && !selected}
                  onClick={() => onTable(table.id)}
                  className={`flex min-h-16 w-full flex-col items-center justify-center rounded-lg border-2 px-2 py-1 text-center disabled:cursor-not-allowed disabled:opacity-50 ${STATE_STYLE[table.state]} ${
                    selected ? "ring-4 ring-neutral-900/70" : ""
                  } ${focusRing}`}
                >
                  <span className="text-lg font-semibold">{table.code}</span>
                  <span className="text-sm" title={locale === "am" ? table.name_am : table.name_en}>
                    {state}
                  </span>
                </button>
              </li>
            );
          })}
        </ul>
      )}
    </section>
  );
}
