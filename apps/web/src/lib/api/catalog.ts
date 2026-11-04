"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { authed, can, useSession } from "../session";
import type {
  Collection,
  Item,
  ItemChanges,
  ItemImageAnswer,
  ItemPrice,
  Menu,
  NewScheduledRequest,
  ProductImageDetail,
  ProductionBoardPage,
  ProductionRequest,
  Recipe,
  RecipeCost,
  RecipeSpec,
  RecipeVersionRow,
  Station,
  User,
} from "./types";
import { upload } from "./upload";

// The calls of the admin Catalog item page, the planner and the station switch (Phase 2, step 2.8d). Reads run only while
// signed in; every write carries the Idempotency-Key its form chose and refreshes what it changes.

function useSignedIn(): boolean {
  return useSession((s) => s.status === "signed-in");
}

export function useItem(id: number | null) {
  const enabled = useSignedIn() && id !== null;
  return useQuery({ queryKey: ["item", id], queryFn: () => authed<Item>(`/items/${id}`), enabled });
}

/** Everything that shows a product reads again after it changed: its page, every list of items (the catalog, the thumbs) and the menu. */
function refreshProduct(client: ReturnType<typeof useQueryClient>, id: number): void {
  void client.invalidateQueries({ queryKey: ["items"] });
  void client.invalidateQueries({ queryKey: ["item-prices", id] });
  void client.invalidateQueries({ queryKey: ["menu", "product-page"] });
}

/**
 * The product page's "Save changes" (step 6.4b): PATCH /items/{item} with the fields that changed (names, category, fulfilment, the two
 * refund settings), then POST /items/{item}/prices when the price changed (a new price starts on the next order). Each write has its own
 * Idempotency-Key, chosen by the page for its payload, so a retry after a time-out replays instead of writing twice.
 */
export function useSaveProduct(id: number) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: async ({ changes, changesKey, price, priceKey }: { changes: ItemChanges | null; changesKey: string; price: string | null; priceKey: string }) => {
      const item = changes ? await authed<Item>(`/items/${id}`, { method: "PATCH", body: changes, idempotencyKey: changesKey }) : null;
      if (item) client.setQueryData(["item", id], item);
      if (price !== null) {
        await authed<ItemPrice>(`/items/${id}/prices`, { method: "POST", body: { price }, idempotencyKey: priceKey });
        // the field shows the new current price as soon as the page's edits are let go
        await client.invalidateQueries({ queryKey: ["item-prices", id] });
      }
      return item;
    },
    onSettled: () => {
      void client.invalidateQueries({ queryKey: ["item", id] });
      refreshProduct(client, id);
    },
  });
}

/** "Take off the menu" and "Put back on the menu": PATCH /items/{item} with is_active (step 6.4b). */
export function useSetOnMenu(id: number) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: ({ active, idempotencyKey }: { active: boolean; idempotencyKey: string }) => authed<Item>(`/items/${id}`, { method: "PATCH", body: { is_active: active }, idempotencyKey }),
    onSuccess: (item) => {
      client.setQueryData(["item", id], item);
      refreshProduct(client, id);
    },
  });
}

/** The item as the page holds it, with its photo replaced (the upload's and the remove's answers carry the photo, not the item). */
function withImage(client: ReturnType<typeof useQueryClient>, id: number, image: ProductImageDetail | null): void {
  client.setQueryData<Item>(["item", id], (item) => (item ? { ...item, image } : item));
}

/**
 * POST /items/{item}/image (step 6.1a, multipart: `photo`, the four crop fields, `client_uuid`) through lib/api/upload.ts, for its
 * progress. The page's item takes the new photo from the answer; the lists and the menu are read again.
 */
export function useUploadProductPhoto(id: number) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: ({ form, idempotencyKey, onProgress }: { form: FormData; idempotencyKey: string; onProgress?: (sent: number) => void }) =>
      upload<ItemImageAnswer>(`/items/${id}/image`, { form, idempotencyKey, onProgress }),
    onSuccess: (answer) => {
      withImage(client, id, answer.data.image);
      refreshProduct(client, id);
    },
  });
}

/** DELETE /items/{item}/image: the pad shows the category's placeholder again. */
export function useRemoveProductPhoto(id: number) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: ({ idempotencyKey }: { idempotencyKey: string }) => authed<ItemImageAnswer>(`/items/${id}/image`, { method: "DELETE", idempotencyKey }),
    onSuccess: () => {
      withImage(client, id, null);
      refreshProduct(client, id);
    },
  });
}

/**
 * The menu as the pad reads it (GET /menu with the unavailable products), for the product page's stock chip and its live pad-card preview
 * (step 6.4b): only for someone who may read it (orders.create), read once and kept for 15 s. Without it (no permission, or a computer
 * that is not an enrolled device) the preview shows the product from its own fields, without a stock chip.
 */
export function useProductPageMenu() {
  const me = useSession((s) => s.me);
  const enabled = useSignedIn() && can(me, "orders.create");
  return useQuery({
    queryKey: ["menu", "product-page"],
    // the menu asks for an enrolled device; on a computer that is not one the page goes on without the chip, never to the gate's screen
    queryFn: () => authed<Menu>("/menu", { query: { include_unavailable: 1 }, deviceGate: false }),
    enabled,
    staleTime: 15_000,
    retry: false,
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
