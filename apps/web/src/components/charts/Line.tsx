"use client";

import { extentOf, lineSegments, pathOf, zeroLine } from "@/lib/charts";
import type { ChartTableText } from "./Bars";
import { ChartFallback, type ChartPoint } from "./ChartTable";

// A line over a series as small inline SVG (step 4.5e; no chart library, 10.8 R-H): the range's figures, and with a comparison the period
// before as a dashed line over the same scale. A point not built breaks the line and shades its column (never drawn as zero); a figure
// standing alone between two gaps is a dot. Positions come from lib/charts.ts; the labels and the table under it are the strings as given.

interface LineProps {
  label: string;
  points: readonly ChartPoint[];
  /** the period before, point for point (the same length), drawn dashed */
  compare?: { values: readonly (string | null)[]; heading: string };
  format?: (value: string) => string;
  /** the folded table of the figures; left out only where the page shows the same figures in a table right beside the chart */
  table?: ChartTableText;
  height?: number;
}

const WIDTH = 600;

export function Line({ label, points, compare, format = (value) => value, table, height = 180 }: LineProps) {
  const values = points.map((point) => point.value);
  const extent = extentOf([...values, ...(compare?.values ?? [])]);
  const box = { width: WIDTH, height };
  const current = lineSegments(values, box, extent);
  const before = compare ? lineSegments(compare.values, box, extent) : [];
  const zero = zeroLine(extent, height);
  const step = points.length > 1 ? WIDTH / (points.length - 1) : WIDTH;
  const first = points[0];
  const last = points[points.length - 1];

  return (
    <figure className="space-y-2">
      <p aria-hidden="true" className="text-sm tabular-nums text-neutral-500">
        {format(extent.max)}
      </p>
      <svg role="img" aria-label={label} viewBox={`0 0 ${WIDTH} ${height}`} preserveAspectRatio="none" className="block w-full overflow-visible" style={{ height }}>
        {points.map((point, i) =>
          point.value === null ? (
            <rect key={point.key} x={points.length > 1 ? Math.max(0, i * step - step / 2) : 0} y={0} width={points.length > 1 ? step : WIDTH} height={height} className="fill-neutral-100" />
          ) : null,
        )}
        <line x1={0} x2={WIDTH} y1={zero} y2={zero} className="stroke-neutral-300" strokeWidth={1} vectorEffect="non-scaling-stroke" />
        {before.map((segment, i) => (
          <path key={`before-${i}`} d={segment.length === 1 ? `${pathOf(segment)} L${segment[0].x} ${segment[0].y}` : pathOf(segment)} className="fill-none stroke-neutral-400" strokeWidth={segment.length === 1 ? 6 : 2} strokeDasharray={segment.length === 1 ? undefined : "6 4"} strokeLinecap="round" vectorEffect="non-scaling-stroke" />
        ))}
        {current.map((segment, i) => (
          <path key={`now-${i}`} d={segment.length === 1 ? `${pathOf(segment)} L${segment[0].x} ${segment[0].y}` : pathOf(segment)} className="fill-none stroke-blue-700" strokeWidth={segment.length === 1 ? 7 : 2.5} strokeLinecap="round" strokeLinejoin="round" vectorEffect="non-scaling-stroke" />
        ))}
      </svg>
      {first ? (
        <div aria-hidden="true" className="flex justify-between gap-4 text-sm text-neutral-600">
          <span>{first.label}</span>
          {last && last !== first ? <span>{last.label}</span> : null}
        </div>
      ) : null}
      {table ? <ChartFallback {...table} points={points} format={format} compare={compare} /> : null}
    </figure>
  );
}
