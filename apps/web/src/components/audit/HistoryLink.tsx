"use client";

import { useTranslations } from "next-intl";
import { Link } from "@/i18n/navigation";
import { historyQuery, type SubjectKind } from "@/lib/audit";
import { can, useSession } from "@/lib/session";
import { focusRing } from "../styles";

/**
 * "Audit history": the audit log filtered to one subject (a payment, an order, a count), for the holders of audit.view (step 4.5f). The
 * order record of step 4.5d links the order and each of its payments: a page with several gives each its own accessible name (`label`,
 * which starts with the words on screen), so every link's name is unique in the page.
 */
export function HistoryLink({ kind, id, label }: { kind: SubjectKind; id: number; label?: string }) {
  const t = useTranslations("audit");
  const allowed = useSession((s) => can(s.me, "audit.view"));
  if (!allowed) return null;
  return (
    <Link
      href={{ pathname: "/admin/audit", query: historyQuery(kind, id) }}
      aria-label={label}
      className={`inline-flex min-h-12 items-center rounded-md border border-neutral-300 bg-white px-4 text-base font-medium text-neutral-900 hover:bg-neutral-100 ${focusRing}`}
    >
      {t("history")}
    </Link>
  );
}
