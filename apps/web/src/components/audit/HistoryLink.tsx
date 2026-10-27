"use client";

import { useTranslations } from "next-intl";
import { Link } from "@/i18n/navigation";
import { historyQuery, type SubjectKind } from "@/lib/audit";
import { can, useSession } from "@/lib/session";
import { focusRing } from "../styles";

/**
 * "History": the audit log filtered to one subject (a payment, an order, a count), for the holders of audit.view (step 4.5f). The order
 * timeline of step 4.5d links the same way.
 */
export function HistoryLink({ kind, id }: { kind: SubjectKind; id: number }) {
  const t = useTranslations("audit");
  const allowed = useSession((s) => can(s.me, "audit.view"));
  if (!allowed) return null;
  return (
    <Link
      href={{ pathname: "/admin/audit", query: historyQuery(kind, id) }}
      className={`inline-flex min-h-12 items-center rounded-md border border-neutral-300 bg-white px-4 text-base font-medium text-neutral-900 hover:bg-neutral-100 ${focusRing}`}
    >
      {t("history")}
    </Link>
  );
}
