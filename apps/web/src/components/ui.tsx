"use client";

import {
  CakeSlice,
  ChevronDown,
  CircleAlert,
  CircleHelp,
  Coffee,
  GlassWater,
  Inbox,
  LoaderCircle,
  Pizza,
  Sandwich,
  UtensilsCrossed,
  Wheat,
  type LucideIcon,
} from "lucide-react";
import {
  useEffect,
  useId,
  useLayoutEffect,
  useRef,
  useState,
  type ButtonHTMLAttributes,
  type InputHTMLAttributes,
  type ReactNode,
  type SelectHTMLAttributes,
  type TableHTMLAttributes,
  type TextareaHTMLAttributes,
} from "react";
import type { ProductImageView } from "@/lib/api/types";
import {
  frameSize,
  initialsOf,
  mediaUrl,
  placeholderIcon,
  sizesFor,
  srcSetOf,
  type PlaceholderIcon,
  type ProductImageSize,
} from "@/lib/images/productImage";
import {
  buttonClass,
  CONTROL_HEIGHT,
  controlClass,
  focusRing,
  surface,
  type ButtonSize,
  type ButtonVariant,
  type ControlSize,
} from "./styles";

// The building blocks of every screen, in the Fresh Counter look (docs/design/fresh-counter.md section 2, ADR 0023). The primitives
// of Phase 0 keep their names and props, so a screen restyles without an edit; the ones Phase 6 adds sit beside them. Station targets
// are at least 48 px tall and admin ones 44 px, focus is always visible, every icon-only control has an aria-label, and every word comes
// from the caller (messages/{en,am}.json).

const ICON_STROKE = 1.75;

// ------------------------------------------------------------------ buttons

/**
 * A button. Variants primary (ink), secondary (white, hairline), ghost, danger (red-50) and dark (ink, as primary); sizes md 44 px (admin),
 * lg 52 px (station) and xl 64 px (the one main action of a station screen), or the 48 px of Phase 0 without a size. `loading` shows a
 * spinner in place of the label and keeps the width; the label stays in the accessible name.
 */
export function Button({
  variant = "primary",
  size,
  compact = false,
  loading = false,
  className = "",
  disabled,
  children,
  ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: ButtonVariant;
  size?: ButtonSize;
  /** less side padding, for a row of actions in a table (still as tall) */
  compact?: boolean;
  /** busy: disabled, with a spinner over the label */
  loading?: boolean;
}) {
  return (
    <button
      className={`${buttonClass({ variant, size, compact, loading })} ${className}`}
      disabled={disabled || loading}
      aria-busy={loading || undefined}
      {...props}
    >
      {loading ? (
        <>
          <span className="inline-flex items-center gap-2 opacity-0">{children}</span>
          <Spinner />
        </>
      ) : (
        children
      )}
    </button>
  );
}

/** A square button holding one icon, in the same variants and sizes; `label` is its accessible name (and the only one). */
export function IconButton({
  label,
  variant = "ghost",
  size,
  loading = false,
  className = "",
  disabled,
  children,
  type = "button",
  ...props
}: Omit<ButtonHTMLAttributes<HTMLButtonElement>, "aria-label"> & {
  label: string;
  variant?: ButtonVariant;
  size?: ButtonSize;
  loading?: boolean;
}) {
  return (
    <button
      type={type}
      aria-label={label}
      className={`${buttonClass({ variant, size, loading, square: true })} ${className}`}
      disabled={disabled || loading}
      aria-busy={loading || undefined}
      {...props}
    >
      {loading ? <Spinner /> : children}
    </button>
  );
}

function Spinner() {
  return (
    <span aria-hidden="true" className="absolute inset-0 flex items-center justify-center">
      <LoaderCircle className="size-5 motion-safe:animate-spin" strokeWidth={2} aria-hidden="true" />
    </span>
  );
}

// ------------------------------------------------------------------ form controls

interface FieldProps {
  label: string;
  hint?: ReactNode;
  error?: string;
  optionalLabel?: string;
  children: (props: { id: string; "aria-describedby"?: string; "aria-invalid"?: boolean }) => ReactNode;
}

