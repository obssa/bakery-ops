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

/**
 * A station screen's band directly under the top bar (lib/topBar.ts), in the page's flow: it pushes the page down and covers nothing. Also a
 * portal, so it keeps the screen's state and events, and it stays outside whatever region the screen draws in the bar.
 */
export function StationHeaderNotice({ children }: { children: ReactNode }) {
  const place = useTopBarSlots((s) => s.notice);
  return place ? createPortal(children, place) : null;
}
