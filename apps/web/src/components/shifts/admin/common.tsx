"use client";

import { useLocale, useTranslations } from "next-intl";
import { useEffect, useState } from "react";
import { Link, usePathname } from "@/i18n/navigation";
import type { Locale, ShiftStatus } from "@/lib/api/types";
import { adminSections } from "@/lib/shiftsAdmin";
import { addisDate } from "@/lib/shiftsAdmin";
import { useSession } from "@/lib/session";
import { Badge } from "../../ui";
import { focusRing } from "../../styles";

// Pieces the admin shifts screens share (step 3.7c): the section tabs, names in the page's language, the status label of a shift and
// the branch's local "today".

/** The sections of the admin shifts screens the person may open, as pills under the admin tabs (like Inventory). */
export function AdminShiftsNav() {
  const t = useTranslations("adminShifts");
  const me = useSession((s) => s.me);
  const pathname = usePathname().replace(/\/+$/, "");
  const sections = adminSections(me?.permissions ?? []);
  return (
    <nav aria-label={t("sections")} className="mb-6 flex flex-wrap gap-2">
      {sections.map(({ href, key }) => {
        const current = pathname === href;
        return (
          <Link
            key={href}
            href={href}
            aria-current={current ? "page" : undefined}
            className={`inline-flex min-h-12 items-center rounded-full border px-5 text-base ${
              current ? "border-neutral-900 bg-neutral-900 text-white" : "border-neutral-300 bg-white text-neutral-800 hover:bg-neutral-100"
            } ${focusRing}`}
          >
            {t(`nav.${key}`)}
          </Link>
        );
      })}
    </nav>
  );
}

/** The name of a station, template, place or reason in the page's language. */
export function useName(): (row: { name_en: string; name_am: string }) => string {
  const locale = useLocale() as Locale;
  return (row) => (locale === "am" ? row.name_am || row.name_en : row.name_en || row.name_am);
}

const TONE: Record<ShiftStatus, "neutral" | "blue" | "green" | "amber"> = {
  scheduled: "neutral",
  opening: "blue",
  open: "green",
  counting: "blue",
  pending_approval: "amber",
  locked: "neutral",
  cancelled: "neutral",
};

export function ShiftStatusBadge({ status }: { status: ShiftStatus }) {
  const t = useTranslations("adminShifts");
  return <Badge tone={TONE[status]}>{t(`status.${status}`)}</Badge>;
}

/** The branch's local date today, known only on the client (a static page cannot know it); "" until then. */
export function useToday(): string {
  const [today, setToday] = useState("");
  useEffect(() => setToday(addisDate(new Date())), []);
  return today;
}

export const TH = "py-2 pr-4 font-medium";
export const TD = "py-3 pr-4 align-top";

export function Loading() {
  const t = useTranslations("common");
  return <p className="py-4 text-base text-neutral-600">{t("loading")}</p>;
}
