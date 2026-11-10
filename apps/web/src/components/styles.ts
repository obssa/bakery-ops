// Shared class names, in a plain module so server components can use them too (an export of a "use client" module
// is a client reference on the server, not the string). The design system is docs/design/fresh-counter.md; the tokens behind
// these names live in app/globals.css (ADR 0023).

/** Always-visible keyboard focus in the brand red (fresh-counter.md 1.5): never removed, on every control. */
export const focusRing =
  "focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-brand-600/35 focus-visible:ring-offset-1";

export type ButtonVariant = "primary" | "secondary" | "ghost" | "danger" | "dark";
/** md 44 px (admin), lg 52 px (station), xl 64 px (the one main action of a station screen). */
export type ButtonSize = "md" | "lg" | "xl";

// Each variant at rest and on hover. The hover is left out while a button is loading, and a disabled button's look (below) wins over it.
const BUTTON_LOOK: Record<ButtonVariant, { rest: string; hover: string }> = {
  primary: { rest: "border-transparent bg-ink-900 text-white", hover: "hover:bg-ink-800 active:bg-ink-800" },
  secondary: { rest: "border-neutral-300 bg-white text-neutral-900", hover: "hover:bg-neutral-100" },
  ghost: { rest: "border-transparent bg-transparent text-neutral-900", hover: "hover:bg-neutral-100" },
  danger: { rest: "border-transparent bg-red-50 text-red-700", hover: "hover:bg-red-100" },
  dark: { rest: "border-transparent bg-ink-900 text-white", hover: "hover:bg-ink-800" },
};

// Every variant disabled alike: no hover, a neutral-50 fill, neutral-400 text (fresh-counter.md 2, Button).
const BUTTON_DISABLED = "disabled:cursor-not-allowed disabled:border-neutral-200 disabled:bg-neutral-50 disabled:text-neutral-400";

// Without a size a button keeps the 48 px it has had since Phase 0, the floor of a station target and above an admin one, so a screen
// that passes none keeps its layout until its own step picks md, lg or xl.
const BUTTON_SIZE: Record<ButtonSize | "base", { box: string; padding: string }> = {
  base: { box: "min-h-12 rounded-xl text-base", padding: "px-5" },
  md: { box: "min-h-11 rounded-xl text-[15px]", padding: "px-4" },
  lg: { box: "min-h-13 rounded-2xl text-base", padding: "px-5" },
  xl: { box: "min-h-16 rounded-2xl text-lg", padding: "px-6" },
};

const ICON_BUTTON_SIZE: Record<ButtonSize | "base", string> = {
  base: "size-12 rounded-xl",
  md: "size-11 rounded-xl",
  lg: "size-13 rounded-2xl",
  xl: "size-16 rounded-2xl",
};

const BUTTON_BASE =
  "relative inline-flex items-center justify-center gap-2 border font-semibold motion-safe:transition-colors motion-safe:duration-150 motion-safe:ease-out";

export interface ButtonLook {
  variant?: ButtonVariant;
  size?: ButtonSize;
  /** less side padding, for a row of actions in a table (still as tall) */
  compact?: boolean;
  /** busy: no hover and no disabled grey, so the button keeps its look under the spinner */
  loading?: boolean;
  /** a square button for one icon (IconButton) */
  square?: boolean;
}

/** The classes of a button, for a <button> (ui.tsx Button and IconButton) or a link that looks like one. */
export function buttonClass({ variant = "primary", size, compact = false, loading = false, square = false }: ButtonLook = {}): string {
  const look = BUTTON_LOOK[variant];
  const box = BUTTON_SIZE[size ?? "base"];
  const dimensions = square ? ICON_BUTTON_SIZE[size ?? "base"] : `${box.box} ${compact ? "px-3" : box.padding}`;
  const state = loading ? "cursor-wait" : `${look.hover} ${BUTTON_DISABLED}`;
  return `${BUTTON_BASE} ${dimensions} ${look.rest} ${state} ${focusRing}`;
}

/** Inputs: 44 px (admin) or 52 px (station); without a size the 48 px of Phase 0. */
export type ControlSize = "md" | "lg";

export const CONTROL_HEIGHT: Record<ControlSize | "base", string> = {
  base: "min-h-12 text-base",
  md: "min-h-11 text-[15px]",
  lg: "min-h-13 text-base",
};

/**
 * A text input, select or textarea (fresh-counter.md 2): a neutral-300 hairline, a 2 px brand border on focus (the border and a 1 px
 * inset ring, so nothing moves), red when invalid, the focus ring around it.
 */
export function controlClass(size?: ControlSize): string {
  return (
    `block w-full rounded-xl border border-neutral-300 bg-white px-3 text-neutral-900 ${CONTROL_HEIGHT[size ?? "base"]} ` +
    "placeholder:text-neutral-500 focus:border-ink-900 focus:inset-ring-1 focus:inset-ring-ink-900 " +
    "aria-[invalid=true]:border-red-700 aria-[invalid=true]:focus:inset-ring-red-700 " +
    "disabled:cursor-not-allowed disabled:bg-neutral-50 disabled:text-neutral-500 " +
    focusRing
  );
}

/** A white surface: hairline border, 16 px corners, 20 px padding (Card, Panel, Stat). */
export const surface = "rounded-2xl border border-neutral-200 bg-white p-5";

/** A number cell of a Table: right-aligned with tabular figures (also on its header cell). */
export const tableNum = "text-right tabular-nums";
