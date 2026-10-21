"use client";

import { useQuery } from "@tanstack/react-query";
import { authed, useSession } from "../session";
import type { AppNotification, NotificationChannels } from "./types";

// The calls of the notification bell. Reading the inbox is the LiveFeed of lib/live/notifications.ts; these are the
// writes (read, read all, subscribe to push) and the channels the web may offer.

export function readNotification(id: number, idempotencyKey: string): Promise<AppNotification> {
  return authed<AppNotification>(`/notifications/${id}/read`, { method: "POST", body: {}, idempotencyKey });
}

export function readAllNotifications(beforeId: number | null, idempotencyKey: string): Promise<{ updated: number }> {
  return authed<{ updated: number }>("/notifications/read-all", { method: "POST", body: beforeId === null ? {} : { before_id: beforeId }, idempotencyKey });
}

/** GET /notifications/channels: the "Enable notifications" control is offered only when push is on and not yet subscribed. */
export function useNotificationChannels(enabled: boolean) {
  const signedIn = useSession((s) => s.status === "signed-in");
  return useQuery({
    queryKey: ["notification-channels"],
    queryFn: () => authed<NotificationChannels>("/notifications/channels"),
    enabled: enabled && signedIn,
    staleTime: 60_000,
  });
}

export interface PushSubscriptionBody {
  endpoint: string;
  keys: { p256dh: string; auth: string };
}

export function subscribePush(subscription: PushSubscriptionBody, idempotencyKey: string): Promise<{ id: number; driver: string }> {
  return authed<{ id: number; driver: string }>("/notifications/endpoints", { method: "POST", body: { driver: "push", subscription }, idempotencyKey });
}
