"use client";

import { CircleCheck, Hourglass } from "lucide-react";
import { useTranslations } from "next-intl";
import { useId, useMemo, useRef, useState, type ReactNode } from "react";
import { Link } from "@/i18n/navigation";
import { useWorkStations, usePlaces, useTemplates } from "@/lib/api/adminShifts";
import { uuid } from "@/lib/api/idempotency";
import { ApiError } from "@/lib/api/client";
import { cancelShift } from "@/lib/api/shifts";
import type { QueueRow, ShiftSummary } from "@/lib/api/types";
import { useErrorText } from "@/lib/errors";
import { createRunningFeed } from "@/lib/live/running";
import { useLiveFeed } from "@/lib/live/useLiveFeed";
import { can, useSession } from "@/lib/session";
import { setupCheck } from "@/lib/shiftsAdmin";
import { useClock, useIsoDay } from "@/lib/useDates";
import { useNow } from "@/lib/useNow";
import { closingDays, needsSomeone, shiftWait } from "@/lib/waiting";
import { CARD, Notice, TEXT_LINK } from "../../admin/kit";
import { Alert, Button, Chip, HelpTip, HowItWorks, Skeleton } from "../../ui";
import { buttonClass } from "../../styles";
import { ShiftStatusBadge, useName } from "./common";

// The overview of the admin shifts screens (step 3.7c): the setup check, the shifts running now (a polled feed behind an ETag) and,
// mounted by the page below them, the approval queue of step 3.7b.
//
// Step 6.4d (fresh-counter.md 4.4, the Admin board's "Shifts" card): white 18 px cards titled 17 / 600; the running shifts as the board's
// rows (the station and its state chip, the template and who started it, the count's link at the row's end) on hairlines, their number a
// brand chip beside the title; the setup check's fixes as amber rows with their link, or one brand line when everything is in place.
//
// The explain-itself follow-up (Ramin 2026-11-10: how shifts work, why someone must wait, what "Ad hoc" means): "How shifts work" as eight
// numbered steps above the cards; under each running shift a line saying what it waits for and who can act (lib/waiting.ts, from the
// approval queue the page reads once); an amber "Closing for 17 days" on a shift whose closing count nobody handed in; "Ad hoc" is now
// "Unscheduled", with a "?" saying what that means; and each setup fix says what goes wrong until it is done.

const HOW_STEPS = ["start", "opening", "open", "close", "closing", "cosign", "approve", "locked"] as const;

/** How a shift goes from start to locked, in eight steps, and why a place has to wait. */
export function HowShiftsWork() {
  const t = useTranslations("adminShifts.how");
  return (
    <HowItWorks
      title={t("title")}
      remember="shifts"
      showLabel={t("show")}
      hideLabel={t("hide")}
      steps={HOW_STEPS.map((step) => ({ title: t(`${step}_title`), text: t(step) }))}
      footer={t("why")}
    />
  );
}

/** A card of the overview: a region named by its 17 / 600 heading, with a chip or a note on the right. */
export function OverviewCard({ title, aside, children, className = "" }: { title: string; aside?: ReactNode; children: ReactNode; className?: string }) {
  const id = useId();
  return (
    <section aria-labelledby={id} className={`${CARD} flex flex-col gap-4 p-5 ${className}`}>
      <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
        <h2 id={id} className="text-[17px] font-semibold text-neutral-900">
          {title}
        </h2>
        {aside}
      </div>
      {children}
    </section>
  );
}

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
    <OverviewCard title={t("setup.title")}>
      {result === null ? (
        <p className="text-[15px] text-neutral-600">{t("setup.checking")}</p>
      ) : result.ok ? (
        <p className="flex items-start gap-3 rounded-xl bg-positive-50 px-4 py-3 text-[15px] font-medium text-positive-700">
          <CircleCheck className="mt-0.5 size-5 shrink-0" strokeWidth={2} aria-hidden="true" />
          {t("setup.ok")}
        </p>
      ) : (
        <div className="space-y-3">
          <p className="text-[15px] text-neutral-700">{t("setup.intro")}</p>
          {result.stationsWithoutTemplates.length > 0 ? (
            <ul className="divide-y divide-neutral-100" aria-label={t("setup.no_template_list")}>
              {result.stationsWithoutTemplates.map((station) => (
                <li key={station.station_id} className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 py-2.5">
                  <span className="flex min-w-0 items-start gap-2.5 text-[15px] text-neutral-900">
                    <span aria-hidden="true" className="mt-2 size-2 shrink-0 rounded-full bg-amber-700" />
                    {t("setup.no_template", { station: name(station) })}
                  </span>
                  {can(me, "shifts.manage") ? (
                    <Link href={{ pathname: "/admin/shifts/templates", query: { station: station.station_id } }} className={TEXT_LINK}>
                      {t("setup.add_template")}
                    </Link>
                  ) : null}
                </li>
              ))}
            </ul>
          ) : null}
          {result.placesWithoutStation.length > 0 ? (
            <ul className="divide-y divide-neutral-100" aria-label={t("setup.no_station_list")}>
              {result.placesWithoutStation.map((place) => (
                <li key={place.id} className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 py-2.5">
                  <span className="flex min-w-0 items-start gap-2.5 text-[15px] text-neutral-900">
                    <span aria-hidden="true" className="mt-2 size-2 shrink-0 rounded-full bg-amber-700" />
                    {t("setup.no_station", { place: name(place), type: place.type })}
                  </span>
                  <Link href="/admin/inventory" className={TEXT_LINK}>
                    {t("setup.open_inventory")}
                  </Link>
                </li>
              ))}
            </ul>
          ) : null}
        </div>
      )}
    </OverviewCard>
  );
}

