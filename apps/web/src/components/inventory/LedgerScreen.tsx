"use client";

import { useTranslations } from "next-intl";
import { useState } from "react";
import { Field, PageHeader, Panel, Select, TextInput } from "@/components/ui";
import { useItems } from "@/lib/api/hooks";
import { useLedger, useLocations, type LedgerFilters, type Movement } from "@/lib/api/inventory";
import { trimDecimal } from "@/lib/decimal";
import { Pager, QueryBox, stamp, TD, TH, useNamer } from "./shared";

// Admin > Inventory > Ledger (the endpoint exists since Phase 0): every movement, newest first, filtered by item, place and
// date range by the API and by reason on the page (the API has no reason filter), 50 to a page. The ledger is append-only;
// a correction is a new row, so there is nothing to edit here.

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

export function LedgerScreen() {
  const t = useTranslations("inventory");
  const name = useNamer();
  const items = useItems(null);
  const locations = useLocations();
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
      <PageHeader title={t("ledger.title")} intro={t("ledger.intro")} />
      <Panel title={t("ledger.movements")}>
        <div className="mb-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
          <Field label={t("ledger.item")}>
            {(props) => (
              <Select {...props} value={filters.item ?? ""} onChange={(event) => change({ item: idParam(event.target.value) })}>
                <option value="">{t("ledger.all_items")}</option>
                {(items.data ?? []).map((item) => (
                  <option key={item.id} value={item.id}>
                    {name(item)}
                  </option>
                ))}
              </Select>
            )}
          </Field>
          <Field label={t("ledger.place")}>
            {(props) => (
              <Select {...props} value={filters.location ?? ""} onChange={(event) => change({ location: idParam(event.target.value) })}>
                <option value="">{t("ledger.all_places")}</option>
                {(locations.data ?? []).map((place) => (
                  <option key={place.id} value={place.id}>
                    {name(place)}
                  </option>
                ))}
              </Select>
            )}
          </Field>
          <Field label={t("ledger.reason")} hint={t("ledger.reason_hint")}>
            {(props) => (
              <Select {...props} value={reason} onChange={(event) => setReason(event.target.value)}>
                <option value="">{t("ledger.all_reasons")}</option>
                {REASONS.map((value) => (
                  <option key={value} value={value}>
                    {t(`reason.${value}`)}
                  </option>
                ))}
              </Select>
            )}
          </Field>
          <Field label={t("ledger.from")}>
            {(props) => <TextInput {...props} type="date" value={filters.from} onChange={(event) => change({ from: event.target.value })} />}
          </Field>
          <Field label={t("ledger.to")}>
            {(props) => <TextInput {...props} type="date" value={filters.to} min={filters.from || undefined} onChange={(event) => change({ to: event.target.value })} />}
          </Field>
        </div>
        <QueryBox query={ledger}>
          {(page) => {
            const rows = reason ? page.data.filter((m) => m.reason === reason) : page.data;
            return (
              <>
                <div className="overflow-x-auto">
                  <table className="w-full border-collapse text-left text-base">
                    <caption className="sr-only">{t("ledger.movements")}</caption>
                    <thead>
                      <tr className="border-b border-neutral-200 text-sm text-neutral-600">
                        <th scope="col" className={TH}>{t("ledger.col_when")}</th>
                        <th scope="col" className={TH}>{t("ledger.col_item")}</th>
                        <th scope="col" className={TH}>{t("ledger.col_place")}</th>
                        <th scope="col" className={TH}>{t("ledger.col_reason")}</th>
                        <th scope="col" className={TH}>{t("ledger.col_on_hand")}</th>
                        <th scope="col" className="py-2 font-medium">{t("ledger.col_reserved")}</th>
                      </tr>
                    </thead>
                    <tbody>
                      {rows.length === 0 ? (
                        <tr>
                          <td colSpan={6} className="py-6 text-neutral-600">{t("ledger.none")}</td>
                        </tr>
                      ) : (
                        rows.map((m) => (
                          <tr key={m.id} className="border-b border-neutral-100 last:border-0">
                            <td className={`${TD} whitespace-nowrap`}>{stamp(m.created_at)}</td>
                            <th scope="row" className={`${TD} font-medium text-neutral-900`}>{itemName(m.item_id)}</th>
                            <td className={TD}>{placeOf(m)}</td>
                            <td className={TD}>{(REASONS as readonly string[]).includes(m.reason) ? t(`reason.${m.reason}`) : m.reason}</td>
                            <td className={`${TD} font-mono`}>{signed(m.on_hand_delta)}</td>
                            <td className="py-3 font-mono">{signed(m.reserved_delta)}</td>
                          </tr>
                        ))
                      )}
                    </tbody>
                  </table>
                </div>
                <Pager page={page.meta.current_page} lastPage={page.meta.last_page} onPage={(next) => change({ page: next })} />
              </>
            );
          }}
        </QueryBox>
      </Panel>
    </>
  );
}
