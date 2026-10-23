"use client";

import { useTranslations } from "next-intl";
import { useMemo, useRef, useState } from "react";
import { Link } from "@/i18n/navigation";
import { useWorkStations, usePlaces, useTemplates } from "@/lib/api/adminShifts";
import { uuid } from "@/lib/api/idempotency";
import { ApiError } from "@/lib/api/client";
import { cancelShift } from "@/lib/api/shifts";
import type { ShiftSummary } from "@/lib/api/types";
import { useErrorText } from "@/lib/errors";
import { createRunningFeed } from "@/lib/live/running";
import { useLiveFeed } from "@/lib/live/useLiveFeed";
import { can, useSession } from "@/lib/session";
import { addisClock } from "@/lib/shifts";
import { setupCheck } from "@/lib/shiftsAdmin";
import { Alert, Badge, Button, Panel } from "../../ui";
import { focusRing } from "../../styles";
import { ShiftStatusBadge, useName } from "./common";

// The overview of the admin shifts screens (step 3.7c): the setup check, the shifts running now (a polled feed behind an ETag) and,
// mounted by the page below them, the approval queue of step 3.7b.

const LINK = `inline-flex min-h-12 items-center justify-center rounded-md border border-neutral-300 bg-white px-5 text-base font-medium text-neutral-900 hover:bg-neutral-100 ${focusRing}`;

/** Stock places with no station, and stations that track shifts but have no template, each with the link that fixes it. */
export function SetupCheck() {
  const t = useTranslations("adminShifts");
  const name = useName();
  const stations = useWorkStations();
  const places = usePlaces();
  const templates = useTemplates();
  const me = useSession((s) => s.me);

  const result = useMemo(() => {
    if (!stations.data || !templates.data) return null;
    // a person who may not read the places (inventory.view) is told only about the stations
    return setupCheck({ stations: stations.data, locations: places.data ?? [], templates: templates.data });
  }, [stations.data, places.data, templates.data]);

  return (
    <Panel title={t("setup.title")}>
      {result === null ? (
        <p className="text-base text-neutral-600">{t("setup.checking")}</p>
      ) : result.ok ? (
        <p className="text-base text-green-800">{t("setup.ok")}</p>
      ) : (
        <div className="space-y-4">
          <p className="text-base text-neutral-700">{t("setup.intro")}</p>
          {result.stationsWithoutTemplates.length > 0 ? (
            <ul className="space-y-3" aria-label={t("setup.no_template_list")}>
              {result.stationsWithoutTemplates.map((station) => (
                <li key={station.station_id} className="flex flex-wrap items-center justify-between gap-3 rounded-md bg-amber-50 px-4 py-3">
                  <span className="text-base text-amber-900">{t("setup.no_template", { station: name(station) })}</span>
                  {can(me, "shifts.manage") ? (
                    <Link href={{ pathname: "/admin/shifts/templates", query: { station: station.station_id } }} className={LINK}>
                      {t("setup.add_template")}
                    </Link>
                  ) : null}
                </li>
              ))}
            </ul>
          ) : null}
          {result.placesWithoutStation.length > 0 ? (
            <ul className="space-y-3" aria-label={t("setup.no_station_list")}>
              {result.placesWithoutStation.map((place) => (
                <li key={place.id} className="flex flex-wrap items-center justify-between gap-3 rounded-md bg-amber-50 px-4 py-3">
                  <span className="text-base text-amber-900">{t("setup.no_station", { place: name(place), type: place.type })}</span>
                  <Link href="/admin/inventory" className={LINK}>
                    {t("setup.open_inventory")}
                  </Link>
                </li>
              ))}
            </ul>
          ) : null}
        </div>
      )}
    </Panel>
  );
}

function Running({ shift }: { shift: ShiftSummary }) {
  const t = useTranslations("adminShifts");
  const name = useName();
  const errorText = useErrorText();
  const me = useSession((s) => s.me);
  const key = useRef<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [failure, setFailure] = useState<unknown>(null);
  const canCancel = shift.status === "opening" && (can(me, "shifts.manage") || shift.started_by?.id === me?.user.id);

  async function cancel() {
    if (busy) return;
    key.current ??= uuid();
    setBusy(true);
    setFailure(null);
    try {
      await cancelShift(shift.id, key.current);
      key.current = null;
    } catch (error) {
      setFailure(error);
      if (!(error instanceof ApiError) || error.status !== 0) key.current = null;
    } finally {
      setBusy(false);
    }
  }

  const label = shift.template ? name(shift.template) : t("running.ad_hoc");
  const since = shift.status === "counting" ? shift.closing_started_at : (shift.opened_at ?? shift.started_at);
  return (
    <li className="space-y-3 rounded-lg border border-neutral-200 bg-white p-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="space-y-1">
          <p className="flex flex-wrap items-center gap-3 text-lg font-semibold text-neutral-900">
            <span>{name(shift.station)}</span>
            <ShiftStatusBadge status={shift.status} />
          </p>
          <p className="text-base text-neutral-700">{t("running.line", { name: label, date: shift.date })}</p>
          <p className="text-sm text-neutral-600">
            {shift.started_by ? t("running.started_by", { name: shift.started_by.name, time: addisClock(since) }) : t("running.since", { time: addisClock(since) })}
          </p>
        </div>
        <div className="flex flex-wrap gap-3">
          {shift.status === "opening" || shift.status === "counting" ? (
            <Link href={{ pathname: "/shifts/count", query: { shift: shift.id } }} className={LINK} aria-label={t("running.open_count_named", { station: name(shift.station) })}>
              {t("running.open_count")}
            </Link>
          ) : null}
          {canCancel ? (
            <Button type="button" variant="secondary" disabled={busy} onClick={() => void cancel()}>
              {busy ? t("working") : t("running.cancel_start")}
            </Button>
          ) : null}
        </div>
      </div>
      {failure ? <Alert>{errorText.message(failure)}</Alert> : null}
    </li>
  );
}

/** The shifts running now (opening, open, counting) at every station the person works at. */
export function RunningShifts() {
  const t = useTranslations("adminShifts");
  const feed = useMemo(() => createRunningFeed(), []);
  const snapshot = useLiveFeed(feed);
  const shifts = snapshot?.state.shifts ?? [];
  const loaded = !!snapshot?.loaded;
  return (
    <Panel title={t("running.title")}>
      <div className="space-y-4">
        {!loaded && !snapshot?.error ? <p role="status" className="text-base text-neutral-600">{t("running.loading")}</p> : null}
        {snapshot?.error ? (
          <p role="status" className="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-base text-amber-900">
            {loaded ? t("running.stale") : t("running.failed")}
          </p>
        ) : null}
        {loaded && shifts.length === 0 ? <p className="text-base text-neutral-700">{t("running.none")}</p> : null}
        {shifts.length > 0 ? (
          <>
            <p>
              <Badge tone="green">{t("running.count", { count: shifts.length })}</Badge>
            </p>
            <ul className="space-y-3">
              {shifts.map((shift) => (
                <Running key={shift.id} shift={shift} />
              ))}
            </ul>
          </>
        ) : null}
      </div>
    </Panel>
  );
}

