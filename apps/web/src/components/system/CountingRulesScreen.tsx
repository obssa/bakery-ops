"use client";

import { BadgeCheck, ChevronLeft, ClipboardList, Signature, type LucideIcon } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useId, useState } from "react";
import { Link } from "@/i18n/navigation";
import { useCountingRules, useSaveCountingRules } from "@/lib/api/countingRules";
import { useIdempotencyKey } from "@/lib/api/idempotency";
import type { CountingRule, CountingRulesView } from "@/lib/api/types";
import { RULES, ruleState, switchAll, switchOne, type RuleChange } from "@/lib/countingRules";
import { useErrorText } from "@/lib/errors";
import { CARD, Notice, TEXT_LINK } from "../admin/kit";
import { ICON_STROKE } from "../icons";
import { Alert, Button, Chip, HelpTip, PageHeader, Skeleton, Status, Switch } from "../ui";

// Admin > System > Counting rules (Ramin 2026-11-12, ADR 0026): the admin switches, per station or for every station at once, whether a
// shift starts by counting (the "double count": once when it opens, again when it closes), whether a handed-in count waits for a second
// person's co-signature, and whether a count that differs waits for a manager's approval. One card per rule, in the order a shift meets
// them; its "All stations" switch sits over the stations' own. A switch moves at once and goes back if the server refuses it. A rule
// applies from the next start or count: counts already waiting keep waiting, and the page says how many there are.

const ICONS: Record<CountingRule, LucideIcon> = { opening_count: ClipboardList, cosign: Signature, approval: BadgeCheck };

export function CountingRulesScreen({ titleAm }: { titleAm?: string }) {
  const t = useTranslations("counting_rules");
  const errorText = useErrorText();
  const rules = useCountingRules();
  const view = rules.data ?? null;

  return (
    <>
      <Link href="/admin/system" className={`${TEXT_LINK} mb-3 inline-flex min-h-11 items-center gap-1`}>
        <ChevronLeft className="size-4" strokeWidth={ICON_STROKE} aria-hidden="true" />
        {t("back")}
      </Link>
      <PageHeader title={t("title")} intro={t("intro")} twin={titleAm ? { text: titleAm, lang: "am" } : undefined} />
      {view === null ? (
        rules.error ? (
          <div className="space-y-3">
            <Alert>{errorText.message(rules.error)}</Alert>
            <Button variant="secondary" size="md" onClick={() => void rules.refetch()}>
              {t("retry")}
            </Button>
          </div>
        ) : (
          <div aria-hidden="true" className="grid gap-4 lg:grid-cols-3">
            {RULES.map((rule) => (
              <Skeleton key={rule} className="h-80 rounded-[18px]" />
            ))}
          </div>
        )
      ) : (
        <div className="flex flex-col gap-4">
          <Waiting view={view} />
          <div className="grid items-start gap-4 lg:grid-cols-3">
            {RULES.map((rule) => (
              <RuleCard key={rule} rule={rule} view={view} />
            ))}
          </div>
        </div>
      )}
    </>
  );
}

/** How many counts wait now: a switch does not release them, so the admin knows where to look. */
function Waiting({ view }: { view: CountingRulesView }) {
  const t = useTranslations("counting_rules");
  const { awaiting_cosign: cosign, pending_approval: approval } = view.waiting;
  if (cosign + approval === 0) return null;
  return (
    <Notice tone="amber">
      <span>{t("waiting", { cosign, approval })} </span>
      <Link href="/admin/shifts" className={TEXT_LINK}>
        {t("waiting_link")}
      </Link>
    </Notice>
  );
}

function RuleCard({ rule, view }: { rule: CountingRule; view: CountingRulesView }) {
  const t = useTranslations("counting_rules");
  const locale = useLocale();
  const errorText = useErrorText();
  const save = useSaveCountingRules();
  const key = useIdempotencyKey();
  const titleId = useId();
  const [said, setSaid] = useState<string | null>(null);
  const Icon = ICONS[rule];
  const state = ruleState(view, rule);
  const on = view.stations.filter((station) => station[rule]).length;
  const name = (station: { name_en: string; name_am: string }) => (locale === "am" ? station.name_am : station.name_en);

  function send(changes: RuleChange[], message: string) {
    if (changes.length === 0 || save.isPending) return;
    setSaid(null);
    save.mutate(
      { changes, idempotencyKey: key.keyFor(changes) },
      {
        onSuccess: () => {
          key.done();
          setSaid(message);
        },
      },
    );
  }

  return (
    <section data-testid={`counting-rule-${rule}`} aria-labelledby={titleId} className={`${CARD} flex min-w-0 flex-col gap-4 p-5`}>
      <div className="flex items-start gap-3">
        <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-700" aria-hidden="true">
          <Icon className="size-5" strokeWidth={ICON_STROKE} />
        </span>
        <div className="min-w-0 flex-1 space-y-1">
          <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
            <h2 id={titleId} className="text-[17px] font-semibold text-neutral-900">
              {t(`${rule}.title`)}
            </h2>
            <HelpTip label={t("help_label", { rule: t(`${rule}.title`) })}>{t(`${rule}.help`)}</HelpTip>
          </div>
          <Chip tone={state === "on" ? "positive" : state === "off" ? "neutral" : "amber"} dot>
            {state === "some" ? t("state_some", { on, total: view.stations.length }) : t(`state_${state}`)}
          </Chip>
        </div>
      </div>

      <dl className="space-y-2 text-[15px]">
        <div>
          <dt className="inline font-semibold text-neutral-900">{t("on_label")} </dt>
          <dd className="inline text-neutral-700">{t(`${rule}.on`)}</dd>
        </div>
        <div>
          <dt className="inline font-semibold text-neutral-900">{t("off_label")} </dt>
          <dd className="inline text-neutral-700">{t(`${rule}.off`)}</dd>
        </div>
      </dl>

      <div className="rounded-xl border border-neutral-200 bg-neutral-50 px-4 py-3">
        <Switch
          label={t("all_stations")}
          hint={state === "some" ? t("all_hint_some") : undefined}
          checked={state === "on"}
          disabled={save.isPending || view.stations.length === 0}
          onChange={(next) => send(switchAll(view, rule, next), t(next ? "saved_all_on" : "saved_all_off", { rule: t(`${rule}.title`) }))}
        />
      </div>

      <ul aria-label={t("stations_label", { rule: t(`${rule}.title`) })} className="divide-y divide-neutral-100">
        {view.stations.map((station) => (
          <li key={station.id} className="py-2.5 first:pt-0 last:pb-0">
            <Switch
              label={name(station)}
              checked={station[rule]}
              disabled={save.isPending}
              onChange={(next) =>
                send(switchOne(station.id, rule, next), t(next ? "saved_on" : "saved_off", { rule: t(`${rule}.title`), station: name(station) }))
              }
            />
          </li>
        ))}
      </ul>

      {save.error ? <Alert>{errorText.message(save.error)}</Alert> : null}
      <Status>{said}</Status>
    </section>
  );
}
