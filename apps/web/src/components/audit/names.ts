"use client";

import { useLocale, useTranslations } from "next-intl";
import type { Locale } from "@/lib/api/types";
import { shortType, subjectKindOf } from "@/lib/audit";
import { useSession } from "@/lib/session";

/**
 * How the audit screen names people, stations and subjects in the page's language. A person the API could not name (nobody of the branch
 * has the id) keeps their number; a station comes from GET /me's work stations (every station for an admin), else its number; a subject
 * the screen knows reads by its kind ("Payment 41"), any other by the last piece of the class the API wrote.
 */
export function useNames() {
  const t = useTranslations("audit");
  const locale = useLocale() as Locale;
  const stations = useSession((s) => s.me?.work_stations ?? []);
  return {
    person: (person: { id: number; name: string | null }): string => person.name || t("person_no", { id: person.id }),
    station: (id: number): string => {
      const station = stations.find((s) => s.station_id === id);
      if (!station) return t("station_no", { id });
      return (locale === "am" ? station.name_am || station.name_en : station.name_en || station.name_am) || t("station_no", { id });
    },
    subject: (type: string, id: number | null): string => {
      const kind = subjectKindOf(type);
      if (kind === "none") return "—";
      const name = kind ? t(`subject.${kind}`) : shortType(type);
      return id === null ? name : t("subject_no", { name, id });
    },
  };
}
