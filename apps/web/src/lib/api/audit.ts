"use client";

import { useQuery } from "@tanstack/react-query";
import { authed, authedBlob, can, useSession } from "../session";
import { ApiError } from "./client";
import type { AuditDetail, AuditPage } from "./types";

// The reads of the audit screen (step 4.5f over step 4.5a's routes; phase-4 plan section 4.7). The audit log is not polled: a trail of
// what happened does not need a live refresh, so the first page is read when the filters change and again on Refresh, and the older pages
// are read once by cursor ("Older entries"). The API answers these reads without an ETag (they are never cached there), so they go
// through `authed` as plain reads. Query keys are one per answer shape: ["audit-log", "page", query] a first page ({data, next_cursor}),
// ["audit-log", "entry", id] one row with its before, after and context. The people list the actor filter offers is the admin console's
// ["users"] (GET /users, people.manage), the same shape as the People screen's.

export const auditKeys = {
  page: (query: Record<string, string>) => ["audit-log", "page", JSON.stringify(query)] as const,
  entry: (id: number) => ["audit-log", "entry", id] as const,
};

function useAllowed(permission: string): boolean {
  return useSession((s) => s.status === "signed-in" && can(s.me, permission));
}

/** a refusal (a filter the API will not read, a row of another branch) is not asked again; a lost answer once */
function retry(failures: number, error: unknown): boolean {
  return failures < 1 && !(error instanceof ApiError && error.status >= 400 && error.status < 500);
}

/** GET /audit-log: the newest rows under the filters (50), not polled; Refresh reads it again. */
export function useAuditPage(query: Record<string, string>) {
  const allowed = useAllowed("audit.view");
  return useQuery({
    queryKey: auditKeys.page(query),
    queryFn: () => authed<AuditPage>("/audit-log", { query }),
    enabled: allowed,
    staleTime: Infinity,
    retry,
  });
}

/** GET /audit-log by cursor: an older page, read once. */
export function olderAuditPage(query: Record<string, string>, cursor: string): Promise<AuditPage> {
  return authed<AuditPage>("/audit-log", { query: { ...query, cursor } });
}

/** GET /audit-log/{id}: the row with before, after and context (redacted by the API on every read). */
export function useAuditEntry(id: number | null) {
  const allowed = useAllowed("audit.view");
  return useQuery({
    queryKey: auditKeys.entry(id ?? 0),
    queryFn: () => authed<AuditDetail>(`/audit-log/${id}`),
    enabled: allowed && id !== null,
    staleTime: Infinity,
    retry,
  });
}

const EXPORT_TYPES = { csv: "text/csv", xlsx: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" } as const;

/** GET /audit-log/export (audit.export, the admin): the filters as a file, oldest first, read with the bearer token. */
export function auditExport(query: Record<string, string>, format: keyof typeof EXPORT_TYPES, locale: string): Promise<Blob> {
  return authedBlob("/audit-log/export", { query: { ...query, format, locale }, accept: EXPORT_TYPES[format], timeoutMs: 120_000 });
}
