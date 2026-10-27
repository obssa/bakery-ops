"use client";

import { useTranslations } from "next-intl";
import { useEffect, useMemo, useState } from "react";
import { Link } from "@/i18n/navigation";
import { useChain, usePlaces, useShiftList, useVerifyChain, useWorkStations } from "@/lib/api/adminShifts";
import type { ChainLink, ShiftStatus, StockPlace } from "@/lib/api/types";
import { useErrorText } from "@/lib/errors";
import { shortHash } from "@/lib/counts";
import { can, useSession } from "@/lib/session";
import { addisClock } from "@/lib/shifts";
import { verifySummary, type VerifySummary } from "@/lib/shiftsAdmin";
import { useIsoDay } from "@/lib/useDates";
import { Alert, Badge, Button, Field, Panel, Select } from "../../ui";
import { focusRing } from "../../styles";
import { Pager, QueryBox } from "../../inventory/shared";
import { Loading, ShiftStatusBadge, TD, TH, useName } from "./common";

// Admin > Shifts > History (step 3.7c): every shift of the branch, newest first, and, for the people who may replay (admin, manager), the
// locked counts of a place with the fingerprint of each link, a link to the side-by-side review, and a "Verify" button that walks the whole
// chain of the place (GET /locations/{id}/chain/verify) and shows ok or each named failure.

const STATUSES: ShiftStatus[] = ["locked", "pending_approval", "counting", "open", "opening", "scheduled", "cancelled"];

export function HistoryScreen() {
  const me = useSession((s) => s.me);
  return (
    <div className="space-y-6">
      <ChainHistory enabled={can(me, "shifts.replay")} />
      <ShiftHistory />
    </div>
  );
}

function ChainHistory({ enabled }: { enabled: boolean }) {
  const t = useTranslations("adminShifts");
  const name = useName();
  const places = usePlaces();
  const stations = useWorkStations();
  const [placeId, setPlaceId] = useState<number | null>(null);
  const counting = useMemo(() => (places.data ?? []).filter((place) => place.type !== "display_case"), [places.data]);
  useEffect(() => {
    if (placeId === null && counting.length > 0) setPlaceId(counting[0].id);
  }, [placeId, counting]);
  const chain = useChain(placeId);
  const place = counting.find((candidate) => candidate.id === placeId) ?? null;
  const stationName = (candidate: StockPlace) => {
    const station = stations.data?.find((s) => s.station_id === candidate.station_id);
    return station ? ` · ${name(station)}` : "";
  };

  if (!enabled) {
    return (
      <Panel title={t("history.chain_title")}>
        <p className="text-base text-neutral-700">{t("history.chain_forbidden")}</p>
      </Panel>
    );
  }
  return (
    <Panel title={t("history.chain_title")}>
      <div className="space-y-4">
        <p className="text-base text-neutral-600">{t("history.chain_intro")}</p>
        {places.isPending ? <Loading /> : null}
        {counting.length > 0 ? (
          <div className="max-w-md">
            <Field label={t("history.place")}>
              {(props) => (
                <Select {...props} value={placeId ?? ""} onChange={(event) => setPlaceId(Number(event.target.value))}>
                  {counting.map((candidate) => (
                    <option key={candidate.id} value={candidate.id}>
                      {name(candidate)}
                      {stationName(candidate)}
                    </option>
                  ))}
                </Select>
              )}
            </Field>
          </div>
        ) : null}
        {placeId !== null ? (
          <QueryBox query={chain}>{(page) => <ChainTable key={placeId} placeId={placeId} placeName={place ? name(place) : ""} links={page.data} head={page.head?.payload_hash ?? null} />}</QueryBox>
        ) : null}
      </div>
    </Panel>
  );
}

