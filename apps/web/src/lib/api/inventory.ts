"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { authed, useSession } from "../session";
import type { Collection, DecimalString } from "./types";

// The calls of the admin Inventory screens (Phase 2, step 2.8b): stock by location, the ledger, suppliers, purchases,
// transfers, reorder rules and the reorder list, and the active stock alerts. Reads load on open and after each write
// (no polling: the plan asks for none here). Money and quantities are decimal strings and stay that way. Every write
// is online and carries the Idempotency-Key its form chose (useIdempotencyKey), reused on a retry.

function useSignedIn(): boolean {
  return useSession((s) => s.status === "signed-in");
}

/** The bilingual name of a place or an item. */
export interface Named {
  name_en: string;
  name_am: string;
}

export interface LocationRow extends Named {
  id: number;
  type: string;
  station_id: number | null;
}

export interface StockLine extends Named {
  item_id: number;
  location_id: number;
  base_unit_code: string;
  on_hand: DecimalString;
  reserved: DecimalString;
  available: DecimalString;
}

export interface Movement {
  id: number;
  item_id: number;
  location_id: number | null;
  from_location_id: number | null;
  to_location_id: number | null;
  qty: DecimalString;
  reason: string;
  on_hand_delta: DecimalString;
  reserved_delta: DecimalString;
  ref_type: string | null;
  ref_id: number | null;
  created_at: string;
}

export interface Page<T> {
  data: T[];
  meta: { current_page: number; last_page: number; total: number; per_page: number };
}

export interface Supplier {
  id: number;
  name: string;
  phone: string | null;
  tin: string | null;
  notes: string | null;
  is_active: boolean;
}

export type PurchaseStatus = "draft" | "confirmed" | "cancelled";

export interface PurchaseLine {
  id: number;
  client_uuid: string;
  item: Named & { id: number };
  qty: DecimalString;
  unit: { id: number; code: string };
  qty_base: DecimalString;
  /** only for those who may see costs (costs.view): the API leaves them out for everyone else */
  unit_cost?: DecimalString;
  unit_cost_base?: DecimalString;
  line_total?: DecimalString;
}

export interface Purchase {
  id: number;
  client_uuid: string;
  supplier: { id: number; name: string };
  invoice_no: string | null;
  purchase_date: string;
  location: Named & { id: number };
  status: PurchaseStatus;
  currency: string;
  total?: DecimalString;
  lines?: PurchaseLine[];
  confirmed_at: string | null;
  confirmed_by: { id: number; name: string } | null;
  cancelled_at: string | null;
  cancel_reason: string | null;
  notes: string | null;
  version: number;
  /** the confirm answer only, and only with costs.view */
  costs?: { item_id: number; cost_before: DecimalString | null; cost_after: DecimalString }[];
}

export interface NewPurchaseLine {
  client_uuid: string;
  item_id: number;
  qty: DecimalString;
  unit_id: number;
  unit_cost: DecimalString;
}

export interface NewPurchase {
  client_uuid: string;
  supplier_id: number;
  invoice_no?: string;
  purchase_date: string;
  location_id?: number;
  notes?: string;
  allow_duplicate_invoice?: boolean;
  lines: NewPurchaseLine[];
}

export type TransferStatus = "requested" | "confirmed" | "rejected";

export interface Transfer {
  id: number;
  item: Named & { id: number; unit: string };
  from_location: Named & { id: number };
  to_location: Named & { id: number };
  qty: DecimalString;
  status: TransferStatus;
  note: string | null;
  reject_reason: string | null;
  requested_by: { id: number; name: string };
  requested_at: string;
  decided_by: { id: number; name: string } | null;
  decided_at: string | null;
  production_request_id: number | null;
  /** requested rows only */
  source_available?: DecimalString;
}

