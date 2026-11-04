"use client";

import { barsLayout, extentOf, zeroLine } from "@/lib/charts";
import { compareDecimal } from "@/lib/decimal";
import { ChartFallback, type ChartPoint } from "./ChartTable";

// Bars, drawn from lib/charts.ts (step 4.5e; no chart library, 10.8 R-H). Vertical bars for a series (days, hours, weeks: the zero line
// across, a shortage below it), horizontal bars for categories (payment methods, order types) with their names and figures beside them.
// Each bar is placed from the decimal string; what the chart says in words (its labels, its table) is the string itself. A point not
// built is a shaded column, never a bar of zero. The picture is one image with an accessible name; the table under it holds the figures.
//
// Step 6.4d, in the dataviz style of Today's "Sales by hour" (fresh-counter.md 4.2, the Admin board): the highlighted bar brand-600 and
// the others brand-100, rounded at their data end and square on the zero line, no gradient; a shortage red; the scale's top and the first
// and last labels in neutral-600; a horizontal bar is Today's share bar, brand-600 on a neutral-100 track. Laid out in percent on plain
// boxes, so a round end stays round at any width; each bar says its label and figure on hover.

export interface ChartTableText {
  /** the fold's button: "Show the figures" */
  summary: string;
  caption: string;
  keyHeading: string;
  valueHeading: string;
  missingText: string;
}

/** Which bar of a vertical series is drawn in brand-600: the last one (the latest day, as Today's hour now), the largest, or none. */
export type BarHighlight = "last" | "peak" | "none";

interface BarsProps {
  /** the accessible name of the image: what it shows, over which range */
  label: string;
  points: readonly ChartPoint[];
  orientation?: "vertical" | "horizontal";
  /** how a figure reads beside a bar and in the table */
  format?: (value: string) => string;
  /** the folded table of the figures; left out only where the page shows the same figures in a table right beside the chart */
  table?: ChartTableText;
  /** a vertical chart's height in px */
  height?: number;
  highlight?: BarHighlight;
}

export function Bars({ label, points, orientation = "vertical", format = (value) => value, table, height = 160, highlight = "last" }: BarsProps) {
  return (
    <figure className="space-y-3">
      {orientation === "vertical" ? <Vertical label={label} points={points} height={height} format={format} highlight={highlight} /> : <Horizontal label={label} points={points} format={format} />}
      {table ? <ChartFallback {...table} points={points} format={format} /> : null}
    </figure>
  );
}

/** The index of the highlighted bar, or -1. */
function highlighted(points: readonly ChartPoint[], highlight: BarHighlight): number {
  if (highlight === "none") return -1;
  if (highlight === "last") {
    for (let i = points.length - 1; i >= 0; i -= 1) if (points[i].value !== null) return i;
    return -1;
  }
  let peak = -1;
  points.forEach((point, i) => {
    if (point.value === null || compareDecimal(point.value, "0") <= 0) return;
    if (peak === -1 || compareDecimal(point.value, points[peak].value as string) > 0) peak = i;
  });
  return peak;
}

function Vertical({ label, points, height, format, highlight }: { label: string; points: readonly ChartPoint[]; height: number; format: (value: string) => string; highlight: BarHighlight }) {
  const values = points.map((point) => point.value);
  const extent = extentOf(values);
  // laid out on a 100 x 100 box, so every position is a percent of the plot
  const bars = barsLayout(values, { width: 100, height: 100 }, "vertical", extent);
  const zero = zeroLine(extent, 100);
  const slot = points.length > 0 ? 100 / points.length : 0;
  const lit = highlighted(points, highlight);
  const first = points[0];
  const last = points[points.length - 1];
  // a few points (the weeks since a launch) keep bars of a readable width instead of one bar across the page
  return (
    <div style={{ maxWidth: Math.max(points.length * 72, 240) }}>
      <p aria-hidden="true" className="mb-1 text-[13px] tabular-nums text-neutral-600">
        {format(extent.max)}
      </p>
      <div role="img" aria-label={label} className="relative w-full" style={{ height }}>
        {bars.map((bar, i) =>
          bar.missing ? <div key={`gap-${points[i].key}`} className="absolute inset-y-0 rounded-md bg-neutral-100" style={{ left: `${i * slot}%`, width: `${slot}%` }} /> : null,
        )}
        <div aria-hidden="true" className="absolute inset-x-0 border-t border-neutral-200" style={{ top: `${zero}%` }} />
        {bars.map((bar, i) => {
          if (bar.missing || bar.height <= 0) return null;
          const tone = bar.negative ? (i === lit ? "bg-red-700" : "bg-red-300") : i === lit ? "bg-brand-600" : "bg-brand-100";
          return (
            <div
              key={points[i].key}
              title={`${points[i].label}: ${format(points[i].value as string)}`}
              className={`absolute ${bar.negative ? "rounded-b-md" : "rounded-t-md"} ${tone}`}
              style={{ left: `${bar.x}%`, width: `${bar.width}%`, top: `${bar.y}%`, height: `max(${bar.height}%, 2px)` }}
            />
          );
        })}
      </div>
      {first ? (
        <div aria-hidden="true" className="mt-2 flex justify-between gap-4 text-[13px] tabular-nums text-neutral-600">
          <span>{first.label}</span>
          {last && last !== first ? <span>{last.label}</span> : null}
        </div>
      ) : null}
    </div>
  );
}

function Horizontal({ label, points, format }: { label: string; points: readonly ChartPoint[]; format: (value: string) => string }) {
  const values = points.map((point) => point.value);
  const bars = barsLayout(values, { width: 100, height: Math.max(points.length, 1) }, "horizontal");
  return (
    <div role="img" aria-label={label}>
      <ul className="flex flex-col gap-3.5">
        {points.map((point, i) => {
          const bar = bars[i];
          return (
            <li key={point.key} className="flex flex-col gap-1.5">
              <span className="flex items-baseline justify-between gap-3 text-sm">
                <span className="min-w-0 truncate font-semibold text-neutral-900">{point.label}</span>
                <span className="shrink-0 tabular-nums text-neutral-600">{point.value === null ? "—" : format(point.value)}</span>
              </span>
              <span className="block h-2.5 overflow-hidden rounded-full bg-neutral-100">
                {bar && !bar.missing && bar.width > 0 ? (
                  <span className={`block h-2.5 rounded-full ${bar.negative ? "bg-red-700" : "bg-brand-600"}`} style={{ marginLeft: `${bar.x}%`, width: `${bar.width}%` }} />
                ) : null}
              </span>
            </li>
          );
        })}
      </ul>
    </div>
  );
}