/** A labelled control with an optional hint and error, wired for screen readers and for getByLabel. */
export function Field({ label, hint, error, optionalLabel, children }: FieldProps) {
  const id = useId();
  const hintId = hint ? `${id}-hint` : undefined;
  const errorId = error ? `${id}-error` : undefined;
  const describedBy = [hintId, errorId].filter(Boolean).join(" ") || undefined;
  return (
    <div className="space-y-1.5">
      <label htmlFor={id} className="block text-sm font-semibold text-neutral-900">
        {label}
        {optionalLabel ? <span className="font-normal text-neutral-600"> ({optionalLabel})</span> : null}
      </label>
      {children({ id, "aria-describedby": describedBy, "aria-invalid": error ? true : undefined })}
      {hint ? (
        <p id={hintId} className="text-sm text-neutral-600">
          {hint}
        </p>
      ) : null}
      {error ? (
        <p id={errorId} className="text-sm font-medium text-red-700">
          {error}
        </p>
      ) : null}
    </div>
  );
}

type ControlProps = { /** 44 px (admin) or 52 px (station); 48 px without one */ controlSize?: ControlSize };

export function TextInput({ className = "", controlSize, ...props }: InputHTMLAttributes<HTMLInputElement> & ControlProps) {
  return <input className={`${controlClass(controlSize)} ${className}`} {...props} />;
}

export function Textarea({ className = "", ...props }: TextareaHTMLAttributes<HTMLTextAreaElement>) {
  return <textarea className={`${controlClass()} min-h-24 py-2 ${className}`} {...props} />;
}

export function Select({ className = "", controlSize, ...props }: SelectHTMLAttributes<HTMLSelectElement> & ControlProps) {
  return <select className={`${controlClass(controlSize)} pr-8 ${className}`} {...props} />;
}

/**
 * An amount: the currency code before the figure in neutral-600, the figure in tabular numbers. The props go to the <input> (so Field
 * wires it as any input); `className` goes to the box around it.
 */
export function MoneyInput({
  currency = "ETB",
  controlSize,
  className = "",
  ...props
}: Omit<InputHTMLAttributes<HTMLInputElement>, "type"> & ControlProps & { currency?: string }) {
  return (
    <div
      className={
        `flex w-full items-center gap-2 rounded-xl border border-neutral-300 bg-white px-3 ${CONTROL_HEIGHT[controlSize ?? "base"]} ` +
        "focus-within:border-ink-900 focus-within:inset-ring-1 focus-within:inset-ring-ink-900 focus-within:ring-4 focus-within:ring-brand-600/35 focus-within:ring-offset-1 " +
        "has-[[aria-invalid=true]]:border-red-700 has-[:disabled]:bg-neutral-50 " +
        className
      }
    >
      <span className="shrink-0 text-[15px] text-neutral-600">{currency}</span>
      <input
        type="text"
        inputMode="decimal"
        autoComplete="off"
        className="min-w-0 flex-1 self-stretch bg-transparent font-semibold tabular-nums text-neutral-900 outline-none placeholder:font-normal placeholder:text-neutral-500 disabled:cursor-not-allowed disabled:text-neutral-500"
        {...props}
      />
    </div>
  );
}

/**
 * An on/off setting: a real <button role="switch" aria-checked> (52 × 32, positive when on) with its visible label beside it; the
 * label names the switch and a tap on it toggles too.
 */
export function Switch({
  checked,
  onChange,
  label,
  hint,
  disabled,
  className = "",
}: {
  checked: boolean;
  onChange: (next: boolean) => void;
  label: string;
  hint?: ReactNode;
  disabled?: boolean;
  className?: string;
}) {
  const id = useId();
  const hintId = hint ? `${id}-hint` : undefined;
  return (
    <div className={`flex items-center justify-between gap-4 ${className}`}>
      <div className="min-w-0">
        <label htmlFor={id} className="block text-[15px] font-semibold text-neutral-900">
          {label}
        </label>
        {hint ? (
          <p id={hintId} className="text-[13px] text-neutral-600">
            {hint}
          </p>
        ) : null}
      </div>
      <button
        id={id}
        type="button"
        role="switch"
        aria-checked={checked}
        aria-describedby={hintId}
        disabled={disabled}
        onClick={() => onChange(!checked)}
        className={`inline-flex h-8 w-13 shrink-0 items-center rounded-full p-1 motion-safe:transition-colors motion-safe:duration-150 disabled:cursor-not-allowed disabled:opacity-50 ${checked ? "bg-positive-600" : "bg-neutral-300"} ${focusRing}`}
      >
        <span
          aria-hidden="true"
          className={`size-6 rounded-full bg-white shadow-xs motion-safe:transition-transform motion-safe:duration-150 ${checked ? "translate-x-5" : "translate-x-0"}`}
        />
      </button>
    </div>
  );
}

