"use client";

import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { authed, useSession } from "../session";
import type { BatchAnswer, NewTransfer, ProductionCard, ProductionRequest } from "./types";

// The calls of the production board (Phase 2, step 2.8a). The board itself is the LiveFeed of lib/live/production.ts;
// these are the card behind a request and the writes. Every write is online (the baker needs the answer: the shortage,
// the refusal of a sign-off) and carries the Idempotency-Key its form chose, reused on a retry.

/**
 * One request with its scaled recipe, kept fresh while it is open: the versions of the request and of its batch are part
 * of the key, so a change the board sees reads the card again, and the old card stays on screen until the new one arrives.
 */
export function useProductionCard(requestId: number | null, version: number, batchVersion: number) {
  const enabled = useSession((s) => s.status === "signed-in") && requestId !== null;
  return useQuery({
    queryKey: ["production-card", requestId, version, batchVersion],
    queryFn: () => authed<ProductionCard>(`/production/requests/${requestId}`),
    enabled,
    placeholderData: keepPreviousData,
  });
}

export function acceptRequest(id: number, idempotencyKey: string): Promise<ProductionRequest> {
  return authed<ProductionRequest>(`/production/requests/${id}/accept`, { method: "POST", body: {}, idempotencyKey });
}

export function startBatch(requestId: number, clientUuid: string, idempotencyKey: string): Promise<ProductionRequest> {
  return authed<ProductionRequest>(`/production/requests/${requestId}/start`, { method: "POST", body: { client_uuid: clientUuid }, idempotencyKey });
}

export function finishBatch(batchId: number, body: { produced_qty: string; waste_qty: string }, idempotencyKey: string): Promise<BatchAnswer> {
  return authed<BatchAnswer>(`/production/batches/${batchId}/done`, { method: "POST", body, idempotencyKey });
}

export function confirmBatch(batchId: number, idempotencyKey: string): Promise<BatchAnswer> {
  return authed<BatchAnswer>(`/production/batches/${batchId}/confirm`, { method: "POST", body: {}, idempotencyKey });
}

export function sendBackBatch(batchId: number, note: string, idempotencyKey: string): Promise<BatchAnswer> {
  return authed<BatchAnswer>(`/production/batches/${batchId}/send-back`, { method: "POST", body: { note }, idempotencyKey });
}

export function cancelRequest(id: number, reason: string, idempotencyKey: string): Promise<ProductionRequest> {
  return authed<ProductionRequest>(`/production/requests/${id}/cancel`, { method: "POST", body: reason ? { reason } : {}, idempotencyKey });
}

/** POST /transfers: the request sheet (kitchen strip, production card) asks the store for an ingredient. */
export function requestTransfer(body: NewTransfer, idempotencyKey: string): Promise<{ id: number; status: string }> {
  return authed<{ id: number; status: string }>("/transfers", { method: "POST", body, idempotencyKey });
}

/** POST /production/requests: the till asks for a batch of a product by hand (qty left out: the product's batch size). */
export function requestProduction(body: { client_uuid: string; product_item_id: number }, idempotencyKey: string): Promise<ProductionRequest> {
  return authed<ProductionRequest>("/production/requests", { method: "POST", body, idempotencyKey });
}
