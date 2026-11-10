import type { Brand } from "./api/types";

// The brand this device last saw in GET /me (Phase 6 follow-up), kept in localStorage so the sign-in screen, which nobody is signed in to,
// shows the bakery's name and logo too. A convenience only: a private window or blocked storage reads nothing, and the screen shows the
// app's own name and mark. Every access is wrapped, as storage can throw.

const KEY = "bakery-ops.brand";

export function rememberBrand(brand: Brand | null): void {
  try {
    if (brand && !brand.is_default) window.localStorage.setItem(KEY, JSON.stringify(brand));
    else window.localStorage.removeItem(KEY);
  } catch {
    // storage off: nothing to remember
  }
}

export function rememberedBrand(): Brand | null {
  try {
    const raw = window.localStorage.getItem(KEY);
    if (!raw) return null;
    const brand = JSON.parse(raw) as Brand;
    return typeof brand === "object" && brand !== null && "is_default" in brand ? brand : null;
  } catch {
    return null;
  }
}
