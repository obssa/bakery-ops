"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { deviceId } from "../device";
import { authed, useSession } from "../session";
import type { Collection, Device, EnrolledDevice, NewDevice, ThisDevice } from "./types";

// React Query hooks over the device routes (step 5.1b; the API of step 5.1a). The list and the three writes need devices.manage (the
// admin); GET /devices/this (devices.self, every role) tells a page whether the device it runs on is enrolled. Every write carries the
// Idempotency-Key its form chose; an enrolment also carries the client_uuid that makes its row.

export const DEVICES_KEY = ["devices"] as const;
export const THIS_DEVICE_KEY = ["devices", "this"] as const;

function useSignedIn(): boolean {
  return useSession((s) => s.status === "signed-in");
}

export function useDevices() {
  const enabled = useSignedIn();
  return useQuery({
    queryKey: [...DEVICES_KEY, "list"],
    queryFn: async () => (await authed<Collection<Device>>("/devices")).data,
    enabled,
  });
}

/** Whether this device is enrolled; the id is part of the key, so a renewed id (after a revoke) is asked about afresh. */
export function useThisDevice() {
  const enabled = useSignedIn();
  const id = deviceId();
  return useQuery({
    queryKey: [...THIS_DEVICE_KEY, id],
    queryFn: () => authed<ThisDevice>("/devices/this"),
    enabled,
  });
}

interface Write<T> {
  body: T;
  idempotencyKey: string;
}

export function useEnrolDevice() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: ({ body, idempotencyKey }: Write<NewDevice>) => authed<EnrolledDevice>("/devices", { method: "POST", body, idempotencyKey }),
    onSuccess: () => client.invalidateQueries({ queryKey: DEVICES_KEY }),
  });
}

export function useRenameDevice() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: ({ id, body, idempotencyKey }: Write<{ name: string }> & { id: number }) =>
      authed<Device>(`/devices/${id}/rename`, { method: "POST", body, idempotencyKey }),
    onSuccess: () => client.invalidateQueries({ queryKey: DEVICES_KEY }),
  });
}

export function useRevokeDevice() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: ({ id, idempotencyKey }: { id: number; idempotencyKey: string }) =>
      authed<Device>(`/devices/${id}/revoke`, { method: "POST", body: {}, idempotencyKey }),
    onSuccess: () => client.invalidateQueries({ queryKey: DEVICES_KEY }),
  });
}
