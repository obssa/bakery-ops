import { create } from "zustand";
import { uuid } from "./api/idempotency";
import type { Locale } from "./api/types";

// This device's id (phase-5 plan 4.1, ADR 0017): every request of the shell sends it in X-Device-Id (api/client.ts), and once the API's
// IDENTITY_REQUIRE_DEVICE is on, a station route (the pad, the KDS, the till, production, the shift bar, and the shared reads of the admin
// pages) answers only a device an admin enrolled. The id is made once and kept in localStorage (memory when storage is blocked). A revoked
// id is never enrolled again (ADR 0017 decision 6), so the first refusal that says "revoked" drops it and makes a new one, which the admin
// then enrols as a new device. The refusal itself is kept here for the "not enrolled" screen (components/DeviceGate.tsx).

export const DEVICE_HEADER = "X-Device-Id";

/** The localStorage key of the id (the e2e fixtures put their enrolled ids there, e2e/support/devices.ts). */
export const DEVICE_STORAGE_KEY = "bakery-ops.device-id";

export const DEVICE_REFUSALS = ["identity.device.unknown", "identity.device.revoked", "identity.device.other_branch"] as const;
export type DeviceRefusalKey = (typeof DEVICE_REFUSALS)[number];

let memoryId: string | null = null;

function stored(): string | null {
  try {
    return window.localStorage.getItem(DEVICE_STORAGE_KEY);
  } catch {
    return null; // storage blocked, or no window: the id lasts for this tab
  }
}

function store(id: string): void {
  try {
    window.localStorage.setItem(DEVICE_STORAGE_KEY, id);
  } catch {
    // see above
  }
}

/** This device's id for X-Device-Id. Ids made before step 5.1b (the pad's `pad-…`) are kept as they are. */
export function deviceId(): string {
  if (memoryId) return memoryId;
  const kept = stored();
  if (kept) return (memoryId = kept);
  return renewDeviceId();
}

/** Forgets this device's id and makes a new one (after a revoke, or to enrol in this branch a tablet enrolled in another). */
export function renewDeviceId(): string {
  const made = `dev-${uuid()}`;
  store(made);
  return (memoryId = made);
}

/** The id as a person reads it off the screen and finds it in the admin's list: its last eight characters, in capitals. */
export function shortDeviceId(id: string): string {
  return id.slice(-8).toUpperCase();
}

/** A 403 that means "this device may not use this route": unknown, revoked, or enrolled in another branch. */
export function isDeviceRefusal(error: unknown): error is { status: 403; key: DeviceRefusalKey } {
  if (typeof error !== "object" || error === null) return false;
  const { status, key } = error as { status?: unknown; key?: unknown };
  return status === 403 && (DEVICE_REFUSALS as readonly unknown[]).includes(key);
}

/** The page a call is made for (the browser's path; empty outside a browser). */
export function currentPath(): string {
  try {
    return window.location.pathname;
  } catch {
    return "";
  }
}

export interface DeviceRefusal {
  key: DeviceRefusalKey;
  messages: Partial<Record<Locale, string>>;
}

interface DeviceGateState {
  /** the last device refusal on the page being shown, until the person goes to another page or the device is enrolled */
  refusal: DeviceRefusal | null;
  /**
   * A call made with device `sentWith` from page `path` came back refused. A revoke renews the id, once: other calls that went out with
   * the same old id and come back revoked leave the new one alone. A refusal for a page the person has already left is not shown.
   */
  report: (error: { key: DeviceRefusalKey; messages: Partial<Record<Locale, string>> }, sentWith: string, path: string) => void;
  clear: () => void;
}

export const useDeviceGate = create<DeviceGateState>()((set) => ({
  refusal: null,
  report: (error, sentWith, path) => {
    if (error.key === "identity.device.revoked" && sentWith === deviceId()) renewDeviceId();
    if (path !== currentPath()) return;
    set({ refusal: { key: error.key, messages: { en: error.messages.en, am: error.messages.am } } });
  },
  clear: () => set({ refusal: null }),
}));
