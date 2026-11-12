"use client";

import { ChevronRight, ListChecks } from "lucide-react";
import { useTranslations } from "next-intl";
import { Link } from "@/i18n/navigation";
import { useCountingRules } from "@/lib/api/countingRules";
import { RULES, ruleState } from "@/lib/countingRules";
import { can, useSession } from "@/lib/session";
import { focusRing } from "../styles";
import { ICON_STROKE } from "../icons";
import { CARD } from "../admin/kit";

// Admin > System: the way to the counting rules (ADR 0026), with where each rule stands, for the admin (shifts.manage).

export function CountingRulesLink() {
  const t = useTranslations("counting_rules");
  const me = useSession((s) => s.me);
  const rules = useCountingRules();
  if (!can(me, "shifts.manage")) return null;
  const view = rules.data ?? null;

  return (
    <Link
      href="/admin/system/counting"
      data-testid="system-counting-rules"
      className={`${CARD} flex items-center gap-3 p-5 hover:border-neutral-300 hover:bg-neutral-50 motion-safe:transition-colors ${focusRing}`}
    >
      <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-700" aria-hidden="true">
        <ListChecks className="size-5" strokeWidth={ICON_STROKE} />
      </span>
      <span className="min-w-0 flex-1 space-y-1">
        <span className="block text-[17px] font-semibold text-neutral-900">{t("title")}</span>
        <span className="block text-[15px] text-neutral-600">
          {view
            ? RULES.map((rule) => t("summary_part", { rule: t(`${rule}.title`), state: ruleState(view, rule) })).join(" · ")
            : t("link_intro")}
        </span>
      </span>
      <ChevronRight className="size-5 shrink-0 text-neutral-500" strokeWidth={ICON_STROKE} aria-hidden="true" />
    </Link>
  );
}