export interface SegmentOption<T extends string> {
  value: T;
  label: ReactNode;
}

const SEGMENT_SELECTED = {
  station: "bg-ink-900 text-white",
  admin: "bg-ink-100 text-ink-900",
} as const;

/**
 * One choice among a few, on a white track: the selected segment is ink with white text on a station, ink-100 with ink-900 text on
 * admin (a choice is charcoal, never red). A group of pressed buttons by default; `tabs` makes it a tablist of tabs (aria-selected), as the pad's and the till's views are.
 */
export function SegmentedControl<T extends string>({
  label,
  options,
  value,
  onChange,
  tone = "admin",
  tabs = false,
  className = "",
}: {
  label: string;
  options: readonly SegmentOption<T>[];
  value: T;
  onChange: (value: T) => void;
  tone?: keyof typeof SEGMENT_SELECTED;
  tabs?: boolean;
  className?: string;
}) {
  return (
    <div
      role={tabs ? "tablist" : "group"}
      aria-label={label}
      className={`inline-flex max-w-full flex-wrap gap-1 rounded-xl border border-neutral-200 bg-white p-1 ${className}`}
    >
      {options.map((option) => {
        const selected = option.value === value;
        return (
          <button
            key={option.value}
            type="button"
            {...(tabs ? { role: "tab", "aria-selected": selected } : { "aria-pressed": selected })}
            onClick={() => onChange(option.value)}
            className={`inline-flex items-center justify-center gap-2 rounded-lg px-4 font-semibold motion-safe:transition-colors motion-safe:duration-150 ${tone === "station" ? "min-h-12 text-base" : "min-h-11 text-[15px]"} ${selected ? SEGMENT_SELECTED[tone] : "text-neutral-700 hover:bg-neutral-100"} ${focusRing}`}
          >
            {option.label}
          </button>
        );
      })}
    </div>
  );
}

// ------------------------------------------------------------------ help

/**
 * A "?" beside something that needs a word of explanation (the explain-itself follow-up, Ramin 2026-11-10): a 44 px button that opens a
 * small card under it saying what the thing is, why, and what to do. A disclosure, not a modal: the button carries aria-expanded and
 * names the card; Esc closes it and gives the focus back, and so does a tap or a click anywhere else. The card is kept inside the window
 * (16 px from each side), so on a phone it never makes the page scroll sideways.
 */
