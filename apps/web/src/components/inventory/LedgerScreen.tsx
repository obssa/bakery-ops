"use client";

import { useTranslations } from "next-intl";
import { useId, useState } from "react";
import { EmptyRow, TableCard, Toolbar, ToolbarField, ToolbarSelect } from "@/components/admin/kit";
import { Chip, PageHeader } from "@/components/ui";
import { useItems } from "@/lib/api/hooks";
import { useLedger, useLocations, type LedgerFilters, type Movement } from "@/lib/api/inventory";
import { trimDecimal } from "@/lib/decimal";
import { InventoryNav, Pager, QueryBox, stamp, useNamer } from "./shared";

// Admin > Inventory > Ledger (the endpoint exists since Phase 0): every movement, newest first, filtered by item, place and
// date range by the API and by reason on the page (the API has no reason filter), 50 to a page. The ledger is append-only;
// a correction is a new row, so there is nothing to edit here.
//
// Step 6.4c (fresh-counter.md 4.4): the title row with its Amharic twin, the section's tabs, the filters as the toolbar (the same labels),
// the movements a table card with the reason as a chip and each change signed, right-aligned in tabular figures, the pages in its foot.

export const REASONS = [
  "purchase_in",
  "transfer",
  "production_consume",
  "production_output",
  "sale",
  "waste",
  "count_adjustment",
  "return",
  "reservation",
  "reservation_release",
] as const;

function signed(value: string): string {
  const text = trimDecimal(value);
  return /^-/.test(text) || text === "0" ? text : `+${text}`;
}

function idParam(raw: string): number | null {
  return /^\d+$/.test(raw) ? Number(raw) : null;
}

export function LedgerScreen({ titleAm }: { titleAm?: string }) {
  const t = useTranslations("inventory");
  const name = useNamer();
  const items = useItems(null);
  const locations = useLocations();
  const reasonHint = useId();
  const [filters, setFilters] = useState<LedgerFilters>({ item: null, location: null, from: "", to: "", page: 1 });
  const [reason, setReason] = useState("");
  const ledger = useLedger(filters);

  const itemName = (id: number) => {
    const item = items.data?.find((i) => i.id === id);
    return item ? name(item) : `#${id}`;
  };
  const placeName = (id: number | null) => {
    if (id === null) return "—";
    const place = locations.data?.find((l) => l.id === id);
    return place ? name(place) : `#${id}`;
  };
  const placeOf = (m: Movement) => (m.location_id !== null ? placeName(m.location_id) : `${placeName(m.from_location_id)} → ${placeName(m.to_location_id)}`);
  const change = (patch: Partial<LedgerFilters>) => setFilters((current) => ({ ...current, ...patch, page: patch.page ?? 1 }));

  return (
    <>
      <PageHeader title={t("ledger.title")} intro={t("ledger.intro")} twin={titleAm ? { text: titleAm, lang: "am" } : undefined} />
      <div className="flex flex-col gap-6">
        <InventoryNav />
        <div className="space-y-2">
          <Toolbar>
            <ToolbarSelect label={t("ledger.item")} value={filters.item ?? ""} onChange={(event) => change({ item: idParam(event.target.value) })}>
              <option value="">{t("ledger.all_items")}</option>
              {(items.data ?? []).map((item) => (
                <option key={item.id} value={item.id}>
                  {name(item)}
                </option>
              ))}
            </ToolbarSelect>
            <ToolbarSelect label={t("ledger.place")} value={filters.location ?? ""} onChange={(event) => change({ location: idParam(event.target.value) })}>
              <option value="">{t("ledger.all_places")}</option>
              {(locations.data ?? []).map((place) => (
                <option key={place.id} value={place.id}>
                  {name(place)}
                </option>
              ))}
            </ToolbarSelect>
            <ToolbarSelect label={t("ledger.reason")} aria-describedby={reasonHint} value={reason} onChange={(event) => setReason(event.target.value)}>
              <option value="">{t("ledger.all_reasons")}</option>
              {REASONS.map((value) => (
                <option key={value} value={value}>
                  {t(`reason.${value}`)}
                </option>
              ))}
            </ToolbarSelect>
            <ToolbarField label={t("ledger.from")} type="date" value={filters.from} onChange={(event) => change({ from: event.target.value })} />
            <ToolbarField label={t("ledger.to")} type="date" value={filters.to} min={filters.from || undefined} onChange={(event) => change({ to: event.target.value })} />
          </Toolbar>
          <p id={reasonHint} className="text-[13px] text-neutral-600">
            {t("ledger.reason_hint")}
          </p>
        </div>
        <QueryBox query={ledger}>
          {(page) => {
            const rows = reason ? page.data.filter((m) => m.reason === reason) : page.data;
            const pager = <Pager page={page.meta.current_page} lastPage={page.meta.last_page} onPage={(next) => change({ page: next })} />;
            return (
              <TableCard caption={t("ledger.movements")} title={t("ledger.movements")} minWidth="min-w-[760px]" footer={page.meta.last_page > 1 ? pager : undefined}>
                <thead>
                  <tr>
                    <th scope="col">{t("ledger.col_when")}</th>
                    <th scope="col">{t("ledger.col_item")}</th>
                    <th scope="col">{t("ledger.col_place")}</th>
                    <th scope="col">{t("ledger.col_reason")}</th>
                    <th scope="col" className="text-right">
                      {t("ledger.col_on_hand")}
                    </th>
                    <th scope="col" className="text-right">
                      {t("ledger.col_reserved")}
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {rows.length === 0 ? (
                    <EmptyRow span={6}>{t("ledger.none")}</EmptyRow>
                  ) : (
                    rows.map((m) => (
                      <tr key={m.id}>
                        <td className="whitespace-nowrap tabular-nums text-neutral-700">{stamp(m.created_at)}</td>
                        <th scope="row" className="font-semibold text-neutral-900">
                          {itemName(m.item_id)}
                        </th>
                        <td className="text-neutral-700">{placeOf(m)}</td>
                        <td>
                          <Chip>{(REASONS as readonly string[]).includes(m.reason) ? t(`reason.${m.reason}`) : m.reason}</Chip>
                        </td>
                        <td className="whitespace-nowrap text-right font-semibold tabular-nums">{signed(m.on_hand_delta)}</td>
                        <td className="whitespace-nowrap text-right tabular-nums text-neutral-700">{signed(m.reserved_delta)}</td>
                      </tr>
                    ))
                  )}
                </tbody>
              </TableCard>
            );
          }}
        </QueryBox>
      </div>
    </>
  );
}
