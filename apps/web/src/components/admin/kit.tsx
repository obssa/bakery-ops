"use client";

import { CircleAlert, Info, Search, X } from "lucide-react";
import { useEffect, useId, useMemo, useRef, useState, type InputHTMLAttributes, type ReactNode, type SelectHTMLAttributes } from "react";
import { Link } from "@/i18n/navigation";
import { useCategories, useItems } from "@/lib/api/hooks";
import type { ProductImageView } from "@/lib/api/types";
import { useProductImages } from "@/lib/productImages";
import { focusRing } from "../styles";
import { ProductImage, Table } from "../ui";

// The patterns every admin list screen shares with the catalog list (step 6.4c, fresh-counter.md 4.3 and 4.4, the CatalogList and Admin
// boards), so Orders, Payments, Inventory and the planner read as one console:
//   - SectionTabs: a section's own pages (Payment sections, Inventory sections) as a named navigation of links in a segment track, the
//     current one brand-50 with brand-700 text, as the catalog's Products and Ingredients switch is drawn;
//   - Toolbar, ToolbarSegments, ToolbarSelect, ToolbarField and SearchBox: the row of filters and actions under the title row, every control
//     44 px tall, the segments 36 px inside their 46 px track;
//   - TableCard: a table on a white card with an 18 px radius (the board's), the 13 px caps header, 48 px rows on hairline dividers, the
//     first and last columns 20 px in, no line under the last row; a title row inside the card when the table has a visible title;
//   - Notice: a line that says something about the screen itself (stale data, a view-only screen), amber for waiting or stale, blue for
//     information, in the Alert's shape; FORM_GRID: a form's two columns from 1024 px; TEXT_LINK: the board's brand-red text link.
// Every word comes from the caller (messages/{en,am}.json).

/** The board's card: white, a neutral-200 hairline, 18 px corners. */
export const CARD = "rounded-[18px] border border-neutral-200 bg-white";

/** A form's fields: two columns from 1024 px, one below (fresh-counter.md 4.4). */
export const FORM_GRID = "grid gap-x-5 gap-y-4 lg:grid-cols-2";

/** A link in the brand red, as the boards draw "All products" and "Add photo". */
export const TEXT_LINK = `rounded-md text-sm font-semibold text-brand-600 hover:text-brand-700 hover:underline ${focusRing}`;

// ------------------------------------------------------------------ the section's own pages

export interface SectionTab {
  href: string;
  label: string;
  current: boolean;
}

/** A section's pages as segmented tabs: a `navigation` named `label`, each page a link, the current one `aria-current="page"`. */
export function SectionTabs({ label, tabs }: { label: string; tabs: readonly SectionTab[] }) {
  return (
    <nav aria-label={label} className="flex max-w-full flex-wrap gap-1 self-start rounded-xl border border-neutral-200 bg-white p-1">
      {tabs.map(({ href, label: name, current }) => (
        <Link
          key={href}
          href={href}
          aria-current={current ? "page" : undefined}
          className={`inline-flex h-9 items-center rounded-[9px] px-3.5 text-sm font-semibold motion-safe:transition-colors motion-safe:duration-150 ${
            current ? "bg-brand-50 text-brand-700" : "text-neutral-700 hover:bg-neutral-100 hover:text-neutral-900"
          } ${focusRing}`}
        >
          {name}
        </Link>
      ))}
    </nav>
  );
}

// ------------------------------------------------------------------ the toolbar

/** The row of a list's filters and actions, under the title row; it wraps on a narrow window. */
export function Toolbar({ children, className = "" }: { children: ReactNode; className?: string }) {
  return <div className={`flex flex-wrap items-center gap-2.5 ${className}`}>{children}</div>;
}

export interface Segment<T extends string> {
  value: T;
  label: string;
  /** a count shown after the label, hidden from readers (the name stays the label) */
  count?: number | null;
}

