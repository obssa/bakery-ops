"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { authed, useSession } from "../session";
import type { Category, Collection, Item, ItemKind, NewCategory, NewItem, NewUser, Unit, User } from "./types";

// React Query hooks over the Phase 0 endpoints (Catalog and Identity). Reads run only while signed in; every write
// carries the Idempotency-Key its form chose (useIdempotencyKey) and refreshes the lists it changes.

function useSignedIn(): boolean {
  return useSession((s) => s.status === "signed-in");
}

export function useUnits() {
  const enabled = useSignedIn();
  return useQuery({
    queryKey: ["units"],
    queryFn: async () => (await authed<Collection<Unit>>("/units")).data,
    enabled,
    staleTime: 5 * 60_000,
  });
}

export function useCategories() {
  const enabled = useSignedIn();
  return useQuery({
    queryKey: ["categories"],
    queryFn: async () => (await authed<Collection<Category>>("/categories")).data,
    enabled,
  });
}

export function useItems(kind: ItemKind | null) {
  const enabled = useSignedIn();
  return useQuery({
    queryKey: ["items", kind ?? "all"],
    queryFn: async () => (await authed<Collection<Item>>("/items", { query: { kind: kind ?? undefined } })).data,
    enabled,
  });
}

export function useUsers() {
  const enabled = useSignedIn();
  return useQuery({
    queryKey: ["users"],
    queryFn: async () => (await authed<Collection<User>>("/users")).data,
    enabled,
  });
}

interface Write<T> {
  body: T;
  idempotencyKey: string;
}

export function useCreateItem() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: ({ body, idempotencyKey }: Write<NewItem>) => authed<Item>("/items", { method: "POST", body, idempotencyKey }),
    onSuccess: () => client.invalidateQueries({ queryKey: ["items"] }),
  });
}

export function useCreateCategory() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: ({ body, idempotencyKey }: Write<NewCategory>) =>
      authed<Category>("/categories", { method: "POST", body, idempotencyKey }),
    onSuccess: () => client.invalidateQueries({ queryKey: ["categories"] }),
  });
}

export function useCreateUser() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: ({ body, idempotencyKey }: Write<NewUser>) => authed<User>("/users", { method: "POST", body, idempotencyKey }),
    onSuccess: () => client.invalidateQueries({ queryKey: ["users"] }),
  });
}