export function HelpTip({ label, children, className = "" }: { label: string; children: ReactNode; className?: string }) {
  const id = useId();
  const [open, setOpen] = useState(false);
  const wrap = useRef<HTMLSpanElement>(null);
  const button = useRef<HTMLButtonElement>(null);
  const card = useRef<HTMLSpanElement>(null);

  useEffect(() => {
    if (!open) return;
    const away = (event: PointerEvent) => {
      if (wrap.current && !wrap.current.contains(event.target as Node)) setOpen(false);
    };
    const key = (event: KeyboardEvent) => {
      if (event.key !== "Escape") return;
      setOpen(false);
      button.current?.focus();
    };
    document.addEventListener("pointerdown", away);
    document.addEventListener("keydown", key);
    return () => {
      document.removeEventListener("pointerdown", away);
      document.removeEventListener("keydown", key);
    };
  }, [open]);

  // moved left or right so that the whole card stays 16 px inside the window, worked out when it opens and then on every frame while it is
  // open: the page around it may reflow at any time (the window turned or resized, a row wrapping, a list arriving), and a card left where
  // it was would make a phone's page scroll sideways. The card's own place is read from what does not move with it (it hangs at the left
  // edge of its wrapper, so its unshifted left is the wrapper's left, and its width is its layout width), never from its own shifted box:
  // reading the shifted box and taking the last shift off drifted from frame to frame whenever a frame was missed (the analytics "?" at 390
  // ended 400 px off the window, 2026-11-12). Only this loop writes the card's transform, and only when the shift changes.
  useLayoutEffect(() => {
    const element = card.current;
    const anchor = wrap.current;
    if (!element || !anchor) return;
    if (!open) {
      element.style.transform = "";
      return;
    }
    let current: number | null = null;
    let frame = 0;
    const place = () => {
      const left = anchor.getBoundingClientRect().left;
      const right = left + element.offsetWidth;
      const room = document.documentElement.clientWidth - 16;
      const next = Math.round(right > room ? Math.max(16 - left, room - right) : left < 16 ? 16 - left : 0);
      if (next !== current) {
        current = next;
        element.style.transform = next ? `translateX(${next}px)` : "";
      }
    };
    const loop = () => {
      place();
      frame = window.requestAnimationFrame(loop);
    };
    loop();
    return () => window.cancelAnimationFrame(frame);
  }, [open]);

  return (
    <span ref={wrap} className={`relative inline-flex align-middle ${className}`}>
      <button
        ref={button}
        type="button"
        aria-label={label}
        aria-expanded={open}
        aria-controls={id}
        onClick={() => setOpen((now) => !now)}
        className={`-m-2.5 inline-flex size-11 shrink-0 items-center justify-center rounded-full text-neutral-500 hover:text-ink-900 ${open ? "text-ink-900" : ""} ${focusRing}`}
      >
        <CircleHelp className="size-5" strokeWidth={2} aria-hidden="true" />
      </button>
      {/* a span drawn as a block: the "?" sits inside paragraphs and headings, where a div may not go */}
      <span
        ref={card}
        id={id}
        role="note"
        aria-label={label}
        hidden={!open}
        className="absolute left-0 top-full z-40 mt-3 block w-[min(22rem,calc(100vw-2rem))] rounded-xl border border-neutral-200 bg-white p-4 text-left text-[15px] font-normal leading-relaxed tracking-normal whitespace-normal text-neutral-800 shadow-lg"
      >
        {children}
      </span>
    </span>
  );
}

export interface HowStep {
  title: string;
  text: string;
}

/**
 * How something works, as numbered steps in a strip (the explain-itself follow-up): a card whose title row folds it away. It starts open;
 * once someone folds it, this browser remembers (`remember` names it in localStorage, which may be refused or empty: then it is open).
 */
export function HowItWorks({
  title,
  steps,
  footer,
  remember,
  showLabel,
  hideLabel,
}: {
  title: string;
  steps: readonly HowStep[];
  footer?: ReactNode;
  remember: string;
  showLabel: string;
  hideLabel: string;
}) {
  const id = useId();
  const key = `how:${remember}`;
  const [open, setOpen] = useState(true);
  useEffect(() => {
    try {
      if (window.localStorage.getItem(key) === "closed") setOpen(false);
    } catch {
      // storage refused: the strip stays open
    }
  }, [key]);
  function toggle() {
    const next = !open;
    setOpen(next);
    try {
      window.localStorage.setItem(key, next ? "open" : "closed");
    } catch {
      // storage refused: remembered for this page only
    }
  }
  return (
    <section aria-labelledby={`${id}-title`} className="rounded-[18px] border border-neutral-200 bg-white">
      <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-5 py-2.5">
        <h2 id={`${id}-title`} className="text-[17px] font-semibold text-neutral-900">
          {title}
        </h2>
        <button
          type="button"
          aria-expanded={open}
          aria-controls={id}
          onClick={toggle}
          className={`-mr-2 inline-flex min-h-11 items-center gap-1.5 rounded-lg px-2 text-sm font-semibold text-neutral-700 hover:text-ink-900 ${focusRing}`}
        >
          {open ? hideLabel : showLabel}
          <ChevronDown className={`size-4 ${open ? "rotate-180" : ""}`} strokeWidth={2} aria-hidden="true" />
        </button>
      </div>
      <div id={id} hidden={!open} className="space-y-4 border-t border-neutral-100 px-5 pb-5 pt-4">
        <ol className="grid gap-x-5 gap-y-4 sm:grid-cols-2 xl:grid-cols-4">
          {steps.map((step, index) => (
            <li key={step.title} className="flex min-w-0 gap-3">
              <span aria-hidden="true" className="inline-flex size-7 shrink-0 items-center justify-center rounded-full bg-ink-900 text-[13px] font-bold text-white">
                {index + 1}
              </span>
              <span className="min-w-0 space-y-0.5">
                <span className="block text-[15px] font-semibold text-neutral-900">{step.title}</span>
                <span className="block text-[13px] leading-snug text-neutral-600">{step.text}</span>
              </span>
            </li>
          ))}
        </ol>
        {footer ? <div className="text-[13px] text-neutral-700">{footer}</div> : null}
      </div>
    </section>
  );
}