export interface ReorderRule {
  id: number;
  item: Named & { id: number; unit: string };
  location: (Named & { id: number; type: string }) | null;
  min_qty: DecimalString;
  reorder_qty: DecimalString;
  notify_roles: string[] | null;
  is_active: boolean;
  version: number;
}

export interface NewReorderRule {
  item_id: number;
  location_id?: number | null;
  min_qty: DecimalString;
  reorder_qty: DecimalString;
  notify_roles?: string[];
}

export interface ReorderRow {
  item: Named & { id: number; unit: string };
  location: (Named & { id: number; type: string }) | null;
  available: DecimalString;
  min_qty: DecimalString;
  reorder_qty: DecimalString;
  consumption_7d: DecimalString;
  days_of_cover: DecimalString | null;
  suggested_qty: DecimalString;
  state: "low" | "out";
}

export interface StockAlert {
  id: number;
  type: "low" | "out";
  item: (Named & { id: number; unit: string }) | null;
  location: (Named & { id: number; type: string }) | null;
  active_since: string;
  available_at_start: DecimalString;
  threshold_qty: DecimalString | null;
}

export interface LedgerFilters {
  item: number | null;
  location: number | null;
  from: string;
  to: string;
  page: number;
}

export const LEDGER_PAGE_SIZE = 50;

/** All inventory reads share one key prefix, so a write refreshes every screen that may show its effect. */
const INV = ["inv"] as const;

export function useLocations() {
  const enabled = useSignedIn();
  return useQuery({
    queryKey: ["locations"],
    queryFn: async () => (await authed<Collection<LocationRow>>("/locations")).data,
    enabled,
    staleTime: 60_000,
  });
}

export function useStock(locationId: number | null) {
  const enabled = useSignedIn() && locationId !== null;
  return useQuery({
    queryKey: [...INV, "stock", locationId],
    queryFn: async () => (await authed<Collection<StockLine>>("/stock", { query: { location: locationId } })).data,
    enabled,
  });
}

export function useStockAlerts() {
  const enabled = useSignedIn();
  return useQuery({
    queryKey: [...INV, "alerts"],
    queryFn: async () => (await authed<Collection<StockAlert>>("/stock-alerts", { query: { active: 1 } })).data,
    enabled,
  });
}

export function useLedger(filters: LedgerFilters) {
  const enabled = useSignedIn();
  return useQuery({
    queryKey: [...INV, "ledger", filters],
    queryFn: () =>
      authed<Page<Movement>>("/ledger", {
        query: { item: filters.item, location: filters.location, from: filters.from, to: filters.to, page: filters.page, per_page: LEDGER_PAGE_SIZE },
      }),
    enabled,
    placeholderData: keepPreviousData,
  });
}

export function useSuppliers() {
  const enabled = useSignedIn();
  return useQuery({
    queryKey: [...INV, "suppliers"],
    queryFn: async () => (await authed<Collection<Supplier>>("/suppliers")).data,
    enabled,
  });
}

export function usePurchases(status: PurchaseStatus | null, page: number) {
  const enabled = useSignedIn();
  return useQuery({
    queryKey: [...INV, "purchases", status, page],
    queryFn: () => authed<Page<Purchase>>("/purchases", { query: { status, page, per_page: 25 } }),
    enabled,
    placeholderData: keepPreviousData,
  });
}

export function usePurchase(id: number | null) {
  const enabled = useSignedIn() && id !== null;
  return useQuery({
    queryKey: [...INV, "purchase", id],
    queryFn: () => authed<Purchase>(`/purchases/${id}`),
    enabled,
  });
}

export function useTransfers(status: TransferStatus | null) {
  const enabled = useSignedIn();
  return useQuery({
    queryKey: [...INV, "transfers", status],
    queryFn: () => authed<Page<Transfer>>("/transfers", { query: { status, per_page: 100 } }),
    enabled,
  });
}

export function useReorderRules() {
  const enabled = useSignedIn();
  return useQuery({
    queryKey: [...INV, "reorder-rules"],
    queryFn: async () => (await authed<Collection<ReorderRule>>("/reorder-rules")).data,
    enabled,
  });
}

