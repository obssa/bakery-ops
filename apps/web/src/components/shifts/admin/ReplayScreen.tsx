"use client";

import { useTranslations } from "next-intl";
import { useEffect, useState, type FormEvent } from "react";
import { useReplay, useWorkStations } from "@/lib/api/adminShifts";
import type { Replay } from "@/lib/api/types";
import { trimDecimal } from "@/lib/decimal";
import { useErrorText } from "@/lib/errors";
import { shortHash } from "@/lib/counts";
import { addisClock } from "@/lib/shifts";
import { addisDate, addisLocalInput, addisToIso } from "@/lib/shiftsAdmin";
import { useIsoDay } from "@/lib/useDates";
import { EmptyRow, TableCard, Toolbar, ToolbarField, ToolbarSelect } from "../../admin/kit";
import { Alert, Avatar, Button, Chip, type Tone } from "../../ui";
import { OverviewCard } from "./Overview";
import { ShiftStatusBadge, useName } from "./common";

// Admin > Shifts > Replay (step 3.7c): a station as it was at a moment in the past (GET /replay): the shift that was running, who was on,
// what each place held, the orders still open, and the counts with the status each had then. The time is typed in the branch's own
// clock (Africa/Addis_Ababa) and sent with its offset. Nothing is changed by looking.
//
// Step 6.4d (fresh-counter.md 4.4, the boards' toolbar, cards and table): the station and the moment as the toolbar with Show; then the
// station at that moment a white card (its shift's chip, the people on duty with their initials), each place's stock a table card (the
// figures right-aligned in tabular numbers), the open orders and the counts two white cards side by side.

const COUNT_TONE: Record<string, Tone> = { locked: "brand", approved: "brand", rejected: "red", awaiting_cosign: "amber", pending_approval: "amber", draft: "blue" };

export function ReplayScreen() {
  const t = useTranslations("adminShifts");
  const name = useName();
  const errorText = useErrorText();
  const stations = useWorkStations();
  const tracked = (stations.data ?? []).filter((station) => station.tracks_shifts);
  const [stationId, setStationId] = useState("");
  const [at, setAt] = useState("");
  const [request, setRequest] = useState<{ stationId: number; at: string } | null>(null);
  const [formError, setFormError] = useState<string | null>(null);

  useEffect(() => {
    // the default is now on the branch's clock; the page is static, so it is set on the client
    setAt((current) => current || addisLocalInput(new Date()));
  }, []);
  useEffect(() => {
    if (!stationId && tracked.length > 0) setStationId(String(tracked[0].station_id));
  }, [stationId, tracked]);

  const replay = useReplay(request);

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const iso = addisToIso(at);
    if (!stationId || iso === null) {
      setFormError(t("replay.need_both"));
      return;
    }
    setFormError(null);
    setRequest({ stationId: Number(stationId), at: iso });
  }

  return (
    <div className="flex flex-col gap-6">
      <form onSubmit={onSubmit} aria-label={t("replay.title")} className="space-y-2" noValidate>
        <Toolbar className="items-start">
          <ToolbarSelect label={t("replay.station")} showLabel value={stationId} onChange={(event) => setStationId(event.target.value)} className="min-w-60">
            {tracked.map((station) => (
              <option key={station.station_id} value={station.station_id}>
                {name(station)}
              </option>
            ))}
          </ToolbarSelect>
          <ToolbarField label={t("replay.at")} type="datetime-local" value={at} onChange={(event) => setAt(event.target.value)} />
          <Button type="submit" size="md" loading={replay.isFetching}>
            {t("replay.show")}
          </Button>
        </Toolbar>
        <p className="text-[13px] text-neutral-600">
          {t("replay.intro")} {t("replay.at_hint")}
        </p>
        {formError ? <Alert>{formError}</Alert> : null}
      </form>
      {replay.isError ? <Alert>{errorText.message(replay.error)}</Alert> : null}
      {replay.data ? <ReplayView replay={replay.data} /> : null}
    </div>
  );
}

