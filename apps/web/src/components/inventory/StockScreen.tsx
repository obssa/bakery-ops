"use client";

import { useTranslations } from "next-intl";
import { useSearchParams } from "next/navigation";
import { useState } from "react";
import { Badge, Button, Field, PageHeader, Panel, Select, TextInput } from "@/components/ui";
import { useLocations, useStock, useStockAlerts, type StockAlert } from "@/lib/api/inventory";
import { QueryBox, qty, stamp, TD, TH, useNamer } from "./shared";

// Admin > Inventory > Stock (Phase 2, step 2.8b): the balances of one place (on hand, reserved, available, in the base
// unit), a filter by item, the low and out flags from the active alerts, and those alerts. A stock alert or transfer
// notification lands here with ?item=&location= (lib/deeplink.ts): the place is chosen and the one item shown.

function numberParam(raw: string | null): number | null {
  return raw !== null && /^\d+$/.test(raw) ? Number(raw) : null;
}

export function StockScreen() {
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
      <PageHeader title={t("stock.title")} intro={t("stock.intro")} />
      <div className="space-y-6">
        <Panel title={t("stock.at_place")}>
          <div className="mb-4 grid gap-4 sm:grid-cols-2">
            <Field label={t("stock.location")}>
              {(props) => (
                <Select {...props} value={chosen ?? ""} onChange={(event) => setLocation(numberParam(event.target.value))}>
                  {places.map((place) => (
                    <option key={place.id} value={place.id}>
                      {name(place)}
                    </option>
                  ))}
                </Select>
              )}
            </Field>
            <Field label={t("stock.find_item")}>
              {(props) => <TextInput {...props} type="search" value={search} onChange={(event) => setSearch(event.target.value)} />}
            </Field>
          </div>
          {onlyItem !== null ? (
            <p className="mb-4 flex flex-wrap items-center gap-3 text-base text-neutral-700">
              {t("stock.one_item")}
              <Button variant="secondary" onClick={() => setOnlyItem(null)}>
                {t("stock.show_all")}
              </Button>
            </p>
          ) : null}
          {chosen === null ? (
            <QueryBox query={locations}>{() => <p className="py-6 text-neutral-600">{t("stock.no_places")}</p>}</QueryBox>
          ) : (
            <QueryBox query={stock}>
              {(lines) => {
                const needle = search.trim().toLowerCase();
                const shown = lines
                  .filter((line) => onlyItem === null || line.item_id === onlyItem)
                  .filter((line) => !needle || `${line.name_en} ${line.name_am}`.toLowerCase().includes(needle))
                  .sort((a, b) => name(a).localeCompare(name(b)));
                return (
                  <div className="overflow-x-auto">
                    <table className="w-full border-collapse text-left text-base">
                      <caption className="sr-only">{t("stock.table")}</caption>
                      <thead>
                        <tr className="border-b border-neutral-200 text-sm text-neutral-600">
                          <th scope="col" className={TH}>{t("stock.col_item")}</th>
                          <th scope="col" className={TH}>{t("stock.col_on_hand")}</th>
                          <th scope="col" className={TH}>{t("stock.col_reserved")}</th>
                          <th scope="col" className={TH}>{t("stock.col_available")}</th>
                          <th scope="col" className="py-2 font-medium">{t("stock.col_state")}</th>
                        </tr>
                      </thead>
                      <tbody>
                        {shown.length === 0 ? (
                          <tr>
                            <td colSpan={5} className="py-6 text-neutral-600">{t("stock.none")}</td>
                          </tr>
                        ) : (
                          shown.map((line) => {
                            const state = flag(line.item_id, line.location_id);
                            return (
                              <tr key={line.item_id} className="border-b border-neutral-100 last:border-0">
                                <th scope="row" className={`${TD} font-medium text-neutral-900`}>{name(line)}</th>
                                <td className={TD}>{qty(line.on_hand, line.base_unit_code)}</td>
                                <td className={TD}>{qty(line.reserved, line.base_unit_code)}</td>
                                <td className={TD}>{qty(line.available, line.base_unit_code)}</td>
                                <td className="py-3">
                                  {state === "out" ? <Badge tone="red">{t("state.out")}</Badge> : state === "low" ? <Badge tone="amber">{t("state.low")}</Badge> : <span className="text-neutral-600">{t("state.ok")}</span>}
                                </td>
                              </tr>
                            );
                          })
                        )}
                      </tbody>
                    </table>
                  </div>
                );
              }}
            </QueryBox>
          )}
        </Panel>

        <Panel title={t("alerts.title")}>
          <QueryBox query={alerts}>
            {(rows) =>
              rows.length === 0 ? (
                <p className="py-2 text-base text-neutral-600">{t("alerts.none")}</p>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full border-collapse text-left text-base">
                    <caption className="sr-only">{t("alerts.title")}</caption>
                    <thead>
                      <tr className="border-b border-neutral-200 text-sm text-neutral-600">
                        <th scope="col" className={TH}>{t("stock.col_item")}</th>
                        <th scope="col" className={TH}>{t("alerts.col_place")}</th>
                        <th scope="col" className={TH}>{t("alerts.col_type")}</th>
                        <th scope="col" className={TH}>{t("alerts.col_since")}</th>
                        <th scope="col" className={TH}>{t("alerts.col_at_start")}</th>
                        <th scope="col" className="py-2 font-medium">{t("alerts.col_threshold")}</th>
                      </tr>
                    </thead>
                    <tbody>
                      {rows.map((alert) => (
                        <tr key={alert.id} className="border-b border-neutral-100 last:border-0">
                          <th scope="row" className={`${TD} font-medium text-neutral-900`}>{alert.item ? name(alert.item) : "—"}</th>
                          <td className={TD}>{alert.location ? name(alert.location) : t("whole_branch")}</td>
                          <td className={TD}>{alert.type === "out" ? <Badge tone="red">{t("state.out")}</Badge> : <Badge tone="amber">{t("state.low")}</Badge>}</td>
                          <td className={TD}>{stamp(alert.active_since)}</td>
                          <td className={TD}>{alert.item ? qty(alert.available_at_start, alert.item.unit) : alert.available_at_start}</td>
                          <td className="py-3">{alert.item && alert.threshold_qty !== null ? qty(alert.threshold_qty, alert.item.unit) : "—"}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )
            }
          </QueryBox>
        </Panel>
      </div>
    </>
  );
}