function ChainTable({ placeId, placeName, links, head }: { placeId: number; placeName: string; links: ChainLink[]; head: string | null }) {
  const isoDay = useIsoDay();
  const t = useTranslations("adminShifts");
  const errorText = useErrorText();
  const verify = useVerifyChain();
  const summary: VerifySummary | null = verify.data ? verifySummary(verify.data) : null;
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <Button type="button" disabled={verify.isPending} onClick={() => verify.mutate(placeId)} aria-label={t("history.verify_named", { place: placeName })}>
          {verify.isPending ? t("working") : t("history.verify")}
        </Button>
        {head ? <span className="text-sm text-neutral-600">{t("history.head", { hash: shortHash(head) })}</span> : null}
      </div>
      {verify.isError ? <Alert>{errorText.message(verify.error)}</Alert> : null}
      {summary?.ok ? (
        <p role="status" className="rounded-md bg-green-50 px-4 py-3 text-base text-green-800">
          {t("history.verify_ok", { count: summary.checked })}
        </p>
      ) : null}
      {summary && !summary.ok ? (
        <div role="alert" className="space-y-2 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-base text-red-800">
          <p className="font-semibold">{t("history.verify_failed", { count: summary.failures.length })}</p>
          <ul className="list-disc space-y-1 pl-5">
            {summary.failures.map((failure) => (
              <li key={`${failure.chain_seq}-${failure.kind}`}>{t("history.failure", { seq: failure.chain_seq, count: failure.count_id, kind: t(`history.kind.${failure.kind}`) })}</li>
            ))}
          </ul>
        </div>
      ) : null}
      {links.length === 0 ? (
        <p className="text-base text-neutral-700">{t("history.chain_empty")}</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full border-collapse text-left text-base">
            <caption className="sr-only">{t("history.chain_title")}</caption>
            <thead>
              <tr className="border-b border-neutral-200 text-sm text-neutral-600">
                <th scope="col" className={TH}>{t("history.col_link")}</th>
                <th scope="col" className={TH}>{t("history.col_count")}</th>
                <th scope="col" className={TH}>{t("history.col_shift")}</th>
                <th scope="col" className={TH}>{t("history.col_locked")}</th>
                <th scope="col" className={TH}>{t("history.col_hash")}</th>
                <th scope="col" className="py-2 font-medium">
                  <span className="sr-only">{t("history.col_review")}</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {links.map((link) => (
                <tr key={link.chain_seq} className="border-b border-neutral-100 last:border-0">
                  <td className={`${TD} font-medium`}>{link.chain_seq}</td>
                  <td className={TD}>
                    {t(link.type === "opening" ? "history.type_opening" : "history.type_closing")}
                    {link.superseded_by_id ? <Badge className="ml-2" tone="amber">{t("history.superseded")}</Badge> : null}
                  </td>
                  <td className={TD}>{isoDay(link.shift_date)}</td>
                  <td className={TD}>{addisClock(link.locked_at)}</td>
                  <td className={`${TD} font-mono text-sm`} title={link.payload_hash}>
                    {shortHash(link.payload_hash)}
                  </td>
                  <td className="py-3">
                    <Link
                      href={{ pathname: "/admin/shifts/review", query: { count: link.count_id } }}
                      aria-label={t("history.review_named", { id: link.count_id })}
                      className={`inline-flex min-h-12 items-center rounded-md border border-neutral-300 bg-white px-4 text-base font-medium text-neutral-900 hover:bg-neutral-100 ${focusRing}`}
                    >
                      {t("history.review")}
                    </Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}

function ShiftHistory() {
  const isoDay = useIsoDay();
  const t = useTranslations("adminShifts");
  const name = useName();
  const stations = useWorkStations();
  const [stationId, setStationId] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const list = useShiftList({ ...(stationId ? { station_id: Number(stationId) } : {}), ...(status ? { status } : {}), page, per_page: 15 });
  return (
    <Panel title={t("history.shifts_title")}>
      <div className="space-y-4">
        <div className="flex flex-wrap items-end gap-3">
          <div className="w-56">
            <Field label={t("history.filter_station")}>
              {(props) => (
                <Select {...props} value={stationId} onChange={(event) => { setStationId(event.target.value); setPage(1); }}>
                  <option value="">{t("history.all_stations")}</option>
                  {(stations.data ?? []).filter((station) => station.tracks_shifts).map((station) => (
                    <option key={station.station_id} value={station.station_id}>{name(station)}</option>
                  ))}
                </Select>
              )}
            </Field>
          </div>
          <div className="w-56">
            <Field label={t("history.filter_status")}>
              {(props) => (
                <Select {...props} value={status} onChange={(event) => { setStatus(event.target.value); setPage(1); }}>
                  <option value="">{t("history.all_statuses")}</option>
                  {STATUSES.map((value) => (
                    <option key={value} value={value}>{t(`status.${value}`)}</option>
                  ))}
                </Select>
              )}
            </Field>
          </div>
        </div>
        <QueryBox query={list}>
          {(result) => (
            <>
              {result.data.length === 0 ? (
                <p className="text-base text-neutral-700">{t("history.shifts_none")}</p>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full border-collapse text-left text-base">
                    <caption className="sr-only">{t("history.shifts_title")}</caption>
                    <thead>
                      <tr className="border-b border-neutral-200 text-sm text-neutral-600">
                        <th scope="col" className={TH}>{t("history.col_date")}</th>
                        <th scope="col" className={TH}>{t("history.col_station")}</th>
                        <th scope="col" className={TH}>{t("history.col_template")}</th>
                        <th scope="col" className={TH}>{t("history.col_status")}</th>
                        <th scope="col" className={TH}>{t("history.col_opened")}</th>
                        <th scope="col" className={TH}>{t("history.col_locked")}</th>
                      </tr>
                    </thead>
                    <tbody>
                      {result.data.map((shift) => (
                        <tr key={shift.id} className="border-b border-neutral-100 last:border-0">
                          <td className={`${TD} font-medium`}>{isoDay(shift.date)}</td>
                          <td className={TD}>{name(shift.station)}</td>
                          <td className={TD}>{shift.template ? name(shift.template) : t("running.ad_hoc")}</td>
                          <td className={TD}><ShiftStatusBadge status={shift.status} /></td>
                          <td className={TD}>{addisClock(shift.opened_at ?? shift.started_at) || "—"}</td>
                          <td className={TD}>{addisClock(shift.locked_at) || "—"}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
              <Pager page={result.meta.current_page} lastPage={result.meta.last_page} onPage={setPage} />
            </>
          )}
        </QueryBox>
      </div>
    </Panel>
  );
}
