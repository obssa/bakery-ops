"use client";

import { useId } from "react";
import { useTranslations } from "next-intl";
import { DATE_DISPLAYS, type DateDisplay } from "@/lib/dates";
import { useDateDisplay } from "@/lib/useDates";
import { focusRing } from "./styles";

/**
 * The person's calendar, beside the language switcher (step 4.5f; in the station top bar since step 6.2b): Gregorian, Ethiopian or both. A
 * label choice only (every date stays a Gregorian date underneath), kept in this browser for this person. `field` (step 6.4a): in the admin
 * sidebar's account menu, its label shown above a full-width 44 px select; the label is "Dates" either way.
 */
export function DateDisplaySwitch({ look = "bar" }: { look?: "bar" | "field" } = {}) {
  const t = useTranslations("dates");
  const id = useId();
  const [display, choose] = useDateDisplay();
  const field = look === "field";
  return (
    <div className={field ? "space-y-1.5" : "flex items-center"}>
      <label htmlFor={id} className={field ? "block text-[13px] font-semibold text-neutral-700" : "sr-only"}>
        {t("label")}
      </label>
      <select
        id={id}
        value={display}
        title={t("hint")}
        onChange={(event) => choose(event.target.value as DateDisplay)}
        className={`block rounded-xl border border-neutral-300 bg-white px-3 pr-8 text-sm font-semibold text-neutral-900 hover:bg-neutral-50 ${field ? "min-h-11 w-full" : "min-h-12"} ${focusRing}`}
      >
        {DATE_DISPLAYS.map((option) => (
          <option key={option} value={option}>
            {t(option)}
          </option>
        ))}
      </select>
    </div>
  );
}