// ------------------------------------------------------------------ messages

export function Alert({ children }: { children: ReactNode }) {
  return (
    <div role="alert" className="flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-base text-red-800">
      <CircleAlert className="mt-0.5 size-5 shrink-0" strokeWidth={2} aria-hidden="true" />
      <div className="min-w-0 flex-1">{children}</div>
    </div>
  );
}

/** A polite live region; renders even when empty so screen readers pick up the first message. */
export function Status({ children }: { children?: ReactNode }) {
  return (
    <p role="status" aria-live="polite" className={children ? "rounded-xl bg-positive-50 px-4 py-3 text-base font-medium text-positive-700" : "sr-only"}>
      {children}
    </p>
  );
}

// ------------------------------------------------------------------ surfaces

/**
 * A white surface with a hairline border. With a `title` it is a region named by its heading (17 / 600), with an optional `action`
 * (a link or a button) on the right of the title.
 */
export function Card({ title, action, children, className = "" }: { title?: string; action?: ReactNode; children: ReactNode; className?: string }) {
  const id = useId();
  if (!title) return <div className={`${surface} ${className}`}>{children}</div>;
  return (
    <section aria-labelledby={id} className={`${surface} ${className}`}>
      <CardTitle id={id} title={title} action={action} />
      {children}
    </section>
  );
}

function CardTitle({ id, title, action }: { id: string; title: string; action?: ReactNode }) {
  const heading = (
    <h2 id={id} className={`text-[17px] font-semibold text-neutral-900 ${action ? "" : "mb-4"}`}>
      {title}
    </h2>
  );
  if (!action) return heading;
  return (
    <div className="mb-4 flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
      {heading}
      <div className="shrink-0">{action}</div>
    </div>
  );
}

/** A titled section: a landmark region named by its heading (a Card that always has a title). */
export function Panel({ title, action, children, className = "" }: { title: string; action?: ReactNode; children: ReactNode; className?: string }) {
  return (
    <Card title={title} action={action} className={className}>
      {children}
    </Card>
  );
}

/**
 * A page's title row (fresh-counter.md 4.1): the title (30 / 700), a one-line intro in neutral-600, and the page's actions on the right,
 * at the bottom of the row. `twin` is the title in the other language, set beside it in neutral-600 on a page that already shows both
 * languages (spec 5); it sits outside the h1 in its own `lang`, so the heading's name stays the title.
 */
export function PageHeader({
  title,
  intro,
  actions,
  twin,
  help,
}: {
  title: string;
  intro?: string;
  actions?: ReactNode;
  twin?: { text: string; lang: "en" | "am" };
  /** a HelpTip beside the title: what the page is for, in a line or two */
  help?: { label: string; text: string };
}) {
  // beside actions the title and intro take what the actions leave (at least 24rem before they wrap under), so a long intro wraps in its
  // column and the actions stay at the right of the row, as the boards draw them
  const text = (
    <div className="min-w-0 flex-[1_1_24rem] space-y-1">
      <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
        <h1 className="text-3xl font-bold tracking-tight text-neutral-900">{title}</h1>
        {twin ? (
          <span lang={twin.lang} className="text-2xl font-semibold text-neutral-600">
            <span aria-hidden="true">· </span>
            {twin.text}
          </span>
        ) : null}
        {help ? (
          <span className="self-center">
            <HelpTip label={help.label}>{help.text}</HelpTip>
          </span>
        ) : null}
      </div>
      {intro ? <p className="text-base text-neutral-600">{intro}</p> : null}
    </div>
  );
  return (
    <header className={`mb-6 ${actions ? "flex flex-wrap items-end justify-between gap-4" : ""}`}>
      {text}
      {actions ? <div className="flex max-w-full shrink-0 flex-wrap items-center gap-2.5">{actions}</div> : null}
    </header>
  );
}