/** One choice among a few, as the catalog's segments: pressed buttons in a white track, the pressed one ink-100 with ink-900 text (a choice is charcoal, never red). */
export function ToolbarSegments<T extends string>({
  label,
  segments,
  value,
  onChange,
}: {
  label: string;
  segments: readonly Segment<T>[];
  value: T;
  onChange: (value: T) => void;
}) {
  return (
    <div role="group" aria-label={label} className="flex max-w-full flex-wrap gap-1 rounded-xl border border-neutral-200 bg-white p-1">
      {segments.map((segment) => {
        const pressed = segment.value === value;
        return (
          <button
            key={segment.value}
            type="button"
            aria-pressed={pressed}
            onClick={() => onChange(segment.value)}
            className={`inline-flex h-9 items-center gap-1 rounded-[9px] px-3.5 text-sm font-semibold motion-safe:transition-colors motion-safe:duration-150 ${
              pressed ? "bg-ink-100 text-ink-900" : "text-neutral-700 hover:bg-neutral-100 hover:text-neutral-900"
            } ${focusRing}`}
          >
            {segment.label}
            {segment.count !== undefined && segment.count !== null ? (
              <span aria-hidden="true" className="tabular-nums">{`· ${segment.count}`}</span>
            ) : null}
          </button>
        );
      })}
    </div>
  );
}

const BOX =
  "flex h-11 min-w-0 items-center gap-2.5 rounded-xl border border-neutral-300 bg-white px-3.5 " +
  "focus-within:border-ink-900 focus-within:inset-ring-1 focus-within:inset-ring-ink-900 focus-within:ring-4 focus-within:ring-brand-600/35 focus-within:ring-offset-1 " +
  "has-[[aria-invalid=true]]:border-red-700";

/** The catalog's search box: a 44 px field with the search icon, its label for readers, Escape or X to clear it. */
export function SearchBox({
  label,
  placeholder,
  value,
  onChange,
  clearLabel,
  className = "flex-[1_1_260px]",
}: {
  label: string;
  placeholder?: string;
  value: string;
  onChange: (value: string) => void;
  clearLabel: string;
  className?: string;
}) {
  const id = useId();
  return (
    <div className={`${BOX} ${className}`}>
      <Search className="size-[18px] shrink-0 text-neutral-600" strokeWidth={2} aria-hidden="true" />
      <label htmlFor={id} className="sr-only">
        {label}
      </label>
      <input
        id={id}
        type="search"
        value={value}
        onChange={(event) => onChange(event.target.value)}
        onKeyDown={(event) => {
          if (event.key === "Escape") onChange("");
        }}
        placeholder={placeholder}
        autoComplete="off"
        className="min-w-0 flex-1 bg-transparent text-[15px] text-neutral-900 outline-none placeholder:text-neutral-500 [&::-webkit-search-cancel-button]:hidden"
      />
      {value ? (
        <button
          type="button"
          aria-label={clearLabel}
          onClick={() => onChange("")}
          className={`-mr-2 inline-flex size-9 shrink-0 items-center justify-center rounded-lg text-neutral-600 hover:bg-neutral-100 ${focusRing}`}
        >
          <X className="size-4" strokeWidth={2} aria-hidden="true" />
        </button>
      ) : null}
    </div>
  );
}

/**
 * A filter of the toolbar as the catalog's category filter: a 44 px select whose first option says what it shows unfiltered ("All
 * statuses"), named for readers by `label` (and so by getByLabel). `showLabel` sets the label in the box, at the left in neutral-600, for
 * a select that has no "all" choice to say what it is (a place).
 */
