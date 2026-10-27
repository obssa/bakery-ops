"use client";

import { useQuery } from "@tanstack/react-query";
import { authed, authedBlob, useSession } from "../session";
import type {
  Collection,
  NewPayment,
  NewPaymentBatch,
  Order,
  OrderDetail,
  OrderPayments,
  PaymentBatchResult,
  PaymentChannel,
  PaymentResult,
  PaymentView,
  ReceiptLink,
  ReceiptSettings,
  StoredFileView,
  VoidBody,
} from "./types";

// The calls of the cashier till and the admin Orders board. Reads run only while signed in; the writes are online only (the cashier needs
// the answer: the change due, the PIN's verdict, the photo's id) and carry the Idempotency-Key the caller made. Step 4.5b adds the full pay
// sheet's calls: the receipt photo upload, the split bill, the receipt (PDF and link) and the void of one payment.

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

/** An upload of a receipt photo may take a while on a slow line (the photo is shrunk first, about 200 to 400 kB). */
const UPLOAD_TIMEOUT_MS = 90_000;

/**
 * POST /files (step 4.2b): the photo as `file`, its purpose and its own client_uuid in one multipart body. A retry of the same photo sends
 * the same client_uuid and Idempotency-Key, so the API answers the stored file (200) instead of keeping a second one.
 */
export function uploadReceiptPhoto(photo: Blob, clientUuid: string, idempotencyKey: string): Promise<StoredFileView> {
  const form = new FormData();
  form.append("purpose", "receipt");
  form.append("client_uuid", clientUuid);
  form.append("file", photo, photo.type === "image/jpeg" || photo.type === "" ? "receipt.jpg" : "receipt");
  return authed<StoredFileView>("/files", { method: "POST", body: form, idempotencyKey, timeoutMs: UPLOAD_TIMEOUT_MS });
}

/** POST /orders/{order}/payment-batches (step 4.3b): every payment of the sheet in one write, all or none. */
export function recordPaymentBatch(orderId: number, body: NewPaymentBatch, idempotencyKey: string): Promise<PaymentBatchResult> {
  return authed<PaymentBatchResult>(`/orders/${orderId}/payment-batches`, { method: "POST", body, idempotencyKey });
}

/** POST /payments/{payment}/void (step 4.3c): one payment of an order not paid yet, with a reason and a manager's PIN. */
export function voidPayment(paymentId: number, body: VoidBody, idempotencyKey: string): Promise<PaymentView> {
  return authed<PaymentView>(`/payments/${paymentId}/void`, { method: "POST", body, idempotencyKey });
}

/** POST /orders/{order}/receipt-link (step 4.3e): a signed link to the customer's receipt, 72 hours by default. */
export function receiptLink(orderId: number, idempotencyKey: string): Promise<ReceiptLink> {
  return authed<ReceiptLink>(`/orders/${orderId}/receipt-link`, { method: "POST", body: {}, idempotencyKey });
}

/** GET /orders/{order}/receipt.pdf (step 4.3e): the receipt in the receipt settings' language. */
export function receiptPdf(orderId: number): Promise<Blob> {
  return authedBlob(`/orders/${orderId}/receipt.pdf`, { timeoutMs: 60_000 });
}

/** GET /orders/{order}/payments (step 4.3b): the order's payments, oldest first, voided ones included, and its balance. */
export function useOrderPayments(orderId: number | null) {
  const enabled = useSession((s) => s.status === "signed-in") && orderId !== null;
  return useQuery({
    queryKey: ["order-payments", orderId],
    queryFn: () => authed<OrderPayments>(`/orders/${orderId}/payments`),
    enabled,
    staleTime: 0,
  });
}

/** GET /receipt-settings (step 4.3e): the business name the receipt preview carries. */
export function useReceiptSettings() {
  const enabled = useSession((s) => s.status === "signed-in");
  return useQuery({
    queryKey: ["receipt-settings"],
    queryFn: () => authed<ReceiptSettings>("/receipt-settings"),
    enabled,
    staleTime: 300_000,
  });
}
