"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { authed, can, useSession } from "../session";
import type {
  ChainPage,
  ChainVerify,
  Collection,
  HandoverNote,
  Me,
  Replay,
  Shift,
  ShiftPage,
  ShiftTemplate,
  StockPlace,
  User,
  VarianceReason,
  WorkStation,
} from "./types";
import { uuid } from "./idempotency";

// The reads and writes of the admin shifts screens (step 3.7c; phase-3 plan section 4). Reads run only while signed in and only for
// the person who may make them (the API decides; the screens just do not ask what would be refused). Every write carries the
// Idempotency-Key its form chose and refreshes the lists it changes.

interface Write<T> {
  body: T;
  idempotencyKey: string;
}

function useAllowed(permission: string): boolean {
  return useSession((s) => s.status === "signed-in" && can(s.me, permission));
}

/** The stations the person works at (every station for stations.all), from GET /me; looked at again every 30 s. */
export function useWorkStations() {
  const enabled = useSession((s) => s.status === "signed-in");
  return useQuery({
    queryKey: ["me-work-stations"],
    queryFn: async (): Promise<WorkStation[]> => (await authed<Me>("/me")).work_stations,
    enabled,
    refetchInterval: 30_000,
  });
}

export function useTemplates() {
  const enabled = useAllowed("shifts.view");
  return useQuery({
    queryKey: ["shift-templates"],
    queryFn: async () => (await authed<Collection<ShiftTemplate>>("/shift-templates")).data,
    enabled,
  });
}

/** Every variance reason, switched-off ones included (the count screens ask for the active ones only). */
export function useAllReasons() {
  const enabled = useAllowed("shifts.view");
  return useQuery({
    queryKey: ["variance-reasons", "all"],
    queryFn: async () => (await authed<Collection<VarianceReason>>("/variance-reasons")).data,
    enabled,
  });
}

/** GET /locations: the stock places (inventory.view). */
export function usePlaces() {
  const enabled = useAllowed("inventory.view");
  return useQuery({
    queryKey: ["locations"],
    queryFn: async () => (await authed<Collection<StockPlace>>("/locations")).data,
    enabled,
  });
}

/** GET /users (people.manage): who can be put on a roster. */
export function useRosterPeople() {
  const enabled = useAllowed("people.manage");
  return useQuery({
    queryKey: ["users"],
    queryFn: async () => (await authed<Collection<User>>("/users")).data,
    enabled,
  });
}

export interface ShiftQuery {
  station_id?: number;
  status?: string;
  from?: string;
  to?: string;
  page?: number;
  per_page?: number;
}

export function useShiftList(query: ShiftQuery, enabled = true) {
  const allowed = useAllowed("shifts.view");
  return useQuery({
    queryKey: ["shifts", "list", query],
    queryFn: () => authed<ShiftPage>("/shifts", { query: { ...query } }),
    enabled: allowed && enabled,
  });
}

export function useShift(id: number | null) {
  const allowed = useAllowed("shifts.view");
  return useQuery({
    queryKey: ["shifts", "one", id],
    queryFn: () => authed<Shift>(`/shifts/${id}`),
    enabled: allowed && id !== null,
  });
}

export function useShiftNotes(shiftId: number | null) {
  const allowed = useAllowed("shifts.view");
  return useQuery({
    queryKey: ["shifts", "notes", shiftId],
    queryFn: async () => (await authed<Collection<HandoverNote>>(`/shifts/${shiftId}/notes`)).data,
    enabled: allowed && shiftId !== null,
  });
}

export function useChain(locationId: number | null) {
  const allowed = useAllowed("shifts.replay");
  return useQuery({
    queryKey: ["chain", locationId],
    queryFn: () => authed<ChainPage>(`/locations/${locationId}/chain`, { query: { limit: 50 } }),
    enabled: allowed && locationId !== null,
  });
}

/** Verifies the whole chain of a place (GET, read-only); run on demand by the "Verify" button, never on a timer. */
export function useVerifyChain() {
  return useMutation({
    mutationFn: (locationId: number) => authed<ChainVerify>(`/locations/${locationId}/chain/verify`),
  });
}

