"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { authed, useSession } from "../session";
import type { Collection, DiningTable } from "./types";

// The calls of Admin > Tables (Phase 6 follow-up): the dining tables the pads show, read and written by tables.manage (the admin and the
// manager). The list is GET /dining-tables/manage, every table of the branch with the switched-off ones, in the pads' order (sort, then
// code), without the station device check (a back-office computer is not a pad). Every write is online, carries the Idempotency-Key its
// form chose, and afterwards invalidates every query under ["dining-tables"], so this list and an open pad's grid (TablesPanel's useTables)
// both read the change. A table is never deleted: it is switched off (is_active), and its code never changes once it is made.

/** Every query that reads the tables starts with this key: the pad's grid and this screen's list. */
export const TABLES_KEY = ["dining-tables"] as const;

export interface NewTable {
  code: string;
  name_en: string;
  name_am: string;
  seats?: number | null;
  sort?: number;
}

export type TableChanges = Partial<Pick<DiningTable, "name_en" | "name_am" | "seats" | "sort" | "is_active">>;

export function useManagedTables() {
  const enabled = useSession((s) => s.status === "signed-in");
  return useQuery({
    queryKey: [...TABLES_KEY, "manage"],
    queryFn: async () => (await authed<Collection<DiningTable>>("/dining-tables/manage")).data,
    enabled,
    // the state chips follow the floor, as the pad's grid does
    refetchInterval: 10_000,
  });
}

/** POST /dining-tables: one table. The caller invalidates (useTablesWritten) once its writes are done, so a range is read back once. */
export function createTable(body: NewTable, idempotencyKey: string): Promise<DiningTable> {
  return authed<DiningTable>("/dining-tables", { method: "POST", body, idempotencyKey });
}

/** Reads the tables again everywhere: this list and any pad's grid. */
export function useTablesWritten(): () => Promise<void> {
  const client = useQueryClient();
  return () => client.invalidateQueries({ queryKey: TABLES_KEY });
}

export function useCreateTable() {
  const written = useTablesWritten();
  return useMutation({
    mutationFn: ({ body, idempotencyKey }: { body: NewTable; idempotencyKey: string }) => createTable(body, idempotencyKey),
    onSuccess: () => written(),
  });
}

export function useUpdateTable() {
  const written = useTablesWritten();
  return useMutation({
    mutationFn: ({ id, body, idempotencyKey }: { id: number; body: TableChanges; idempotencyKey: string }) =>
      authed<DiningTable>(`/dining-tables/${id}`, { method: "PATCH", body, idempotencyKey }),
    onSuccess: () => written(),
  });
}
