"use client";

import { useQuery } from "@tanstack/react-query";
import { useLocale, useTranslations } from "next-intl";
import { getHandover } from "@/lib/api/counts";
import type { CountPlace, Locale } from "@/lib/api/types";
import { shortHash } from "@/lib/counts";
import { useErrorText } from "@/lib/errors";
import { useSession } from "@/lib/session";
import { addisClock } from "@/lib/shifts";
import { Alert, Badge, Panel } from "../ui";
import { NotesList } from "./Notes";

// What the last shift left for this one (GET /stations/{station}/handover, step 3.7b): its notes and the notes addressed to me, one
// closing per place (a closing still waiting for approval is shown as such, it has no fingerprint yet), the money the drawer keeps,
// and the counts nobody has decided yet. It sits in the shift bar before a shift starts and above the opening count. People without
// shifts.view (the waiter) never see it: the API refuses them and the bar is hidden for them.

function placeName(place: CountPlace, locale: Locale): string {
  return locale === "am" ? place.name_am : place.name_en;
}

export function HandoverPanel({ stationId }: { stationId: number }) {
  const t = useTranslations("counts");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const signedIn = useSession((s) => s.status === "signed-in");
  const query = useQuery({
    queryKey: ["handover", stationId],
    queryFn: () => getHandover(stationId),
    enabled: signedIn,
    refetchInterval: 15_000,
  });

  const data = query.data;
  return (
    <Panel title={t("handover_title")}>
      {query.isError && !data ? <Alert>{errorText.message(query.error)}</Alert> : null}
      {!data && !query.isError ? <p className="text-base text-neutral-600">{t("loading")}</p> : null}
      {data ? (
        <div className="space-y-5">
          <p className="text-base text-neutral-700">
            {data.previous
              ? t("handover_previous", {
                  date: data.previous.date,
                  name: data.previous.closed_by?.name ?? data.previous.started_by?.name ?? "",
                })
              : t("handover_no_previous")}
          </p>

          <section aria-label={t("handover_notes")} className="space-y-2">
            <h3 className="text-base font-semibold text-neutral-900">{t("handover_notes")}</h3>
            <NotesList notes={data.notes} />
          </section>

          {data.previous_closing.length > 0 ? (
            <section aria-label={t("handover_closings")} className="space-y-2">
              <h3 className="text-base font-semibold text-neutral-900">{t("handover_closings")}</h3>
              <ul className="space-y-2">
                {data.previous_closing.map((row) => (
                  <li key={row.count_id} className="flex flex-wrap items-center gap-x-4 gap-y-1 rounded-md border border-neutral-200 px-4 py-3">
                    <span className="text-base font-medium text-neutral-900">{placeName(row.location, locale)}</span>
                    {row.provisional ? (
                      <Badge tone="amber">{t("closing_provisional")}</Badge>
                    ) : (
                      <>
                        <Badge tone="green">{t("closing_locked")}</Badge>
                        <span className="text-sm text-neutral-600">
                          {t("chain_link", { seq: row.chain_seq ?? 0 })} · <code title={row.payload_hash ?? ""}>{shortHash(row.payload_hash)}</code>
                          {row.locked_at ? ` · ${addisClock(row.locked_at)}` : ""}
                        </span>
                      </>
                    )}
                    {row.float_left_total !== null ? <span className="text-sm text-neutral-700">{t("float_left", { amount: row.float_left_total })}</span> : null}
                  </li>
                ))}
              </ul>
            </section>
          ) : null}

          {data.pending_counts.length > 0 ? (
            <p role="status" className="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-base text-amber-900">
              {t("handover_pending", { count: data.pending_counts.length })}
            </p>
          ) : null}
        </div>
      ) : null}
    </Panel>
  );
}