export function useReplay(input: { stationId: number; at: string } | null) {
  const allowed = useAllowed("shifts.replay");
  return useQuery({
    queryKey: ["replay", input],
    queryFn: () => authed<Replay>("/replay", { query: { station_id: input!.stationId, at: input!.at, nonzero: 0 } }),
    enabled: allowed && input !== null,
    retry: false,
  });
}

// --- writes ---------------------------------------------------------------------------------------------------------------------

export interface TemplateBody {
  name_en: string;
  name_am: string;
  start_time: string;
  end_time: string;
  days_of_week: number[];
  station_ids: number[];
  is_active: boolean;
}

export function useSaveTemplate() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: ({ id, body, idempotencyKey }: Write<TemplateBody> & { id: number | null }) =>
      id === null
        ? authed<ShiftTemplate>("/shift-templates", { method: "POST", body, idempotencyKey })
        : authed<ShiftTemplate>(`/shift-templates/${id}`, { method: "PATCH", body, idempotencyKey }),
    onSuccess: () => client.invalidateQueries({ queryKey: ["shift-templates"] }),
  });
}

/** Retiring a template is a soft delete: shifts already made from it keep their own window. */
export function useRetireTemplate() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: ({ id, idempotencyKey }: { id: number; idempotencyKey: string }) =>
      authed<ShiftTemplate>(`/shift-templates/${id}`, { method: "DELETE", idempotencyKey }),
    onSuccess: () => client.invalidateQueries({ queryKey: ["shift-templates"] }),
  });
}

export interface ReasonBody {
  code?: string;
  name_en: string;
  name_am: string;
  requires_note?: boolean;
  is_active?: boolean;
}

export function useSaveReason() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: ({ id, body, idempotencyKey }: Write<ReasonBody> & { id: number | null }) =>
      id === null
        ? authed<VarianceReason>("/variance-reasons", { method: "POST", body, idempotencyKey })
        : authed<VarianceReason>(`/variance-reasons/${id}`, { method: "PATCH", body, idempotencyKey }),
    onSuccess: () => client.invalidateQueries({ queryKey: ["variance-reasons"] }),
  });
}

function refreshShifts(client: ReturnType<typeof useQueryClient>) {
  return client.invalidateQueries({ queryKey: ["shifts"] });
}

/** Schedules a template at a station for a local date with people on the roster; the client_uuid is the Idempotency-Key. */
export function useScheduleShift() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: ({ key, stationId, templateId, date, userIds }: { key: string; stationId: number; templateId: number; date: string; userIds: number[] }) =>
      authed<Shift>("/shifts", {
        method: "POST",
        body: { client_uuid: key, station_id: stationId, template_id: templateId, date, user_ids: userIds },
        idempotencyKey: key,
      }),
    onSuccess: () => refreshShifts(client),
  });
}

export function useReplaceRoster() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: ({ shiftId, userIds, key }: { shiftId: number; userIds: number[]; key: string }) =>
      authed<Shift>(`/shifts/${shiftId}/roster`, { method: "PUT", body: { user_ids: userIds }, idempotencyKey: key }),
    onSuccess: () => refreshShifts(client),
  });
}

export function useCancelScheduled() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: ({ shiftId, key }: { shiftId: number; key: string }) => authed<Shift>(`/shifts/${shiftId}/cancel`, { method: "POST", body: {}, idempotencyKey: key }),
    onSuccess: () => refreshShifts(client),
  });
}

/**
 * "Start now" for an admin: starts the scheduled instance outside its window (force). The start's client_uuid is the Idempotency-Key, so a
 * retry answers the shift already made. `carry` opens it without counting.
 */
export function useStartNow() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: ({ stationId, shiftId, carry, key }: { stationId: number; shiftId: number; carry: boolean; key: string }) =>
      authed<{ shift: Shift }>(`/stations/${stationId}/shifts/start`, {
        method: "POST",
        body: { client_uuid: key, shift_id: shiftId, carry, force: true },
        idempotencyKey: key,
      }),
    onSuccess: () => Promise.all([refreshShifts(client), client.invalidateQueries({ queryKey: ["me-work-stations"] })]),
  });
}

export { uuid };
