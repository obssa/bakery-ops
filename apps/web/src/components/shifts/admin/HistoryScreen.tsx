"use client";

import { CircleCheck } from "lucide-react";
import { useTranslations } from "next-intl";
import { useEffect, useId, useMemo, useState, type ReactNode } from "react";
import { Link } from "@/i18n/navigation";
import { useChain, usePlaces, useShiftList, useVerifyChain, useWorkStations } from "@/lib/api/adminShifts";
import type { ChainLink, ShiftStatus, StockPlace } from "@/lib/api/types";
import { useErrorText } from "@/lib/errors";
import { shortHash } from "@/lib/counts";
import { can, useSession } from "@/lib/session";
import { addisClock } from "@/lib/shifts";
import { verifySummary, type VerifySummary } from "@/lib/shiftsAdmin";
import { useIsoDay } from "@/lib/useDates";
import { ACTIONS_CELL, CARD, EmptyRow, InnerTable, TableCard, Toolbar, ToolbarSelect } from "../../admin/kit";
import { QueryRows } from "../../admin/rows";
import { Alert, Button, Chip, Skeleton } from "../../ui";
import { buttonClass } from "../../styles";
import { Pager, QueryBox } from "../../inventory/shared";
import { ShiftStatusBadge, useName } from "./common";

// Admin > Shifts > History (step 3.7c): every shift of the branch, newest first, and, for the people who may replay (admin, manager), the
// locked counts of a place with the fingerprint of each link, a link to the side-by-side review, and a "Verify" button that walks the whole
// chain of the place (GET /locations/{id}/chain/verify) and shows ok or each named failure.
//
// Step 6.4d (fresh-counter.md 4.4, the boards' cards, toolbar and table): the chain a white card, its place and Verify as its toolbar, the
// links a table inside it; the shifts a table card with its two filters on the right of its title and the pages in its foot.

const STATUSES: ShiftStatus[] = ["locked", "pending_approval", "counting", "open", "opening", "scheduled", "cancelled"];

export function HistoryScreen() {
  const me = useSession((s) => s.me);
  return (
    <div className="flex flex-col gap-6">
      <ChainHistory enabled={can(me, "shifts.replay")} />
      <ShiftHistory />
    </div>
  );
}

function ChainHistory({ enabled }: { enabled: boolean }) {
  const t = useTranslations("adminShifts");
  const name = useName();
  const headingId = useId();
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

  return (
    <section aria-labelledby={headingId} className={`${CARD} space-y-4 p-5`}>
      <div className="space-y-1">
        <h2 id={headingId} className="text-[17px] font-semibold text-neutral-900">
          {t("history.chain_title")}
        </h2>
        <p className="text-[13px] text-neutral-600">{enabled ? t("history.chain_intro") : t("history.chain_forbidden")}</p>
      </div>
      {!enabled ? null : (
        <>
          {places.isPending ? <Skeleton className="h-11 w-72" /> : null}
          {placeId !== null ? (
            <ChainBody
              key={placeId}
              placeId={placeId}
              placeName={place ? name(place) : ""}
              head={chain.data?.head?.payload_hash ?? null}
              picker={
                <ToolbarSelect label={t("history.place")} showLabel value={placeId} onChange={(event) => setPlaceId(Number(event.target.value))} className="min-w-72">
                  {counting.map((candidate) => (
                    <option key={candidate.id} value={candidate.id}>
                      {name(candidate)}
                      {stationName(candidate)}
                    </option>
                  ))}
                </ToolbarSelect>
              }
            >
              <QueryBox query={chain}>{(page) => <ChainTable links={page.data} />}</QueryBox>
            </ChainBody>
          ) : null}
        </>
      )}
    </section>
  );
}

/** The place's toolbar (the place, Verify, the latest link) and what Verify found, over the links (`children`); one per place, so a new place starts unverified. */
function ChainBody({ placeId, placeName, head, picker, children }: { placeId: number; placeName: string; head: string | null; picker: ReactNode; children: ReactNode }) {
  const t = useTranslations("adminShifts");
  const errorText = useErrorText();
  const verify = useVerifyChain();
  const summary: VerifySummary | null = verify.data ? verifySummary(verify.data) : null;
  return (
    <div className="space-y-4">
      <Toolbar>
        {picker}
        <Button type="button" size="md" loading={verify.isPending} onClick={() => verify.mutate(placeId)} aria-label={t("history.verify_named", { place: placeName })}>
          {t("history.verify")}
        </Button>
        {head ? <span className="font-mono text-[13px] tabular-nums text-neutral-600">{t("history.head", { hash: shortHash(head) })}</span> : null}
      </Toolbar>
      {verify.isError ? <Alert>{errorText.message(verify.error)}</Alert> : null}
      {summary?.ok ? (
        <p role="status" className="flex items-start gap-3 rounded-xl bg-brand-50 px-4 py-3 text-[15px] font-medium text-brand-700">
          <CircleCheck className="mt-0.5 size-5 shrink-0" strokeWidth={2} aria-hidden="true" />
          {t("history.verify_ok", { count: summary.checked })}
        </p>
      ) : null}
      {summary && !summary.ok ? (
        <div role="alert" className="space-y-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-[15px] text-red-800">
          <p className="font-semibold">{t("history.verify_failed", { count: summary.failures.length })}</p>
          <ul className="list-disc space-y-1 pl-5">
            {summary.failures.map((failure) => (
              <li key={`${failure.chain_seq}-${failure.kind}`}>{t("history.failure", { seq: failure.chain_seq, count: failure.count_id, kind: t(`history.kind.${failure.kind}`) })}</li>
            ))}
          </ul>
        </div>
      ) : null}
      {children}
    </div>
  );
}

