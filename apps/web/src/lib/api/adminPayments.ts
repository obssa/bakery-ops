"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { authed, authedBlob, can, useSession } from "../session";
import type {
  Collection,
  NewPaymentChannel,
  PaymentChannel,
  PaymentChannelChanges,
  PaymentDetail,
  PaymentsPage,
  ReceiptSettings,
  ReceiptSettingsInput,
  SignedFileUrl,
} from "./types";

// The reads and writes of the admin payments screens (step 4.5c; phase-4 plan sections 4.2, 4.3, 4.4 and 4.5). Reads run only while signed in
// and only for a person who may make them (the API decides; the screens just do not ask what would be refused). Every write carries the
// Idempotency-Key its screen chose (a new one after a refusal, the same one when the outcome is unknown, as the till does since 4.5b) and
// refreshes what it changes. Query keys are one per answer shape: ["payment-channels"] stays the till's live list of usePaymentChannels,
// ["payment-channels", "all"] is this screen's list with the removed ones, ["payment", id] one payment's detail, ["receipt-settings"] the
// settings (the same shape the till's preview reads). The polled list and the reconciliation are feeds (lib/live/payments.ts), not queries.

function useAllowed(permission: string): boolean {
  return useSession((s) => s.status === "signed-in" && can(s.me, permission));
}

/** GET /payment-channels?all=1 (channels.manage): every channel, the removed ones after the live ones. */
export function useAllChannels(enabled = true) {
  const allowed = useAllowed("channels.manage");
  return useQuery({
    queryKey: ["payment-channels", "all"],
    queryFn: async () => (await authed<Collection<PaymentChannel>>("/payment-channels", { query: { all: 1 } })).data,
    enabled: allowed && enabled,
  });
}

/** A write's answer is a channel or the whole list: both payment-channels lists are read again (the till's live one too). */
function useChannelWrite<A, R>(write: (args: A) => Promise<R>) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: write,
    onSettled: () => client.invalidateQueries({ queryKey: ["payment-channels"] }),
  });
}

export function useCreateChannel() {
  return useChannelWrite(({ body, idempotencyKey }: { body: NewPaymentChannel; idempotencyKey: string }) =>
    authed<PaymentChannel>("/payment-channels", { method: "POST", body, idempotencyKey }),
  );
}

export function useUpdateChannel() {
  return useChannelWrite(({ id, changes, idempotencyKey }: { id: number; changes: PaymentChannelChanges; idempotencyKey: string }) =>
    authed<PaymentChannel>(`/payment-channels/${id}`, { method: "PATCH", body: changes, idempotencyKey }),
  );
}

export function useRemoveChannel() {
  return useChannelWrite(({ id, idempotencyKey }: { id: number; idempotencyKey: string }) =>
    authed<PaymentChannel>(`/payment-channels/${id}`, { method: "DELETE", idempotencyKey }),
  );
}

/** PUT /payment-channels/order: every live channel's id exactly once, in the new order; the answer is the renumbered list. */
export function useReorderChannels() {
  return useChannelWrite(({ ids, idempotencyKey }: { ids: number[]; idempotencyKey: string }) =>
    authed<Collection<PaymentChannel>>("/payment-channels/order", { method: "PUT", body: { ids }, idempotencyKey }),
  );
}

/** GET /payments by cursor: an older page of the list, read once (the first page is the polled feed). */
export function olderPayments(query: Record<string, string>, cursor: string): Promise<PaymentsPage> {
  return authed<PaymentsPage>("/payments", { query: { ...query, cursor } });
}

/** GET /payments/{payment} (payments.view): the payment with its order and its refunds. */
export function usePayment(id: number | null) {
  const allowed = useAllowed("payments.view");
  return useQuery({
    queryKey: ["payment", id],
    queryFn: () => authed<PaymentDetail>(`/payments/${id}`),
    enabled: allowed && id !== null,
    staleTime: 0,
  });
}

/** POST /payments/{payment}/verify (payments.verify): recorded or disputed -> verified; the note stays in the audit trail only. */
export function verifyPayment(id: number, note: string | null, idempotencyKey: string): Promise<PaymentDetail> {
  return authed<PaymentDetail>(`/payments/${id}/verify`, { method: "POST", body: note ? { note } : {}, idempotencyKey });
}

/** POST /payments/{payment}/dispute (payments.verify): recorded -> disputed, with a reason of 3 to 255 characters. */
export function disputePayment(id: number, reason: string, idempotencyKey: string): Promise<PaymentDetail> {
  return authed<PaymentDetail>(`/payments/${id}/dispute`, { method: "POST", body: { reason }, idempotencyKey });
}

/** A decision's answer is the payment: the detail is replaced in place. */
export function useDecision() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (args: { id: number; kind: "verify" | "dispute"; text: string; idempotencyKey: string }) =>
      args.kind === "verify" ? verifyPayment(args.id, args.text.trim() || null, args.idempotencyKey) : disputePayment(args.id, args.text.trim(), args.idempotencyKey),
    onSuccess: (payment) => client.setQueryData(["payment", payment.id], payment),
  });
}

/** GET /files/{file}/url: a fresh signed URL (5 minutes) of a receipt photo; never cached, a viewer asks each time it opens. */
export function signedFileUrl(fileId: number): Promise<SignedFileUrl> {
  return authed<SignedFileUrl>(`/files/${fileId}/url`);
}

const EXPORT_TYPES = { csv: "text/csv", xlsx: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" } as const;

/** GET /payments/export (payments.verify): the list's filters as a file, read with the bearer token (an <a href> could not send it). */
export function paymentsExport(query: Record<string, string>, format: keyof typeof EXPORT_TYPES, locale: string): Promise<Blob> {
  return authedBlob("/payments/export", { query: { ...query, format, locale }, accept: EXPORT_TYPES[format], timeoutMs: 120_000 });
}

/** PUT /receipt-settings (channels.manage): the whole form; the answer replaces what the till's receipt preview reads. */
export function useSaveReceiptSettings() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: ({ body, idempotencyKey }: { body: ReceiptSettingsInput; idempotencyKey: string }) =>
      authed<ReceiptSettings>("/receipt-settings", { method: "PUT", body, idempotencyKey }),
    onSuccess: (settings) => client.setQueryData(["receipt-settings"], settings),
  });
}
