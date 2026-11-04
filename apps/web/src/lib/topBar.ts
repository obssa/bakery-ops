"use client";

import { useEffect } from "react";
import { create } from "zustand";

// The station top bar's API for the screens (step 6.3a; fresh-counter.md 3 and the Pad, Kitchen, Till and Production boards): every station
// screen has exactly one 68 px bar, drawn by the shell (components/AppShell.tsx StationBar). A screen fills it in two ways:
//
// - `useStationHeader({ title, titleAm, subtitle, night, bell })` names the screen. Without it the bar shows the station the screen's shift
//   feed published (lib/station.ts useStationChrome: its name, its Amharic name beside it on the English page, and its shift with the
//   person in the subtitle), or the app's name. `night` draws the bar for the kitchen display's night look; `bell: false` leaves the
//   bell out (the pad's board has none).
// - `<StationHeaderActions>` (components/StationHeaderActions.tsx) portals the screen's own buttons into the bar's extras place, before
//   the sync chip: the pad's "Tables", "My orders · N", chime and "Lock pad". A portal keeps them in the screen's React tree, so they use
//   its state.
// - `<StationHeaderNotice>` (the same file) portals a band into the place directly under the bar, in the page's flow, so it pushes the page
//   down and never floats over it: the shift's attention strip (ShiftBar). The place is empty, with no height, when no screen fills it.

export interface StationHeader {
  /** the screen's name, in the page's language */
  title?: string;
  /** its Amharic name, shown beside the title on the English page */
  titleAm?: string;
  /** one line under the title: the shift, the person */
  subtitle?: string;
  /** the kitchen display's night look */
  night?: boolean;
  /** the notification bell (on by default) */
  bell?: boolean;
}

interface TopBarState {
  header: StationHeader | null;
  /** the bar's extras place, registered by the shell */
  actions: HTMLElement | null;
  /** the place under the bar, in the page's flow, registered by the shell */
  notice: HTMLElement | null;
  setHeader: (header: StationHeader | null) => void;
  setActions: (el: HTMLElement | null) => void;
  setNotice: (el: HTMLElement | null) => void;
}

export const useTopBarSlots = create<TopBarState>()((set) => ({
  header: null,
  actions: null,
  notice: null,
  setHeader: (header) => set({ header }),
  setActions: (el) => set({ actions: el }),
  setNotice: (el) => set({ notice: el }),
}));

/** Names the station screen in the top bar while it is mounted (see above). */
export function useStationHeader(header: StationHeader): void {
  const { title, titleAm, subtitle, night, bell } = header;
  useEffect(() => {
    useTopBarSlots.getState().setHeader({ title, titleAm, subtitle, night, bell });
  }, [title, titleAm, subtitle, night, bell]);
  useEffect(() => () => useTopBarSlots.getState().setHeader(null), []);
}
