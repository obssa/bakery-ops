"use client";

import { create } from "zustand";

// The station top bar's places for a screen's own controls (step 6.3a, fresh-counter.md 3.2): the waiter pad puts its name beside the
// mark, and its "Tables" and "My orders" buttons and the chime before the language button, as the design board has them. The shell
// draws the two places in its `fill` frame and registers them here; the screen renders into them through a portal, so its controls keep
// the screen's own state (the cart, the open sheets, the chime) while they sit in the bar.

interface TopBarSlots {
  /** after the app's mark: the screen's name */
  title: HTMLElement | null;
  /** before the language button: the screen's buttons */
  actions: HTMLElement | null;
  setTitle: (el: HTMLElement | null) => void;
  setActions: (el: HTMLElement | null) => void;
}

export const useTopBarSlots = create<TopBarSlots>()((set) => ({
  title: null,
  actions: null,
  setTitle: (el) => set({ title: el }),
  setActions: (el) => set({ actions: el }),
}));
