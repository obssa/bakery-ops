"use client";

import { useTranslations } from "next-intl";
import { Link } from "@/i18n/navigation";
import type { AuditEntry } from "@/lib/api/types";
import { focusRing } from "../styles";
import { useNames } from "./names";

/** The subject: its name and number, a link (in the brand red, step 6.4d) to every entry about it. */
export function SubjectCell({ entry }: { entry: Pick<AuditEntry, "subject_type" | "subject_id"> }) {
  const t = useTranslations("audit");
  const names = useNames();
  const label = names.subject(entry.subject_type, entry.subject_id);
  if (entry.subject_id === null) return <>{label}</>;
  return (
    <Link
      href={{ pathname: "/admin/audit", query: { subject_type: entry.subject_type, subject_id: String(entry.subject_id) } }}
      aria-label={t("list.subject_history", { subject: label })}
      className={`rounded-md font-semibold text-brand-600 underline-offset-2 hover:text-brand-700 hover:underline ${focusRing}`}
    >
      {label}
    </Link>
  );
}