/** What the running shift waits for and who can act, in a line; amber when it needs someone other than the people on duty. */
function WaitLine({ shift, queue }: { shift: ShiftSummary; queue: readonly QueueRow[] }) {
  const t = useTranslations("adminShifts.running.wait");
  const name = useName();
  const wait = shiftWait(shift, queue);
  if (!wait) return null;
  const text =
    wait.kind === "cosign" || wait.kind === "approval"
      ? t(`${wait.kind}${wait.counter ? "" : "_anon"}`, { type: wait.type, name: wait.counter ?? "" })
      : t(wait.kind, { station: name(shift.station) });
  return (
    <p className={`flex items-start gap-2 text-[13px] leading-snug ${needsSomeone(wait) ? "text-amber-900" : "text-neutral-700"}`}>
      <Hourglass className="mt-px size-4 shrink-0" strokeWidth={2} aria-hidden="true" />
      {text}
    </p>
  );
}

function Running({ shift, queue }: { shift: ShiftSummary; queue: readonly QueueRow[] }) {
  const clock = useClock();
  // a minute is enough for a count of days
  const now = useNow(60_000);
  const isoDay = useIsoDay();
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
  const stuck = closingDays(shift, new Date(now));
  return (
    <li className="space-y-2 py-3 first:pt-0 last:pb-0">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="min-w-0 space-y-0.5">
          <p className="flex flex-wrap items-center gap-2.5">
            <span className="text-[15px] font-semibold text-neutral-900">{name(shift.station)}</span>
            <ShiftStatusBadge status={shift.status} />
            {stuck > 0 ? (
              <span className="inline-flex items-center gap-1">
                <Chip tone="amber" dot>
                  {t("running.closing_for", { count: stuck })}
                </Chip>
                <HelpTip label={t("running.closing_for_label")}>{t("running.closing_for_help")}</HelpTip>
              </span>
            ) : null}
          </p>
          <p className="flex flex-wrap items-center gap-x-1 text-[13px] text-neutral-600">
            <span>{t("running.line", { name: label, date: isoDay(shift.date) })}</span>
            {shift.template ? null : <HelpTip label={t("running.ad_hoc_label")}>{t("running.ad_hoc_help")}</HelpTip>}
            <span aria-hidden="true">·</span>
            <span>
              {shift.started_by ? t("running.started_by", { name: shift.started_by.name, time: clock(since) }) : t("running.since", { time: clock(since) })}
            </span>
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          {shift.status === "opening" || shift.status === "counting" ? (
            <Link
              href={{ pathname: "/shifts/count", query: { shift: shift.id } }}
              className={buttonClass({ variant: "secondary", size: "md" })}
              aria-label={t("running.open_count_named", { station: name(shift.station) })}
            >
              {t("running.open_count")}
            </Link>
          ) : null}
          {canCancel ? (
            <Button type="button" variant="ghost" size="md" disabled={busy} onClick={() => void cancel()}>
              {busy ? t("working") : t("running.cancel_start")}
            </Button>
          ) : null}
        </div>
      </div>
      <WaitLine shift={shift} queue={queue} />
      {failure ? <Alert>{errorText.message(failure)}</Alert> : null}
    </li>
  );
}

/** The shifts running now (opening, open, counting) at every station the person works at, each with what it waits for. */
export function RunningShifts({ queue }: { queue: readonly QueueRow[] }) {
  const t = useTranslations("adminShifts");
  const feed = useMemo(() => createRunningFeed(), []);
  const snapshot = useLiveFeed(feed);
  const shifts = snapshot?.state.shifts ?? [];
  const loaded = !!snapshot?.loaded;
  return (
    <OverviewCard title={t("running.title")} aside={shifts.length > 0 ? <Chip tone="positive">{t("running.count", { count: shifts.length })}</Chip> : null}>
      {!loaded && !snapshot?.error ? (
        <>
          <p role="status" className="sr-only">
            {t("running.loading")}
          </p>
          <div aria-hidden="true" className="space-y-3">
            <Skeleton className="h-11" />
            <Skeleton className="h-11" />
          </div>
        </>
      ) : null}
      {snapshot?.error ? (
        <Notice tone="amber" live>
          {loaded ? t("running.stale") : t("running.failed")}
        </Notice>
      ) : null}
      {loaded && shifts.length === 0 ? <p className="text-[15px] text-neutral-600">{t("running.none")}</p> : null}
      {shifts.length > 0 ? (
        <ul className="divide-y divide-neutral-100">
          {shifts.map((shift) => (
            <Running key={shift.id} shift={shift} queue={queue} />
          ))}
        </ul>
      ) : null}
    </OverviewCard>
  );
}
