"use client";

import { barsLayout, extentOf, zeroLine } from "@/lib/charts";
import { ChartFallback, type ChartPoint } from "./ChartTable";

// Bars as small inline SVG (step 4.5e; no chart library, 10.8 R-H). Vertical bars for a series (days, hours, weeks: the zero line across,
// a shortage below it), horizontal bars for categories (payment methods, order types) with their names and figures beside them. Each bar
// is placed by lib/charts.ts from the decimal string; what the chart says in words (its labels, its table) is the string itself. A point
// not built is a shaded column, never a bar of zero. The SVG is one image with an accessible name; the table under it holds the figures.

export interface ChartTableText {
  /** the fold's button: "Show the figures" */
  summary: string;
  caption: string;
  keyHeading: string;
  valueHeading: string;
  missingText: string;
}

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
}

const ROW = 32; // px per category of a horizontal chart, the same in the SVG and beside it

export function Bars({ label, points, orientation = "vertical", format = (value) => value, table, height = 160 }: BarsProps) {
  const values = points.map((point) => point.value);
  const extent = extentOf(values);
  return (
    <figure className="space-y-2">
      {orientation === "vertical" ? <Vertical label={label} points={points} values={values} height={height} format={format} extent={extent} /> : <Horizontal label={label} points={points} values={values} format={format} />}
      {table ? <ChartFallback {...table} points={points} format={format} /> : null}
    </figure>
  );
}

function Vertical({ label, points, values, height, format, extent }: { label: string; points: readonly ChartPoint[]; values: (string | null)[]; height: number; format: (value: string) => string; extent: { min: string; max: string } }) {
  const width = Math.max(points.length * 24, 240);
  const bars = barsLayout(values, { width, height }, "vertical", extent);
  const zero = zeroLine(extent, height);
  const slot = points.length > 0 ? width / points.length : 0;
  const first = points[0];
  const last = points[points.length - 1];
  return (
    <div>
      <p aria-hidden="true" className="text-sm tabular-nums text-neutral-500">
        {format(extent.max)}
      </p>
      <svg role="img" aria-label={label} viewBox={`0 0 ${width} ${height}`} preserveAspectRatio="none" className="block w-full" style={{ height }}>
        {bars.map((bar, i) =>
          bar.missing ? <rect key={points[i].key} x={i * slot} y={0} width={slot} height={height} className="fill-neutral-100" /> : null,
        )}
        <line x1={0} x2={width} y1={zero} y2={zero} className="stroke-neutral-300" strokeWidth={1} vectorEffect="non-scaling-stroke" />
        {bars.map((bar, i) =>
          bar.missing ? null : <rect key={points[i].key} x={bar.x} y={bar.y} width={bar.width} height={Math.max(bar.height, 0)} className={bar.negative ? "fill-red-500" : "fill-blue-600"} />,
        )}
      </svg>
      {first ? (
        <div aria-hidden="true" className="mt-1 flex justify-between gap-4 text-sm text-neutral-600">
          <span>{first.label}</span>
          {last && last !== first ? <span>{last.label}</span> : null}
        </div>
      ) : null}
    </div>
  );
}

function Horizontal({ label, points, values, format }: { label: string; points: readonly ChartPoint[]; values: (string | null)[]; format: (value: string) => string }) {
  const width = 300;
  const height = Math.max(points.length, 1) * ROW;
  const bars = barsLayout(values, { width, height }, "horizontal");
  return (
    <div className="grid grid-cols-[minmax(6rem,12rem)_1fr_auto] items-start gap-x-3">
      <ul aria-hidden="true" className="text-sm text-neutral-800">
        {points.map((point) => (
          <li key={point.key} className="truncate leading-8" style={{ height: ROW }}>
            {point.label}
          </li>
        ))}
      </ul>
      <svg role="img" aria-label={label} viewBox={`0 0 ${width} ${height}`} preserveAspectRatio="none" className="block w-full" style={{ height }}>
        {bars.map((bar, i) =>
          bar.missing ? (
            <rect key={points[i].key} x={0} y={bar.y} width={width} height={bar.height} className="fill-neutral-100" />
          ) : (
            <rect key={points[i].key} x={bar.x} y={bar.y} width={Math.max(bar.width, 0)} height={bar.height} className={bar.negative ? "fill-red-500" : "fill-blue-600"} />
          ),
        )}
      </svg>
      <ul aria-hidden="true" className="text-right text-sm tabular-nums text-neutral-800">
        {points.map((point) => (
          <li key={point.key} className="leading-8" style={{ height: ROW }}>
            {point.value === null ? "—" : format(point.value)}
          </li>
        ))}
      </ul>
    </div>
  );
}
