"use client";

import { Clock } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useId, useState } from "react";
import { useIdempotencyKey } from "@/lib/api/idempotency";
import { useSaveClock } from "@/lib/brand";
import { CLOCK_ALSO, wallText, type ClockAlso } from "@/lib/clock";
import { useErrorText } from "@/lib/errors";
import { can, useSession } from "@/lib/session";
import { useClockAlso } from "@/lib/useDates";
import { CARD } from "../admin/kit";
import { Alert, SegmentedControl, Status } from "../ui";
import { ICON_STROKE } from "../icons";

// Admin > System > Time (the Ethiopian clock follow-up, Ramin 2026-11-10): every time in the app, on the receipts and on the printed
// reports reads in Ethiopian time (lib/clock.ts); the admin may show the international clock beside it, 12-hour or 24-hour, for everyone
// (PUT /brand/clock, branding.manage). The card says how the clock works with examples across the day, in the choice as it stands, so the
// admin sees what everyone will see before and after changing it. A choice is saved at once.

/** The examples, as 24-hour times: the first hour of the day, noon, an afternoon, the evening and the night. */
const EXAMPLES: readonly [number, number][] = [
  [6, 0],
  [7, 0],
  [12, 0],
  [16, 10],
  [19, 30],
  [0, 15],
];

const pad = (n: number) => String(n).padStart(2, "0");

export function ClockCard() {
  const t = useTranslations("system.clock");
  const locale = useLocale();
  const errorText = useErrorText();
  const me = useSession((s) => s.me);
  const current = useClockAlso();
  const titleId = useId();
  const save = useSaveClock();
  const key = useIdempotencyKey();
  const [saved, setSaved] = useState<string | null>(null);
  if (!can(me, "branding.manage")) return null;

  function choose(also: ClockAlso) {
    if (also === current || save.isPending) return;
    setSaved(null);
    save.mutate(
      { also, idempotencyKey: key.keyFor({ also }) },
      {
        onSuccess: () => {
          key.done();
          setSaved(t(`saved_${also}`));
        },
      },
    );
  }

  return (
    <section data-testid="system-clock" aria-labelledby={titleId} className={`${CARD} flex flex-col gap-5 p-5`}>
      <div className="flex items-start gap-3">
        <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-700" aria-hidden="true">
          <Clock className="size-5" strokeWidth={ICON_STROKE} />
        </span>
        <div className="min-w-0 space-y-1">
          <h2 id={titleId} className="text-[17px] font-semibold text-neutral-900">
            {t("title")}
          </h2>
          <p className="text-[15px] text-neutral-600">{t("intro")}</p>
        </div>
      </div>

      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        <div className="flex flex-col gap-3">
          <p id={`${titleId}-choice`} className="text-sm font-semibold text-neutral-900">
            {t("also_label")}
          </p>
          <SegmentedControl<ClockAlso>
            label={t("also_label")}
            value={current}
            onChange={choose}
            options={CLOCK_ALSO.map((also) => ({ value: also, label: t(`also_${also}`) }))}
          />
          <p className="text-[13px] text-neutral-600">{t("also_hint")}</p>
          {save.error ? <Alert>{errorText.message(save.error)}</Alert> : null}
          <Status>{saved}</Status>
        </div>

        <figure className="min-w-0">
          <figcaption className="mb-2 text-sm font-semibold text-neutral-900">{t("examples")}</figcaption>
          <dl className="divide-y divide-neutral-200 rounded-xl border border-neutral-200">
            {EXAMPLES.map(([hour, minute]) => (
              <div key={`${hour}:${minute}`} className="flex items-baseline justify-between gap-4 px-4 py-2.5">
                <dt className="text-[13px] tabular-nums text-neutral-600">{t("example_at", { time: `${pad(hour)}:${pad(minute)}` })}</dt>
                <dd className="text-right text-[15px] font-semibold tabular-nums text-neutral-900">{wallText(hour, minute, { locale, also: current })}</dd>
              </div>
            ))}
          </dl>
        </figure>
      </div>
    </section>
  );
}