export function useReorderList() {
  const enabled = useSignedIn();
  return useQuery({
    queryKey: [...INV, "reorder-list"],
    queryFn: async () => (await authed<Collection<ReorderRow> & { generated_at: string }>("/reorder-list")).data,
    enabled,
  });
}

/** A write that refreshes every inventory read once it succeeds. */
function useInventoryWrite<V, R>(send: (variables: V) => Promise<R>) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: send,
    onSuccess: () => client.invalidateQueries({ queryKey: INV }),
  });
}

interface Keyed<T> {
  body: T;
  idempotencyKey: string;
}

export function useCreateSupplier() {
  return useInventoryWrite(({ body, idempotencyKey }: Keyed<{ name: string; phone?: string; tin?: string; notes?: string }>) =>
    authed<Supplier>("/suppliers", { method: "POST", body, idempotencyKey }),
  );
}

export function useUpdateSupplier() {
  return useInventoryWrite(({ id, body, idempotencyKey }: Keyed<Partial<Omit<Supplier, "id">>> & { id: number }) =>
    authed<Supplier>(`/suppliers/${id}`, { method: "PATCH", body, idempotencyKey }),
  );
}

export function useSavePurchase() {
  return useInventoryWrite(({ body, idempotencyKey }: Keyed<NewPurchase>) => authed<Purchase>("/purchases", { method: "POST", body, idempotencyKey }));
}

export function useReplacePurchaseLines() {
  return useInventoryWrite(({ id, body, idempotencyKey }: Keyed<{ lines: NewPurchaseLine[] }> & { id: number }) =>
    authed<Purchase>(`/purchases/${id}/lines`, { method: "PUT", body, idempotencyKey }),
  );
}

export function useConfirmPurchase() {
  return useInventoryWrite(({ id, idempotencyKey }: { id: number; idempotencyKey: string }) =>
    authed<Purchase>(`/purchases/${id}/confirm`, { method: "POST", body: {}, idempotencyKey }),
  );
}

export function useCancelPurchase() {
  return useInventoryWrite(({ id, reason, idempotencyKey }: { id: number; reason: string; idempotencyKey: string }) =>
    authed<Purchase>(`/purchases/${id}/cancel`, { method: "POST", body: reason ? { reason } : {}, idempotencyKey }),
  );
}

export function useConfirmTransfer() {
  return useInventoryWrite(({ id, idempotencyKey }: { id: number; idempotencyKey: string }) =>
    authed<Transfer>(`/transfers/${id}/confirm`, { method: "POST", body: {}, idempotencyKey }),
  );
}

export function useRejectTransfer() {
  return useInventoryWrite(({ id, reason, idempotencyKey }: { id: number; reason: string; idempotencyKey: string }) =>
    authed<Transfer>(`/transfers/${id}/reject`, { method: "POST", body: { reason }, idempotencyKey }),
  );
}

export function useCreateReorderRule() {
  return useInventoryWrite(({ body, idempotencyKey }: Keyed<NewReorderRule>) => authed<ReorderRule>("/reorder-rules", { method: "POST", body, idempotencyKey }));
}

export function useUpdateReorderRule() {
  return useInventoryWrite(({ id, body, idempotencyKey }: Keyed<{ min_qty?: DecimalString; reorder_qty?: DecimalString; notify_roles?: string[]; is_active?: boolean }> & { id: number }) =>
    authed<ReorderRule>(`/reorder-rules/${id}`, { method: "PATCH", body, idempotencyKey }),
  );
}

export function useDeleteReorderRule() {
  return useInventoryWrite(({ id, idempotencyKey }: { id: number; idempotencyKey: string }) =>
    authed<ReorderRule>(`/reorder-rules/${id}`, { method: "DELETE", idempotencyKey }),
  );
}
