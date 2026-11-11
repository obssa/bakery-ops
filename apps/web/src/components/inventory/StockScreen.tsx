"use client";

import { useTranslations } from "next-intl";
import { useSearchParams } from "next/navigation";
import { useState } from "react";
import { EmptyRow, Notice, SearchBox, TableCard, Toolbar, ToolbarSelect } from "@/components/admin/kit";
import { Button, Chip, PageHeader } from "@/components/ui";
import { useLocations, useStock, useStockAlerts, type StockAlert } from "@/lib/api/inventory";
import { InventoryNav, QueryBox, qty, useStamp, useNamer } from "./shared";

// Admin > Inventory > Stock (Phase 2, step 2.8b): the balances of one place (on hand, reserved, available, in the base
// unit), a filter by item, the low and out flags from the active alerts, and those alerts. A stock alert or transfer
// notification lands here with ?item=&location= (lib/deeplink.ts): the place is chosen and the one item shown.
//
// Step 6.4c (fresh-counter.md 4.4, the CatalogList board): the title row with its Amharic twin, the section's tabs, the toolbar (the place,
// its label in the box, and the search, still "Location" and "Find an item"), the balances a table card (the quantities right-aligned in
// tabular figures, Low amber and Out red as chips) and the active alerts a second one.

function numberParam(raw: string | null): number | null {
  return raw !== null && /^\d+$/.test(raw) ? Number(raw) : null;
}

function StateChip({ state }: { state: StockAlert["type"] | null }) {
  const t = useTranslations("inventory");
  if (state === "out")
    return (
      <Chip tone="red" dot>
        {t("state.out")}
      </Chip>
    );
  if (state === "low")
    return (
      <Chip tone="amber" dot>
        {t("state.low")}
      </Chip>
    );
  return <span className="text-[13px] text-neutral-600">{t("state.ok")}</span>;
}