/** A KPI: a label (14, neutral-600), the value (30 / 700, tabular) and a delta (positive-700, or red-700 when it is bad). */
export function Stat({ label, value, delta, bad = false, className = "" }: { label: string; value: ReactNode; delta?: ReactNode; bad?: boolean; className?: string }) {
  return (
    <div className={`${surface} ${className}`}>
      <p className="text-sm text-neutral-600">{label}</p>
      <p className="mt-1 text-3xl font-bold tracking-tight tabular-nums text-neutral-900">{value}</p>
      {delta ? <p className={`mt-1 text-[13px] font-semibold tabular-nums ${bad ? "text-red-700" : "text-positive-700"}`}>{delta}</p> : null}
    </div>
  );
}

/**
 * Nothing to show yet: a 48 px circle with a line icon, a title, one line of help and at most one action. `level` is the title's
 * heading level (2 by default; 1 when the empty state is the whole page, as the device gate is).
 */
export function EmptyState({
  icon: Icon = Inbox,
  title,
  body,
  action,
  level = 2,
  headingId,
  className = "",
}: {
  icon?: LucideIcon;
  title: string;
  body?: ReactNode;
  action?: ReactNode;
  level?: 1 | 2 | 3;
  /** the heading's id, for a region named by it */
  headingId?: string;
  className?: string;
}) {
  const Heading = (["h1", "h2", "h3"] as const)[level - 1];
  return (
    <div className={`flex flex-col items-center gap-3 px-6 py-10 text-center ${className}`}>
      <span aria-hidden="true" className="inline-flex size-12 items-center justify-center rounded-full bg-neutral-100 text-neutral-500">
        <Icon className="size-6" strokeWidth={ICON_STROKE} aria-hidden="true" />
      </span>
      <Heading id={headingId} className={`font-semibold text-neutral-900 ${level === 1 ? "text-2xl tracking-tight" : "text-lg"}`}>
        {title}
      </Heading>
      {body ? <p className="max-w-md text-[15px] text-neutral-600">{body}</p> : null}
      {action ? <div className="pt-1">{action}</div> : null}
    </div>
  );
}

/** A grey block standing in for content while a first load is pending; size it with `className`. */
export function Skeleton({ className = "" }: { className?: string }) {
  return <div aria-hidden="true" className={`rounded-xl bg-neutral-100 motion-safe:animate-skeleton ${className}`} />;
}

// The admin table: 48 px rows on neutral-200 dividers, no vertical rules, a caps header in neutral-600, row hover in neutral-50. A number
// cell takes `tableNum` (styles.ts) to sit right-aligned in tabular figures.
const TABLE =
  "w-full border-collapse text-left text-[15px] text-neutral-900 " +
  "[&_thead_th]:h-11 [&_thead_th]:border-b [&_thead_th]:border-neutral-200 [&_thead_th]:px-3 [&_thead_th]:text-[13px] [&_thead_th]:font-semibold [&_thead_th]:uppercase [&_thead_th]:tracking-[0.08em] [&_thead_th]:text-neutral-600 " +
  "[&_tbody_tr]:border-b [&_tbody_tr]:border-neutral-200 [&_tbody_tr:hover]:bg-neutral-50 " +
  "[&_tbody_td]:h-12 [&_tbody_td]:px-3 [&_tbody_th]:h-12 [&_tbody_th]:px-3 [&_tbody_th]:font-medium";

export function Table({ className = "", children, ...props }: TableHTMLAttributes<HTMLTableElement>) {
  return (
    <table className={`${TABLE} ${className}`} {...props}>
      {children}
    </table>
  );
}

// ------------------------------------------------------------------ labels