export function ToolbarSelect({ label, showLabel = false, className = "", ...props }: SelectHTMLAttributes<HTMLSelectElement> & { label: string; showLabel?: boolean }) {
  const id = useId();
  if (showLabel) {
    return (
      <div className={`${BOX} pr-1 ${className}`}>
        <label htmlFor={id} className="shrink-0 text-[13px] font-semibold text-neutral-600">
          {label}
        </label>
        <select
          id={id}
          className="h-full min-w-0 flex-1 cursor-pointer rounded-lg bg-transparent pr-7 text-sm font-semibold text-neutral-900 outline-none disabled:cursor-not-allowed disabled:text-neutral-500"
          {...props}
        />
      </div>
    );
  }
  return (
    <select
      aria-label={label}
      className={`h-11 max-w-full rounded-xl border border-neutral-300 bg-white pl-3.5 pr-9 text-sm font-semibold text-neutral-900 hover:bg-neutral-50 disabled:cursor-not-allowed disabled:bg-neutral-50 disabled:text-neutral-500 ${focusRing} ${className}`}
      {...props}
    />
  );
}

/**
 * A field of the toolbar that needs its label in sight (a date, an order number): the label inside the 44 px box, at its left in
 * neutral-600, the control after it. An error turns the box red and is said under it, and the control is described by it.
 */
export function ToolbarField({
  label,
  error,
  className = "",
  ...props
}: InputHTMLAttributes<HTMLInputElement> & { label: string; error?: string }) {
  const id = useId();
  const errorId = error ? `${id}-error` : undefined;
  return (
    <div className={`flex min-w-0 flex-col gap-1 ${className}`}>
      <div className={BOX}>
        <label htmlFor={id} className="shrink-0 text-[13px] font-semibold text-neutral-600">
          {label}
        </label>
        <input
          id={id}
          aria-invalid={error ? true : undefined}
          aria-describedby={errorId}
          className="min-w-0 flex-1 bg-transparent text-[15px] tabular-nums text-neutral-900 outline-none placeholder:text-neutral-500"
          {...props}
        />
      </div>
      {error ? (
        <p id={errorId} className="text-[13px] font-medium text-red-700">
          {error}
        </p>
      ) : null}
    </div>
  );
}

/** A filter that is on or off, in a 44 px pill with its box at the left (the label names the checkbox, and getByLabel finds it). */
export function ToolbarCheck({ label, ...props }: Omit<InputHTMLAttributes<HTMLInputElement>, "type"> & { label: string }) {
  return (
    <label className="inline-flex h-11 cursor-pointer items-center gap-2.5 rounded-xl border border-neutral-300 bg-white px-3.5 text-sm font-semibold text-neutral-900 hover:bg-neutral-50 has-[:checked]:border-ink-900 has-[:checked]:bg-ink-50 has-[:checked]:text-ink-900 has-[:focus-visible]:ring-4 has-[:focus-visible]:ring-brand-600/35 has-[:focus-visible]:ring-offset-1">
      <input type="checkbox" className="size-[18px] accent-ink-900 outline-none" {...props} />
      {label}
    </label>
  );
}

// ------------------------------------------------------------------ tables

// The Table primitive's rows and header, with the board's edges: the first and last columns 20 px in, no divider under the last row, a
// little air above and below a cell that wraps, the header on one line.
const ROWS =
  "[&_tbody_tr:last-child]:border-b-0 [&_tbody_td]:py-2.5 [&_tbody_th]:py-2.5 " +
  "[&_tfoot_td]:h-12 [&_tfoot_td]:px-3 [&_tfoot_th]:h-12 [&_tfoot_th]:px-3 [&_tfoot_tr]:border-t [&_tfoot_tr]:border-neutral-200 [&_tfoot_tr]:bg-neutral-50";
const EDGES = `[&_tr>*:first-child]:pl-5 [&_tr>*:last-child]:pr-5 ${ROWS}`;
const ONE_LINE_HEAD = "[&_thead_th]:whitespace-nowrap";
// A wide table of figures (the reconciliation's twelve columns): 8 px between columns and a header that may take two lines, so it fits the
// 1160 px content without scrolling.
const COMPACT = "[&_thead_tr>th]:px-2 [&_tbody_tr>td]:px-2 [&_tbody_tr>th]:px-2 [&_tfoot_tr>*]:px-2 [&_thead_th]:py-2 [&_thead_th]:leading-tight";

