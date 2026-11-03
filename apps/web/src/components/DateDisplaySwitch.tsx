"use client";

import { useId } from "react";
import { useTranslations } from "next-intl";
import { DATE_DISPLAYS, type DateDisplay } from "@/lib/dates";
import { useDateDisplay } from "@/lib/useDates";
import { focusRing } from "./styles";

/**
 * The person's calendar, beside the language switcher (step 4.5f; in the station top bar since step 6.2b): Gregorian, Ethiopian or both. A
 * label choice only (every date stays a Gregorian date underneath), kept in this browser for this person.
 */
export function DateDisplaySwitch() {
  const t = useTranslations("dates");
  const id = useId();
  const [display, choose] = useDateDisplay();
  return (
    <div className="flex items-center">
      <label htmlFor={id} className="sr-only">
        {t("label")}
      </label>
      <select
        id={id}
        value={display}
        title={t("hint")}
        onChange={(event) => choose(event.target.value as DateDisplay)}
        className={`block min-h-12 rounded-xl border border-neutral-300 bg-white px-3 pr-8 text-sm font-semibold text-neutral-900 hover:bg-neutral-50 ${focusRing}`}
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
