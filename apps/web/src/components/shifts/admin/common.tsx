"use client";

import { useLocale, useTranslations } from "next-intl";
import { useEffect, useState } from "react";
import { usePathname } from "@/i18n/navigation";
import type { Locale, ShiftStatus } from "@/lib/api/types";
import { adminSections } from "@/lib/shiftsAdmin";
import { addisDate } from "@/lib/shiftsAdmin";
import { useSession } from "@/lib/session";
import { SectionTabs } from "../../admin/kit";
import { Chip, type Tone } from "../../ui";

// Pieces the admin shifts screens share (step 3.7c): the section tabs, names in the page's language, the status label of a shift and
// the branch's local "today". Step 6.4d: the tabs as the admin's segmented tabs, the status a chip with its dot.

/**
 * The sections of the admin shifts screens the person may open: the navigation "Shifts sections" with the same link names, drawn as
 * segmented tabs under each page's title row (step 6.4d, components/admin/kit.tsx SectionTabs), as the catalog's Products and Ingredients
 * switch and the Payment and Inventory sections are. The review of a count belongs to the overview.
 */
export function AdminShiftsNav() {
  const t = useTranslations("adminShifts");
  const me = useSession((s) => s.me);
  const pathname = usePathname().replace(/\/+$/, "");
  const tabs = adminSections(me?.permissions ?? []).map(({ href, key }) => ({
    href,
    label: t(`nav.${key}`),
    current: pathname === href || (key === "overview" && pathname === "/admin/shifts/review"),
  }));
  if (tabs.length === 0) return null;
  return <SectionTabs label={t("sections")} tabs={tabs} />;
}

/** The name of a station, template, place or reason in the page's language. */
export function useName(): (row: { name_en: string; name_am: string }) => string {
  const locale = useLocale() as Locale;
  return (row) => (locale === "am" ? row.name_am || row.name_en : row.name_en || row.name_am);
}

// green is money confirmed only (fresh-counter.md 1.3): a shift that is open is brand teal, as the station bar's chip is
const TONE: Record<ShiftStatus, Tone> = {
  scheduled: "neutral",
  opening: "blue",
  open: "brand",
  counting: "blue",
  pending_approval: "amber",
  locked: "neutral",
  cancelled: "neutral",
};

export function ShiftStatusBadge({ status }: { status: ShiftStatus }) {
  const t = useTranslations("adminShifts");
  return (
    <Chip tone={TONE[status]} dot>
      {t(`status.${status}`)}
    </Chip>
  );
}

/** The branch's local date today, known only on the client (a static page cannot know it); "" until then. */
export function useToday(): string {
  const [today, setToday] = useState("");
  useEffect(() => setToday(addisDate(new Date())), []);
  return today;
}

export function Loading() {
  const t = useTranslations("common");
  return <p className="py-4 text-[15px] text-neutral-600">{t("loading")}</p>;
}
