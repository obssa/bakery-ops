"use client";

import { lineSegments, pathOf } from "@/lib/charts";
import type { ChartTableText } from "./Bars";
import { ChartTable, type ChartPoint } from "./ChartTable";

// A sparkline as small inline SVG (step 4.5e; no chart library, 10.8 R-H): the shape of a figure over the range inside a KPI tile, no
// axes. One image with an accessible name; its table is there for screen readers (visually hidden: the tile already says the total). A
// point not built breaks the line.

interface SparklineProps {
  label: string;
  points: readonly ChartPoint[];
  format?: (value: string) => string;
  table: Omit<ChartTableText, "summary">;
}

const WIDTH = 160;
const HEIGHT = 36;

export function Sparkline({ label, points, format, table }: SparklineProps) {
  const segments = lineSegments(
    points.map((point) => point.value),
    { width: WIDTH, height: HEIGHT },
  );
  return (
    <figure className="mt-2">
      <svg role="img" aria-label={label} viewBox={`0 0 ${WIDTH} ${HEIGHT}`} preserveAspectRatio="none" className="block h-9 w-full overflow-visible">
        {segments.map((segment, i) => (
          <path key={i} d={segment.length === 1 ? `${pathOf(segment)} L${segment[0].x} ${segment[0].y}` : pathOf(segment)} className="fill-none stroke-blue-600" strokeWidth={segment.length === 1 ? 5 : 2} strokeLinecap="round" strokeLinejoin="round" vectorEffect="non-scaling-stroke" />
        ))}
      </svg>
      <div className="sr-only">
        <ChartTable {...table} points={points} format={format} />
      </div>
    </figure>
  );
}
