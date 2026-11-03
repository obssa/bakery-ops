"use client";

import type { ReactNode } from "react";
import { createPortal } from "react-dom";
import { useTopBarSlots } from "@/lib/topBar";

/**
 * A station screen's own buttons in the top bar's extras place, before the sync chip (lib/topBar.ts). Rendered through a portal, so they
 * keep the screen's state and events; nothing is drawn until the shell has registered the place.
 */
export function StationHeaderActions({ children }: { children: ReactNode }) {
  const place = useTopBarSlots((s) => s.actions);
  return place ? createPortal(children, place) : null;
}
