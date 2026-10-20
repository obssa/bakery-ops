"use client";

import { useQuery } from "@tanstack/react-query";
import { authed, useSession } from "../session";
import type { Collection, NewPayment, Order, OrderDetail, PaymentChannel, PaymentResult, VoidBody } from "./types";

// The calls of the cashier till and the admin Orders board. Reads run only while signed in; the two writes are online
// only (the cashier needs the answer: the change due, the PIN's verdict) and carry the Idempotency-Key the caller made.

/** The branch's payment channels in their order; the till shows the enabled ones. */
export function usePaymentChannels() {
  const enabled = useSession((s) => s.status === "signed-in");
  return useQuery({
    queryKey: ["payment-channels"],
    queryFn: async () => (await authed<Collection<PaymentChannel>>("/payment-channels")).data,
    enabled,
    staleTime: 60_000,
  });
}

export function recordPayment(orderId: number, body: NewPayment, idempotencyKey: string): Promise<PaymentResult> {
  return authed<PaymentResult>(`/orders/${orderId}/payments`, { method: "POST", body, idempotencyKey });
}

export function voidOrder(orderId: number, body: VoidBody, idempotencyKey: string): Promise<Order> {
  return authed<Order>(`/orders/${orderId}/void`, { method: "POST", body, idempotencyKey });
}

/** One order with its timeline, kept fresh while it is open on screen. */
export function useOrderDetail(orderId: number | null, version: number | null) {
  const enabled = useSession((s) => s.status === "signed-in") && orderId !== null;
  return useQuery({
    // the feed's version of the order is part of the key: a change seen by the feed reads the timeline again
    queryKey: ["order", orderId, version],
    queryFn: () => authed<OrderDetail>(`/orders/${orderId}`),
    enabled,
  });
}
