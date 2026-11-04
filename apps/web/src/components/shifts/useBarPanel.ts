"use client";

import { useLayoutEffect, useRef, useState, type CSSProperties } from "react";

// A floating panel that belongs to a button in the station top bar: the shift's (ShiftBar look="menu") and the drawer's (CashWidget
// look="summary"). It opens only when someone taps for it, and closes on its button, on a tap or click outside it, and on Escape, which gives
// the focus back to the button. A dialog opened from the panel is drawn inside it: a tap there is not outside, and Escape closes the dialog
// first (ui.tsx Modal). On a phone the bar wraps to two or three rows, so the panel is placed under the bar's real height, not a fixed one, and
// its height is what is left of the window: it scrolls inside itself.

/** Between the bar and the panel, and between the panel and the window's bottom edge. */
const GAP = 8;
/** The 68 px bar and the gap, until the bar has been measured. */
const FIRST_TOP = 68 + GAP;

export function useBarPanel() {
  const [open, setOpen] = useState(false);
  const [top, setTop] = useState(FIRST_TOP);
  const toggleRef = useRef<HTMLButtonElement>(null);
  const panelRef = useRef<HTMLDivElement>(null);
  const focusOnOpen = useRef(false);

  useLayoutEffect(() => {
    if (!open) return;

    function place() {
      const bar = toggleRef.current?.closest("header");
      if (bar) setTop(Math.max(GAP, Math.round(bar.getBoundingClientRect().bottom) + GAP));
    }
    function onKey(event: KeyboardEvent) {
      if (event.key !== "Escape" || panelRef.current?.querySelector('[role="dialog"]')) return;
      setOpen(false);
      toggleRef.current?.focus();
    }
    function onPointer(event: PointerEvent) {
      const target = event.target as Node;
      if (panelRef.current?.contains(target) || toggleRef.current?.contains(target)) return;
      setOpen(false);
    }

    place();
    if (focusOnOpen.current) {
      focusOnOpen.current = false;
      panelRef.current?.focus({ preventScroll: true });
    }
    window.addEventListener("resize", place);
    document.addEventListener("keydown", onKey);
    document.addEventListener("pointerdown", onPointer);
    return () => {
      window.removeEventListener("resize", place);
      document.removeEventListener("keydown", onKey);
      document.removeEventListener("pointerdown", onPointer);
    };
  }, [open]);

  const panelStyle: CSSProperties = { top, maxHeight: `calc(100dvh - ${top + GAP}px)` };

  return {
    open,
    /** the panel's own button */
    toggle: () => setOpen((now) => !now),
    /** a control that goes away while the panel is open (the shift strip's "More"): the focus moves into the panel instead of being lost */
    openWithFocus: () => {
      focusOnOpen.current = true;
      setOpen(true);
    },
    toggleRef,
    panelRef,
    panelStyle,
  };
}
