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
import { Alert, Badge, Button, Field, Panel, Select, TextInput } from "../../ui";
import { TD, TH, ShiftStatusBadge, useName } from "./common";

// Admin > Shifts > Replay (step 3.7c): a station as it was at a moment in the past (GET /replay): the shift that was running, who was on,
// what each place held, the orders still open, and the counts with the status each had then. The time is typed in the branch's own
// clock (Africa/Addis_Ababa) and sent with its offset. Nothing is changed by looking.

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
    <div className="space-y-6">
      <Panel title={t("replay.title")}>
        <form onSubmit={onSubmit} className="space-y-4" noValidate>
          <p className="text-base text-neutral-600">{t("replay.intro")}</p>
          <div className="flex flex-wrap items-end gap-3">
            <div className="w-56">
              <Field label={t("replay.station")}>
                {(props) => (
                  <Select {...props} value={stationId} onChange={(event) => setStationId(event.target.value)}>
                    {tracked.map((station) => (
                      <option key={station.station_id} value={station.station_id}>{name(station)}</option>
                    ))}
                  </Select>
                )}
              </Field>
            </div>
            <div className="w-72">
              <Field label={t("replay.at")} hint={t("replay.at_hint")}>
                {(props) => <TextInput {...props} type="datetime-local" value={at} onChange={(event) => setAt(event.target.value)} />}
              </Field>
            </div>
            <Button type="submit" disabled={replay.isFetching}>
              {replay.isFetching ? t("working") : t("replay.show")}
            </Button>
          </div>
          {formError ? <Alert>{formError}</Alert> : null}
        </form>
      </Panel>
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
    <div className="space-y-6">
      <Panel title={t("replay.shift_title", { station: name(replay.station), time: `${addisDate(new Date(replay.at))} ${addisClock(replay.at)}` })}>
        {shift ? (
          <p className="flex flex-wrap items-center gap-3 text-base text-neutral-800">
            <ShiftStatusBadge status={shift.status} />
            <span>{t("running.line", { name: shift.template ? name(shift.template) : t("running.ad_hoc"), date: isoDay(shift.date) })}</span>
          </p>
        ) : (
          <p className="text-base text-neutral-700">{t("replay.no_shift")}</p>
        )}
        <h3 className="mt-4 text-base font-semibold text-neutral-900">{t("replay.on_duty")}</h3>
        {replay.on_duty.length === 0 ? (
          <p className="text-base text-neutral-700">{t("replay.nobody")}</p>
        ) : (
          <ul className="mt-2 flex flex-wrap gap-2">
            {replay.on_duty.map((row) => (
              <li key={row.user.id} className="rounded-full bg-neutral-100 px-3 py-1 text-base font-medium text-neutral-900">
                {row.user.name}
                {row.joined_at ? <span className="ml-2 text-sm font-normal text-neutral-600">{t("replay.joined", { time: addisClock(row.joined_at) })}</span> : null}
              </li>
            ))}
          </ul>
        )}
      </Panel>

      {replay.locations.map((place) => (
        <Panel key={place.location.id} title={t("replay.balances_title", { place: name(place.location) })}>
          {place.last_locked ? (
            <p className="mb-3 text-sm text-neutral-600">
              {t("replay.last_locked", { seq: place.last_locked.chain_seq, hash: shortHash(place.last_locked.payload_hash), time: addisClock(place.last_locked.locked_at) })}
            </p>
          ) : (
            <p className="mb-3 text-sm text-neutral-600">{t("replay.never_locked")}</p>
          )}
          {place.balances.length === 0 ? (
            <p className="text-base text-neutral-700">{t("replay.no_balances")}</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full border-collapse text-left text-base">
                <caption className="sr-only">{t("replay.balances_title", { place: name(place.location) })}</caption>
                <thead>
                  <tr className="border-b border-neutral-200 text-sm text-neutral-600">
                    <th scope="col" className={TH}>{t("replay.col_item")}</th>
                    <th scope="col" className={TH}>{t("replay.col_on_hand")}</th>
                    <th scope="col" className={TH}>{t("replay.col_reserved")}</th>
                  </tr>
                </thead>
                <tbody>
                  {place.balances.map((balance) => (
                    <tr key={balance.item.id} className="border-b border-neutral-100 last:border-0">
                      <th scope="row" className={`${TD} font-medium text-neutral-900`}>{name(balance.item)}</th>
                      <td className={TD}>{trimDecimal(balance.on_hand)} {balance.item.unit}</td>
                      <td className={TD}>{trimDecimal(balance.reserved)} {balance.item.unit}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Panel>
      ))}

      <Panel title={t("replay.orders_title")}>
        {replay.open_orders.length === 0 ? (
          <p className="text-base text-neutral-700">{t("replay.no_orders")}</p>
        ) : (
          <ul className="divide-y divide-neutral-100">
            {replay.open_orders.map((order) => (
              <li key={order.order_id} className="py-3 text-base">
                <p className="font-medium text-neutral-900">
                  {t("replay.order_line", { no: order.order_no, table: order.table_code ?? t("replay.no_table"), owner: order.owner.name })}
                </p>
                <p className="text-sm text-neutral-600">
                  {order.sent_at ? t("replay.sent", { time: addisClock(order.sent_at) }) : t("replay.not_sent")} · {t("replay.lines", { count: order.lines.length })}
                </p>
              </li>
            ))}
          </ul>
        )}
      </Panel>

      <Panel title={t("replay.counts_title")}>
        {replay.counts.length === 0 ? (
          <p className="text-base text-neutral-700">{t("replay.no_counts")}</p>
        ) : (
          <ul className="divide-y divide-neutral-100">
            {replay.counts.map((count) => (
              <li key={count.count_id} className="flex flex-wrap items-center gap-3 py-3 text-base">
                <span className="font-medium text-neutral-900">{t("replay.count_line", { id: count.count_id })}</span>
                <Badge tone="neutral">{t(count.type === "opening" ? "history.type_opening" : "history.type_closing")}</Badge>
                <Badge tone={count.status_at === "locked" ? "green" : "amber"}>{t(`count_status.${count.status_at}`)}</Badge>
              </li>
            ))}
          </ul>
        )}
      </Panel>
    </div>
  );
}