const TONES = {
  neutral: { fill: "bg-neutral-100 text-neutral-700", dot: "bg-neutral-500" },
  brand: { fill: "bg-brand-50 text-brand-700", dot: "bg-brand-600" },
  positive: { fill: "bg-positive-50 text-positive-700", dot: "bg-positive-600" },
  amber: { fill: "bg-amber-50 text-amber-800", dot: "bg-amber-700" },
  red: { fill: "bg-red-50 text-red-700", dot: "bg-red-700" },
  blue: { fill: "bg-blue-50 text-blue-700", dot: "bg-blue-700" },
  /** money confirmed, and only that (fresh-counter.md 1.3) */
  green: { fill: "bg-green-50 text-green-800", dot: "bg-green-700" },
} as const;

export type Tone = keyof typeof TONES;

/**
 * A pill with an optional 8 px dot. Amber means low or waiting, red out, void, late or an error, blue information or in progress, positive
 * a good or "on" state, brand (the bakery's red) a highlight such as "New", green money confirmed. The words carry the meaning; the tone
 * only adds to it.
 */
export function Chip({ tone = "neutral", dot = false, children, className = "" }: { tone?: Tone; dot?: boolean; children: ReactNode; className?: string }) {
  return (
    <span className={`inline-flex min-h-7 items-center gap-1.5 rounded-full px-3 py-0.5 text-[13px] font-semibold ${TONES[tone].fill} ${className}`}>
      {dot ? <span aria-hidden="true" className={`size-2 shrink-0 rounded-full ${TONES[tone].dot}`} /> : null}
      {children}
    </span>
  );
}

/** A small status label (Phase 0's name for a Chip). */
export function Badge({ tone = "neutral", dot = false, children, className = "" }: { tone?: Tone; dot?: boolean; children: ReactNode; className?: string }) {
  return (
    <Chip tone={tone} dot={dot} className={className}>
      {children}
    </Chip>
  );
}

/** A person: their initials in a circle on ink (no user photos yet, phase-6 plan 10.2 Q15). Beside the name, so hidden from readers. */
export function Avatar({ name, size = "md", className = "" }: { name: string; size?: "sm" | "md" | "lg"; className?: string }) {
  const box = size === "sm" ? "size-7 text-[11px]" : size === "lg" ? "size-12 text-base" : "size-9 text-[13px]";
  return (
    <span aria-hidden="true" translate="no" className={`inline-flex shrink-0 items-center justify-center rounded-full bg-neutral-900 font-semibold text-white ${box} ${className}`}>
      {initialsOf(name)}
    </span>
  );
}

// ------------------------------------------------------------------ product photos

const PLACEHOLDER_ICONS: Record<PlaceholderIcon, LucideIcon> = {
  bread: Wheat,
  cake: CakeSlice,
  sandwich: Sandwich,
  pizza: Pizza,
  cup: Coffee,
  glass: GlassWater,
  plate: UtensilsCrossed,
};

/**
 * A product's photo in a 4:3 frame, or a placeholder that never looks broken (fresh-counter.md section 2). `image` is the API's
 * `{thumb, card, large, width, height}` or null; `name` is the product's name in the current language (the photo's alt); `category` is
 * its category's name in either language (the placeholder's icon); `size` picks the rendition and the `sizes` hint. A photo that fails
 * to load falls back to the placeholder.
 */
export function ProductImage({
  image,
  name,
  category,
  size = "card",
  className = "",
}: {
  image: ProductImageView | null | undefined;
  name: string;
  category?: string | null;
  size?: ProductImageSize;
  className?: string;
}) {
  const src = image ? mediaUrl(image[size]) : null;
  // the URL that failed, so a new photo (a new content-addressed URL) is tried again
  const [failed, setFailed] = useState<string | null>(null);
  const photo = useRef<HTMLImageElement>(null);
  // A photo already in a prerendered page's HTML can fail before React listens for its error event. On mount, an image that reports
  // itself complete without a single pixel is probed once (a lazy one not fetched yet may report the same), and a failed probe counts.
  useEffect(() => {
    const img = photo.current;
    if (!src || !img || !img.complete || img.naturalWidth > 0) return;
    const probe = new Image();
    probe.onerror = () => setFailed(src);
    probe.src = img.currentSrc || src;
    return () => {
      probe.onerror = null;
    };
  }, [src]);
  const frame = `relative block aspect-[4/3] overflow-hidden bg-neutral-100 ${className}`;
  if (!image || !src || failed === src) {
    return (
      <span className={frame}>
        <ProductPlaceholder name={name} category={category} size={size} />
      </span>
    );
  }
  const { width, height } = frameSize(image, size);
  return (
    <span className={frame}>
      {/* a static export serves no image optimiser (next.config images.unoptimized); the renditions are already the right sizes */}
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img
        ref={photo}
        src={src}
        srcSet={srcSetOf(image)}
        sizes={sizesFor(size)}
        width={width}
        height={height}
        alt={name}
        loading="lazy"
        decoding="async"
        onError={() => setFailed(src)}
        className="absolute inset-0 size-full object-cover"
      />
    </span>
  );
}