/** The place's locked counts, newest link first as the API lists them, each with its fingerprint and a link to its review. */
function ChainTable({ links }: { links: ChainLink[] }) {
  const isoDay = useIsoDay();
  const t = useTranslations("adminShifts");
  return (
    <>
      {links.length === 0 ? (
        <p className="text-[15px] text-neutral-600">{t("history.chain_empty")}</p>
      ) : (
        <InnerTable caption={t("history.chain_title")} minWidth="min-w-[720px]">
          <thead>
            <tr>
              <th scope="col" className="text-right">
                {t("history.col_link")}
              </th>
              <th scope="col">{t("history.col_count")}</th>
              <th scope="col">{t("history.col_shift")}</th>
              <th scope="col">{t("history.col_locked")}</th>
              <th scope="col">{t("history.col_hash")}</th>
              <th scope="col">
                <span className="sr-only">{t("history.col_review")}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            {links.map((link) => (
              <tr key={link.chain_seq}>
                <td className="text-right font-semibold tabular-nums">{link.chain_seq}</td>
                <td>
                  <span className="flex flex-wrap items-center gap-2">
                    {t(link.type === "opening" ? "history.type_opening" : "history.type_closing")}
                    {link.superseded_by_id ? <Chip tone="amber">{t("history.superseded")}</Chip> : null}
                  </span>
                </td>
                <td className="whitespace-nowrap tabular-nums">{isoDay(link.shift_date)}</td>
                <td className="tabular-nums">{addisClock(link.locked_at)}</td>
                <td className="font-mono text-sm text-neutral-700" title={link.payload_hash}>
                  {shortHash(link.payload_hash)}
                </td>
                <td className={ACTIONS_CELL}>
                  <Link
                    href={{ pathname: "/admin/shifts/review", query: { count: link.count_id } }}
                    aria-label={t("history.review_named", { id: link.count_id })}
                    className={buttonClass({ variant: "secondary", size: "md", compact: true })}
                  >
                    {t("history.review")}
                  </Link>
                </td>
              </tr>
            ))}
          </tbody>
        </InnerTable>
      )}
    </>
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
    <TableCard
      caption={t("history.shifts_title")}
      title={t("history.shifts_title")}
      minWidth="min-w-[760px]"
      action={
        <>
          <ToolbarSelect
            label={t("history.filter_station")}
            value={stationId}
            onChange={(event) => {
              setStationId(event.target.value);
              setPage(1);
            }}
          >
            <option value="">{t("history.all_stations")}</option>
            {(stations.data ?? [])
              .filter((station) => station.tracks_shifts)
              .map((station) => (
                <option key={station.station_id} value={station.station_id}>
                  {name(station)}
                </option>
              ))}
          </ToolbarSelect>
          <ToolbarSelect
            label={t("history.filter_status")}
            value={status}
            onChange={(event) => {
              setStatus(event.target.value);
              setPage(1);
            }}
          >
            <option value="">{t("history.all_statuses")}</option>
            {STATUSES.map((value) => (
              <option key={value} value={value}>
                {t(`status.${value}`)}
              </option>
            ))}
          </ToolbarSelect>
        </>
      }
      footer={list.data && list.data.meta.last_page > 1 ? <Pager page={list.data.meta.current_page} lastPage={list.data.meta.last_page} onPage={setPage} /> : undefined}
    >
      <thead>
        <tr>
          <th scope="col">{t("history.col_date")}</th>
          <th scope="col">{t("history.col_station")}</th>
          <th scope="col">{t("history.col_template")}</th>
          <th scope="col">{t("history.col_status")}</th>
          <th scope="col">{t("history.col_opened")}</th>
          <th scope="col">{t("history.col_locked")}</th>
        </tr>
      </thead>
      <tbody>
        <QueryRows query={list} span={6} rows={6}>
          {(result) =>
            result.data.length === 0 ? (
              <EmptyRow span={6}>{t("history.shifts_none")}</EmptyRow>
            ) : (
              result.data.map((shift) => (
                <tr key={shift.id}>
                  <td className="whitespace-nowrap font-semibold tabular-nums">{isoDay(shift.date)}</td>
                  <td>{name(shift.station)}</td>
                  <td className="text-neutral-700">{shift.template ? name(shift.template) : t("running.ad_hoc")}</td>
                  <td>
                    <ShiftStatusBadge status={shift.status} />
                  </td>
                  <td className="tabular-nums text-neutral-700">{addisClock(shift.opened_at ?? shift.started_at) || "—"}</td>
                  <td className="tabular-nums text-neutral-700">{addisClock(shift.locked_at) || "—"}</td>
                </tr>
              ))
            )
          }
        </QueryRows>
      </tbody>
    </TableCard>
  );
}
