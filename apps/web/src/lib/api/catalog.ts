"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { authed, can, useSession } from "../session";
import type { Collection, Item, ItemPrice, ItemRefundSettings, NewScheduledRequest, ProductionBoardPage, ProductionRequest, Recipe, RecipeCost, RecipeSpec, RecipeVersionRow, Station, User } from "./types";

// The calls of the admin Catalog item page, the planner and the station switch (Phase 2, step 2.8d). Reads run only while
// signed in; every write carries the Idempotency-Key its form chose and refreshes what it changes.

function useSignedIn(): boolean {
  return useSession((s) => s.status === "signed-in");
}

export function useItem(id: number | null) {
  const enabled = useSignedIn() && id !== null;
  return useQuery({ queryKey: ["item", id], queryFn: () => authed<Item>(`/items/${id}`), enabled });
}

/** PATCH /items/{item} with the two refund settings (step 4.5g); the item and every list of items are read again. */
export function useSaveRefundSettings(id: number) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: ({ body, idempotencyKey }: { body: ItemRefundSettings; idempotencyKey: string }) => authed<Item>(`/items/${id}`, { method: "PATCH", body, idempotencyKey }),
    onSuccess: (item) => {
      client.setQueryData(["item", id], item);
      void client.invalidateQueries({ queryKey: ["items"] });
    },
  });
}

export function useItemPrices(id: number | null) {
  const enabled = useSignedIn() && id !== null;
  return useQuery({ queryKey: ["item-prices", id], queryFn: async () => (await authed<Collection<ItemPrice>>(`/items/${id}/prices`)).data, enabled });
}

export function useRecipeVersions(id: number | null) {
  const enabled = useSignedIn() && id !== null;
  return useQuery({ queryKey: ["recipe-versions", id], queryFn: async () => (await authed<Collection<RecipeVersionRow>>(`/items/${id}/recipe/versions`)).data, enabled });
}

export function useRecipeVersion(id: number | null, version: number | null) {
  const enabled = useSignedIn() && id !== null && version !== null;
  return useQuery({ queryKey: ["recipe-version", id, version], queryFn: () => authed<Recipe>(`/items/${id}/recipe/versions/${version}`), enabled });
}

/** The cost roll-up of one version. Only for people holding costs.view: anyone else gets a 403 for the whole body, so it is not asked. */
export function useRecipeCost(id: number | null, version: number | null) {
  const me = useSession((s) => s.me);
  const enabled = useSignedIn() && id !== null && version !== null && can(me, "costs.view");
  return useQuery({
    queryKey: ["recipe-cost", id, version],
    queryFn: () => authed<RecipeCost>(`/inventory/recipe-costs/${id}`, { query: { version } }),
    enabled,
  });
}

interface Write<T> {
  body: T;
  idempotencyKey: string;
}

/** POST /items/{item}/recipe/versions, or PUT /items/{item}/recipe for the first version of a product that has none. */
export function usePublishRecipe(id: number) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: ({ body, idempotencyKey, first }: Write<RecipeSpec> & { first: boolean }) =>
      first ? authed<Recipe>(`/items/${id}/recipe`, { method: "PUT", body, idempotencyKey }) : authed<Recipe>(`/items/${id}/recipe/versions`, { method: "POST", body, idempotencyKey }),
    // a refusal (a publish that lost a race) is read again too: the history on screen may be out of date
    onSettled: () => {
      void client.invalidateQueries({ queryKey: ["recipe-versions", id] });
      void client.invalidateQueries({ queryKey: ["recipe-version", id] });
      void client.invalidateQueries({ queryKey: ["recipe-cost", id] });
    },
  });
}

export function useStations() {
  const me = useSession((s) => s.me);
  const enabled = useSignedIn() && can(me, "people.manage");
  return useQuery({ queryKey: ["stations"], queryFn: async () => (await authed<Collection<Station>>("/stations")).data, enabled });
}

/** PATCH /stations/{station}: the requires_overseer switch. */
export function usePatchStation() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: ({ id, body, idempotencyKey }: Write<{ requires_overseer: boolean }> & { id: number }) =>
      authed<Station>(`/stations/${id}`, { method: "PATCH", body, idempotencyKey }),
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: ["stations"] });
      void client.invalidateQueries({ queryKey: ["stations-of-board"] });
    },
  });
}

/** The open requests of the planner: the board's answer without what is already done. Read again every 10 s while the page is open. */
export function usePlannerRequests() {
  const enabled = useSignedIn();
  return useQuery({
    queryKey: ["planner-requests"],
    queryFn: async () => {
      const page = await authed<ProductionBoardPage>("/production/requests", { query: { view: "board", limit: 200 } });
      return page.data.filter((request) => request.status !== "done" && request.status !== "cancelled");
    },
    enabled,
    refetchInterval: 10_000,
  });
}

export function useScheduleRequest() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: ({ body, idempotencyKey }: Write<NewScheduledRequest>) => authed<ProductionRequest>("/production/requests", { method: "POST", body, idempotencyKey }),
    onSuccess: () => client.invalidateQueries({ queryKey: ["planner-requests"] }),
  });
}

export function useAssignRequest() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: ({ id, userId, idempotencyKey }: { id: number; userId: number; idempotencyKey: string }) =>
      authed<ProductionRequest>(`/production/requests/${id}/assign`, { method: "POST", body: { user_id: userId }, idempotencyKey }),
    onSuccess: () => client.invalidateQueries({ queryKey: ["planner-requests"] }),
  });
}

/** The people who can be named as a baker: GET /users needs people.manage, so a planner without it assigns nobody here. */
export function useBakers() {
  const me = useSession((s) => s.me);
  const enabled = useSignedIn() && can(me, "people.manage");
  return useQuery({ queryKey: ["users"], queryFn: async () => (await authed<Collection<User>>("/users")).data, enabled });
}