export function StockScreen({ titleAm }: { titleAm?: string }) {
  const stamp = useStamp();
  const t = useTranslations("inventory");
  const params = useSearchParams();
  const [location, setLocation] = useState<number | null>(numberParam(params.get("location")));
  const [onlyItem, setOnlyItem] = useState<number | null>(numberParam(params.get("item")));
  const [search, setSearch] = useState("");
  const name = useNamer();
  const locations = useLocations();
  const alerts = useStockAlerts();

  // until a place is picked the store is shown (else the first place): the stock endpoint answers one place at a time
  const places = locations.data ?? [];
  const chosen = location ?? (places.find((p) => p.type === "store") ?? places[0])?.id ?? null;
  const stock = useStock(chosen);

  const activeAlerts = alerts.data ?? [];
  function flag(itemId: number, locationId: number): StockAlert["type"] | null {
    const mine = activeAlerts.filter((a) => a.item?.id === itemId && (a.location === null || a.location.id === locationId));
    if (mine.some((a) => a.type === "out")) return "out";
    return mine.length > 0 ? "low" : null;
  }

  return (
    <>
      <PageHeader title={t("stock.title")} intro={t("stock.intro")} twin={titleAm ? { text: titleAm, lang: "am" } : undefined} />
      <div className="flex flex-col gap-6">
        <InventoryNav />
        <Toolbar>
          <ToolbarSelect label={t("stock.location")} showLabel value={chosen ?? ""} onChange={(event) => setLocation(numberParam(event.target.value))}>
            {places.map((place) => (
              <option key={place.id} value={place.id}>
                {name(place)}
              </option>
            ))}
          </ToolbarSelect>
          <SearchBox label={t("stock.find_item")} placeholder={t("stock.find_item")} value={search} onChange={setSearch} clearLabel={t("stock.clear_search")} />
        </Toolbar>
        {onlyItem !== null ? (
          <Notice tone="blue">
            <span className="flex flex-wrap items-center justify-between gap-3">
              {t("stock.one_item")}
              <Button variant="secondary" size="md" onClick={() => setOnlyItem(null)}>
                {t("stock.show_all")}
              </Button>
            </span>
          </Notice>
        ) : null}
        {chosen === null ? (
          <QueryBox query={locations}>{() => <p className="text-[15px] text-neutral-600">{t("stock.no_places")}</p>}</QueryBox>
        ) : (
          <QueryBox query={stock}>
            {(lines) => {
              const needle = search.trim().toLowerCase();
              const shown = lines
                .filter((line) => onlyItem === null || line.item_id === onlyItem)
                .filter((line) => !needle || `${line.name_en} ${line.name_am}`.toLowerCase().includes(needle))
                .sort((a, b) => name(a).localeCompare(name(b)));
              return (
                <TableCard caption={t("stock.table")} title={t("stock.at_place")} minWidth="min-w-[640px]">
                  <thead>
                    <tr>
                      <th scope="col">{t("stock.col_item")}</th>
                      <th scope="col" className="text-right">
                        {t("stock.col_on_hand")}
                      </th>
                      <th scope="col" className="text-right">
                        {t("stock.col_reserved")}
                      </th>
                      <th scope="col" className="text-right">
                        {t("stock.col_available")}
                      </th>
                      <th scope="col">{t("stock.col_state")}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {shown.length === 0 ? (
                      <EmptyRow span={5}>{t("stock.none")}</EmptyRow>
                    ) : (
                      shown.map((line) => (
                        <tr key={line.item_id}>
                          <th scope="row" className="font-semibold text-neutral-900">
                            {name(line)}
                          </th>
                          <td className="whitespace-nowrap text-right tabular-nums">{qty(line.on_hand, line.base_unit_code)}</td>
                          <td className="whitespace-nowrap text-right tabular-nums text-neutral-600">{qty(line.reserved, line.base_unit_code)}</td>
                          <td className="whitespace-nowrap text-right font-semibold tabular-nums">{qty(line.available, line.base_unit_code)}</td>
                          <td>
                            <StateChip state={flag(line.item_id, line.location_id)} />
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </TableCard>
              );
            }}
          </QueryBox>
        )}

        <QueryBox query={alerts}>
          {(rows) => (
            <TableCard caption={t("alerts.title")} title={t("alerts.title")} minWidth="min-w-[720px]">
              <thead>
                <tr>
                  <th scope="col">{t("stock.col_item")}</th>
                  <th scope="col">{t("alerts.col_place")}</th>
                  <th scope="col">{t("alerts.col_type")}</th>
                  <th scope="col">{t("alerts.col_since")}</th>
                  <th scope="col" className="text-right">
                    {t("alerts.col_at_start")}
                  </th>
                  <th scope="col" className="text-right">
                    {t("alerts.col_threshold")}
                  </th>
                </tr>
              </thead>
              <tbody>
                {rows.length === 0 ? (
                  <EmptyRow span={6}>{t("alerts.none")}</EmptyRow>
                ) : (
                  rows.map((alert) => (
                    <tr key={alert.id}>
                      <th scope="row" className="font-semibold text-neutral-900">
                        {alert.item ? name(alert.item) : "—"}
                      </th>
                      <td className="text-neutral-700">{alert.location ? name(alert.location) : t("whole_branch")}</td>
                      <td>
                        <StateChip state={alert.type} />
                      </td>
                      <td className="whitespace-nowrap tabular-nums text-neutral-700">{stamp(alert.active_since)}</td>
                      <td className="whitespace-nowrap text-right tabular-nums">{qty(alert.available_at_start, alert.item?.unit ?? "")}</td>
                      <td className="whitespace-nowrap text-right tabular-nums">{alert.item && alert.threshold_qty !== null ? qty(alert.threshold_qty, alert.item.unit) : "—"}</td>
                    </tr>
                  ))
                )}
              </tbody>
            </TableCard>
          )}
        </QueryBox>
      </div>
    </>
  );
}