/** The category's line icon in neutral-400 on the neutral-100 well, with the initials under it where there is room; hidden from readers. */
function ProductPlaceholder({ name, category, size }: { name: string; category?: string | null; size: ProductImageSize }) {
  const Icon = PLACEHOLDER_ICONS[placeholderIcon(category)];
  const initials = size === "thumb" ? "" : initialsOf(name);
  return (
    <span aria-hidden="true" className="absolute inset-0 flex flex-col items-center justify-center gap-1.5 text-neutral-400">
      <Icon className={size === "thumb" ? "size-1/2" : size === "large" ? "size-14" : "size-9"} strokeWidth={ICON_STROKE} aria-hidden="true" />
      {initials ? (
        <span translate="no" className={`font-bold text-neutral-500 ${size === "large" ? "text-base" : "text-[13px]"}`}>
          {initials}
        </span>
      ) : null}
    </span>
  );
}

// ------------------------------------------------------------------ layers

/**
 * A dialog over the screen (the till's pay sheet and void form): named for screen readers, closed by Escape or a tap on
 * the backdrop. The page behind stays as it was; the dialog holds what must be decided before going on.
 */
export function Modal({ label, onClose, size = "md", children }: { label: string; onClose: () => void; size?: "md" | "lg"; children: ReactNode }) {
  useEffect(() => {
    function onKey(event: KeyboardEvent) {
      if (event.key === "Escape") onClose();
    }
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [onClose]);

  return (
    <div className="fixed inset-0 z-40 flex items-end justify-center bg-scrim p-4 sm:items-center" onClick={onClose}>
      <div
        role="dialog"
        aria-modal="true"
        aria-label={label}
        className={`max-h-full w-full ${size === "lg" ? "max-w-3xl" : "max-w-lg"} space-y-4 overflow-y-auto rounded-[20px] bg-white p-6 shadow-float`}
        onClick={(event) => event.stopPropagation()}
      >
        {children}
      </div>
    </div>
  );
}

/**
 * A panel from the side of the screen (step 4.5f: the audit log's entry): a dialog named for screen readers that takes the focus when it
 * opens, closed by Escape or a tap on the backdrop, the list behind it kept as it was. 480 px wide from the right (`lg`: 768 px, for a
 * table of before and after on a tablet); the whole width on a phone.
 */
export function Drawer({ label, onClose, size = "md", children }: { label: string; onClose: () => void; size?: "md" | "lg"; children: ReactNode }) {
  const panel = useRef<HTMLDivElement>(null);
  useEffect(() => {
    panel.current?.focus();
  }, []);
  useEffect(() => {
    function onKey(event: KeyboardEvent) {
      if (event.key === "Escape") onClose();
    }
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [onClose]);

  return (
    <div className="fixed inset-0 z-40 flex justify-end bg-scrim" onClick={onClose}>
      <div
        ref={panel}
        role="dialog"
        aria-modal="true"
        aria-label={label}
        tabIndex={-1}
        className={`h-full w-full ${size === "lg" ? "max-w-3xl" : "max-w-[480px]"} space-y-5 overflow-y-auto bg-white p-4 shadow-float sm:p-6 outline-none motion-safe:animate-drawer-in`}
        onClick={(event) => event.stopPropagation()}
      >
        {children}
      </div>
    </div>
  );
}