/** A table cell that holds a row's actions: as narrow as its buttons, which stay on one line. */
export const ACTIONS_CELL = "w-px whitespace-nowrap text-right";

/**
 * A table on a white card (the CatalogList board). `caption` names the table for readers (getByRole("table", { name })); with a `title` the
 * card is a region named by its heading (17 / 600), with an optional `intro` under it and an `action` on the right, above the table.
 * `children` are the table's thead, tbody and tfoot; `footer` sits under the table inside the card (a pager, an "older" button).
 */
export function TableCard({
  caption,
  title,
  intro,
  action,
  footer,
  minWidth = "min-w-[640px]",
  compact = false,
  className = "",
  children,
}: {
  caption: string;
  title?: string;
  intro?: ReactNode;
  action?: ReactNode;
  footer?: ReactNode;
  minWidth?: string;
  /** many columns of figures: tighter columns, a header that may wrap */
  compact?: boolean;
  className?: string;
  children: ReactNode;
}) {
  const id = useId();
  // a table wider than the window scrolls sideways in its box: the box then takes the keyboard focus (named by the caption), so the
  // arrow keys reach the columns past the edge even when no row holds a button or a link (axe scrollable-region-focusable; phase-6/
  // stock-requests met it on an empty list at 390 px). A box that fits takes no tab stop.
  const scroller = useRef<HTMLDivElement>(null);
  const [scrolls, setScrolls] = useState(false);
  useEffect(() => {
    const box = scroller.current;
    if (!box || typeof ResizeObserver === "undefined") return;
    const measure = () => setScrolls(box.scrollWidth > box.clientWidth + 1);
    measure();
    const observer = new ResizeObserver(measure);
    observer.observe(box);
    if (box.firstElementChild) observer.observe(box.firstElementChild);
    return () => observer.disconnect();
  }, []);
  // relative: a header's visually hidden label (sr-only, absolutely placed) stays inside the scroll box; without it, it is placed by the
  // page and pushes a narrow window into a sideways scroll (Templates at 390 px: 757 px wide)
  const table = (
    <div ref={scroller} className={`relative overflow-x-auto ${scrolls ? "rounded-[inherit] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-inset focus-visible:ring-brand-600/35" : ""}`} {...(scrolls ? { tabIndex: 0, role: "group", "aria-label": caption } : {})}>
      <Table className={`${minWidth} ${compact ? `${COMPACT} [&_tr>*:first-child]:pl-5 [&_tr>*:last-child]:pr-5 ${ROWS}` : `${EDGES} ${ONE_LINE_HEAD}`}`}>
        <caption className="sr-only">{caption}</caption>
        {children}
      </Table>
    </div>
  );
  const foot = footer ? <div className="border-t border-neutral-200 px-5 py-3">{footer}</div> : null;
  if (!title) {
    return (
      <div className={`${CARD} overflow-hidden ${className}`}>
        {table}
        {foot}
      </div>
    );
  }
  return (
    <section aria-labelledby={id} className={`${CARD} overflow-hidden ${className}`}>
      <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 px-5 pb-3 pt-5">
        <div className="min-w-0 space-y-1">
          <h2 id={id} className="text-[17px] font-semibold text-neutral-900">
            {title}
          </h2>
          {intro ? <p className="text-[13px] text-neutral-600">{intro}</p> : null}
        </div>
        {action ? <div className="flex shrink-0 flex-wrap items-center gap-2">{action}</div> : null}
      </div>
      {table}
      {foot}
    </section>
  );
}

/**
 * A table inside a card's body (a purchase's lines, the removed payment methods): the same header and rows as TableCard's, on a
 * neutral-200 hairline with 12 px corners, so it reads as one part of the card it sits in.
 */
