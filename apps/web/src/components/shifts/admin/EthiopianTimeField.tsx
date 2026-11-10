"use client";

import { useLocale, useTranslations } from "next-intl";
import { useId } from "react";
import { DAY_ORDER, ethiopianOf, internationalText, PART_WORDS } from "@/lib/clock";
import { useClockAlso } from "@/lib/useDates";
import { Select } from "../../ui";

// A time of day picked on the Ethiopian clock (the Ethiopian clock follow-up, Ramin 2026-11-10): the hour, listed as the day runs from
// sunrise ("12 morning", "1 morning", ... "6 afternoon", ... "12 evening", ... "11 night"), so no hour can be paired with a part of the day
// it does not have; and the minutes in fives. The value stays the API's 24-hour "HH:MM" (12 in the morning is 06:00); a template saved with
// minutes off the five-minute grid keeps them as a choice. With the admin's international clock on, each hour also names it ("1 morning ·
// 7 AM") and the line under the field says the time that way.

const FIVES = Array.from({ length: 12 }, (_, i) => i * 5);
const pad = (n: number) => String(n).padStart(2, "0");

export function EthiopianTimeField({ label, value, onChange, error }: { label: string; value: string; onChange: (value: string) => void; error?: string }) {
  const t = useTranslations("adminShifts.templates");
  const locale = useLocale();
  const also = useClockAlso();
  const id = useId();
  const match = /^(\d{2}):(\d{2})$/.exec(value);
  const hour24 = match ? Number(match[1]) : 6;
  const minute = match ? Number(match[2]) : 0;
  const minutes = FIVES.includes(minute) ? FIVES : [...FIVES, minute].sort((a, b) => a - b);
  const words = locale === "am" ? PART_WORDS.am : PART_WORDS.en;
  const international = internationalText(hour24, minute, also);
  const errorId = error ? `${id}-error` : undefined;
  const noteId = international ? `${id}-note` : undefined;
  const described = [noteId, errorId].filter(Boolean).join(" ") || undefined;

  const hourLabel = (h: number) => {
    const { hour, part } = ethiopianOf(h, 0);
    const other = also === "h12" ? `${h % 12 || 12} ${h < 12 ? "AM" : "PM"}` : also === "h24" ? `${pad(h)}:00` : null;
    return `${hour} ${words[part]}${other ? ` · ${other}` : ""}`;
  };

  return (
    <fieldset className="min-w-0 space-y-1.5" aria-describedby={described}>
      <legend className="mb-1.5 block text-sm font-semibold text-neutral-900">{label}</legend>
      <div className="grid grid-cols-[minmax(0,1.7fr)_minmax(0,1fr)] gap-2">
        <Select controlSize="md" aria-label={t("time_hour")} aria-invalid={error ? true : undefined} value={hour24} onChange={(event) => onChange(`${pad(Number(event.target.value))}:${pad(minute)}`)}>
          {DAY_ORDER.map((h) => (
            <option key={h} value={h}>
              {hourLabel(h)}
            </option>
          ))}
        </Select>
        <Select controlSize="md" aria-label={t("time_minute")} aria-invalid={error ? true : undefined} value={minute} onChange={(event) => onChange(`${pad(hour24)}:${pad(Number(event.target.value))}`)}>
          {minutes.map((m) => (
            <option key={m} value={m}>
              :{pad(m)}
            </option>
          ))}
        </Select>
      </div>
      {international ? (
        <p id={noteId} className="text-[13px] tabular-nums text-neutral-600">
          {t("time_international", { time: international })}
        </p>
      ) : null}
      {error ? (
        <p id={errorId} className="text-sm font-medium text-red-700">
          {error}
        </p>
      ) : null}
    </fieldset>
  );
}
