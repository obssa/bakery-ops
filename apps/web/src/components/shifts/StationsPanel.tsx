"use client";

import { useLocale, useTranslations } from "next-intl";
import { Link } from "@/i18n/navigation";
import { useWorkStations } from "@/lib/api/adminShifts";
import type { Locale, ShiftStatus, StationCode, WorkStation } from "@/lib/api/types";
import { can, useSession } from "@/lib/session";
import { addisClock } from "@/lib/shifts";
import { Badge, Panel } from "../ui";
import { focusRing } from "../styles";

// The stations of the branch and the shift running at each, for the people who work any station (admin, manager: stations.all), from
// GET /me work_stations (step 3.7a). A row opens the screen of its station. Looked at again every 15 s; the full roster calendar and the
// history are the admin shifts screen of step 3.7c.

const SCREEN: Record<StationCode, string | null> = {
  till: "/till",
  kitchen: "/kds/kitchen",
  bar: "/kds/bar",
  cake_room: "/production",
  bakery: "/production",
  store: null,
};

const TONE: Record<ShiftStatus, "neutral" | "blue" | "green" | "amber"> = {
  scheduled: "neutral",
  opening: "blue",
  open: "green",
  counting: "blue",
  pending_approval: "amber",
  locked: "neutral",
  cancelled: "neutral",
};

function StatusBadge({ station }: { station: WorkStation }) {
  const t = useTranslations("shifts");
  if (!station.tracks_shifts) return <span className="text-base text-neutral-600">{t("station_no_tracking")}</span>;
  const shift = station.current_shift;
  if (!shift) return <Badge tone="amber">{t("badge_none")}</Badge>;
  const key = shift.status === "opening" ? "badge_opening" : shift.status === "counting" ? "badge_counting" : "badge_open";
  return (
    <span className="flex items-center gap-2">
      <Badge tone={TONE[shift.status]}>{t(key)}</Badge>
      {shift.opened_at ? <span className="text-base text-neutral-600">{t("open_since", { time: addisClock(shift.opened_at) })}</span> : null}
    </span>
  );
}

export function StationsPanel() {
  const t = useTranslations("shifts");
  const locale = useLocale() as Locale;
  const me = useSession((s) => s.me);
  const allowed = can(me, "stations.all");

  // the same query (and so the same cached shape, the list) as the admin shifts screens this overview links to
  const fresh = useWorkStations({ enabled: allowed, refetchInterval: 15_000 });
  if (!allowed) return null;

  const stations = fresh.data ?? me?.work_stations ?? [];
  return (
    <Panel title={t("stations_title")} className="mt-6">
      <p className="mb-4 text-base text-neutral-600">{t("stations_intro")}</p>
      {stations.length === 0 ? (
        <p className="text-base text-neutral-700">{t("stations_none")}</p>
      ) : (
        <ul className="divide-y divide-neutral-200">
          {stations.map((station) => {
            const href = SCREEN[station.code];
            const name = locale === "am" ? station.name_am : station.name_en;
            return (
              <li key={station.station_id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
                  <p className="min-w-40 text-lg font-medium text-neutral-900">{name}</p>
                  <StatusBadge station={station} />
                </div>
                {href ? (
                  <Link
                    href={href}
                    aria-label={`${t("station_open_screen")}: ${name}`}
                    className={`inline-flex min-h-12 items-center rounded-md border border-neutral-300 bg-white px-5 text-base font-medium text-neutral-900 hover:bg-neutral-100 ${focusRing}`}
                  >
                    {t("station_open_screen")}
                  </Link>
                ) : null}
              </li>
            );
          })}
        </ul>
      )}
    </Panel>
  );
}