export function InnerTable({ caption, minWidth = "min-w-[560px]", children }: { caption: string; minWidth?: string; children: ReactNode }) {
  return (
    <div className="overflow-x-auto rounded-xl border border-neutral-200">
      <Table className={`${minWidth} ${ROWS} [&_tr>*:first-child]:pl-4 [&_tr>*:last-child]:pr-4`}>
        <caption className="sr-only">{caption}</caption>
        {children}
      </Table>
    </div>
  );
}

/** A table's row that says there is nothing to list, across `span` columns. */
export function EmptyRow({ span, children }: { span: number; children: ReactNode }) {
  return (
    <tr>
      <td colSpan={span} className="py-6 text-[15px] text-neutral-600">
        {children}
      </td>
    </tr>
  );
}

// ------------------------------------------------------------------ notices

const NOTICE = {
  amber: { box: "border-amber-200 bg-amber-50 text-amber-900", Icon: CircleAlert },
  blue: { box: "border-blue-200 bg-blue-50 text-blue-800", Icon: Info },
  neutral: { box: "border-neutral-200 bg-neutral-50 text-neutral-700", Icon: Info },
} as const;

/**
 * Something about the screen itself, in the Alert's shape: amber when what is shown is waiting or may be stale, blue for information,
 * neutral for a plain remark (a view-only form). `live` makes it a polite status line, for a notice that comes and goes.
 */
export function Notice({ tone = "amber", live = false, children }: { tone?: keyof typeof NOTICE; live?: boolean; children: ReactNode }) {
  const { box, Icon } = NOTICE[tone];
  return (
    <div role={live ? "status" : undefined} className={`flex items-start gap-3 rounded-xl border px-4 py-3 text-[15px] ${box}`}>
      <Icon className="mt-0.5 size-5 shrink-0" strokeWidth={2} aria-hidden="true" />
      <div className="min-w-0 flex-1">{children}</div>
    </div>
  );
}

// ------------------------------------------------------------------ product thumbs

export interface ProductLook {
  /** the product's display photo, or null for its placeholder */
  image: (itemId: number) => ProductImageView | null;
  /** its category's English name (the placeholder's icon), or null */
  category: (itemId: number) => string | null;
}

/**
 * What a product looks like beside an order line or a request: its photo and its category, from the catalog every role may read (the
 * ["items", "product"] list of useProductImages, and ["categories"]). Until they are read, or offline, every thumb is the placeholder.
 */
export function useProductLook(): ProductLook {
  const images = useProductImages();
  const products = useItems("product");
  const categories = useCategories();
  return useMemo(() => {
    const names = new Map<number, string>((categories.data ?? []).map((c) => [c.id, c.name_en]));
    const byItem = new Map<number, string>();
    for (const item of products.data ?? []) if (item.category_id !== null && names.has(item.category_id)) byItem.set(item.id, names.get(item.category_id) ?? "");
    return { image: (itemId) => images.get(itemId) ?? null, category: (itemId) => byItem.get(itemId) ?? null };
  }, [images, products.data, categories.data]);
}

/** A product's photo as a 4:3 thumb beside its name (hidden from readers: the name is printed next to it); size it with `className`. */
export function ProductThumb({ itemId, name, look, className = "w-12 rounded-lg" }: { itemId: number; name: string; look: ProductLook; className?: string }) {
  return (
    <span aria-hidden="true" className="shrink-0">
      <ProductImage image={look.image(itemId)} name={name} category={look.category(itemId)} size="thumb" className={className} />
    </span>
  );
}

/** A label and its value, as the order record and a purchase list their facts: the label in 13 px caps neutral-600 over the value. */
export function Fact({ term, children }: { term: string; children: ReactNode }) {
  return (
    <div className="min-w-0 space-y-1">
      <dt className="text-[13px] font-semibold uppercase tracking-[0.08em] text-neutral-600">{term}</dt>
      <dd className="text-[15px] text-neutral-900">{children}</dd>
    </div>
  );
}