function ReplayView({ replay }: { replay: Replay }) {
  const isoDay = useIsoDay();
  const t = useTranslations("adminShifts");
  const name = useName();
  const shift = replay.shift;
  return (
    <div className="flex flex-col gap-6">
      <OverviewCard title={t("replay.shift_title", { station: name(replay.station), time: `${addisDate(new Date(replay.at))} ${addisClock(replay.at)}` })} aside={shift ? <ShiftStatusBadge status={shift.status} /> : null}>
        <p className="-mt-2 text-[15px] text-neutral-700">
          {shift ? t("running.line", { name: shift.template ? name(shift.template) : t("running.ad_hoc"), date: isoDay(shift.date) }) : t("replay.no_shift")}
        </p>
        <div className="space-y-2 border-t border-neutral-100 pt-4">
          <h3 className="text-[13px] font-semibold uppercase tracking-[0.08em] text-neutral-600">{t("replay.on_duty")}</h3>
          {replay.on_duty.length === 0 ? (
            <p className="text-[15px] text-neutral-600">{t("replay.nobody")}</p>
          ) : (
            <ul className="flex flex-wrap gap-2">
              {replay.on_duty.map((row) => (
                <li key={row.user.id} className="inline-flex items-center gap-2.5 rounded-full border border-neutral-200 py-1 pl-1 pr-3.5">
                  <Avatar name={row.user.name} size="sm" />
                  <span className="text-[15px] font-medium text-neutral-900">{row.user.name}</span>
                  {row.joined_at ? <span className="text-[13px] tabular-nums text-neutral-600">{t("replay.joined", { time: addisClock(row.joined_at) })}</span> : null}
                </li>
              ))}
            </ul>
          )}
        </div>
      </OverviewCard>

      {replay.locations.map((place) => {
        const title = t("replay.balances_title", { place: name(place.location) });
        return (
          <TableCard
            key={place.location.id}
            caption={title}
            title={title}
            intro={
              place.last_locked
                ? t("replay.last_locked", { seq: place.last_locked.chain_seq, hash: shortHash(place.last_locked.payload_hash), time: addisClock(place.last_locked.locked_at) })
                : t("replay.never_locked")
            }
            minWidth="min-w-[520px]"
          >
            <thead>
              <tr>
                <th scope="col">{t("replay.col_item")}</th>
                <th scope="col" className="text-right">
                  {t("replay.col_on_hand")}
                </th>
                <th scope="col" className="text-right">
                  {t("replay.col_reserved")}
                </th>
              </tr>
            </thead>
            <tbody>
              {place.balances.length === 0 ? (
                <EmptyRow span={3}>{t("replay.no_balances")}</EmptyRow>
              ) : (
                place.balances.map((balance) => (
                  <tr key={balance.item.id}>
                    <th scope="row" className="font-semibold text-neutral-900">
                      {name(balance.item)}
                    </th>
                    <td className="whitespace-nowrap text-right tabular-nums">
                      {trimDecimal(balance.on_hand)} {balance.item.unit}
                    </td>
                    <td className="whitespace-nowrap text-right tabular-nums text-neutral-700">
                      {trimDecimal(balance.reserved)} {balance.item.unit}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </TableCard>
        );
      })}

      <div className="grid items-start gap-4 lg:grid-cols-2">
        <OverviewCard title={t("replay.orders_title")}>
          {replay.open_orders.length === 0 ? (
            <p className="text-[15px] text-neutral-600">{t("replay.no_orders")}</p>
          ) : (
            <ul className="-mt-1.5 divide-y divide-neutral-100">
              {replay.open_orders.map((order) => (
                <li key={order.order_id} className="py-2.5">
                  <p className="text-[15px] font-semibold text-neutral-900">
                    {t("replay.order_line", { no: order.order_no, table: order.table_code ?? t("replay.no_table"), owner: order.owner.name })}
                  </p>
                  <p className="text-[13px] tabular-nums text-neutral-600">
                    {order.sent_at ? t("replay.sent", { time: addisClock(order.sent_at) }) : t("replay.not_sent")} · {t("replay.lines", { count: order.lines.length })}
                  </p>
                </li>
              ))}
            </ul>
          )}
        </OverviewCard>

        <OverviewCard title={t("replay.counts_title")}>
          {replay.counts.length === 0 ? (
            <p className="text-[15px] text-neutral-600">{t("replay.no_counts")}</p>
          ) : (
            <ul className="-mt-1.5 divide-y divide-neutral-100">
              {replay.counts.map((count) => (
                <li key={count.count_id} className="flex flex-wrap items-center justify-between gap-3 py-2.5">
                  <span className="flex flex-wrap items-center gap-2">
                    <span className="text-[15px] font-semibold text-neutral-900">{t("replay.count_line", { count: count.count_id })}</span>
                    <Chip tone="neutral">{t(count.type === "opening" ? "history.type_opening" : "history.type_closing")}</Chip>
                  </span>
                  <Chip tone={COUNT_TONE[count.status_at] ?? "neutral"} dot>
                    {t(`count_status.${count.status_at}`)}
                  </Chip>
                </li>
              ))}
            </ul>
          )}
        </OverviewCard>
      </div>
    </div>
  );
}
