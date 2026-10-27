"use client";

import type { ReactNode } from "react";
import { focusRing } from "../styles";

// The table behind every chart (step 4.5e): the same points, each figure exactly as the API sent it (formatted for reading, never
// recomputed), and a point that was not built said in words. A chart is a picture of this table.

export interface ChartPoint {
  key: string;
  /** what the point is called: a day, a week, an hour, a payment method */
  label: string;
  /** the figure as a decimal string, or null for a point not built */
  value: string | null;
}

export interface ChartTableProps {
  caption: string;
  keyHeading: string;
  valueHeading: string;
  points: readonly ChartPoint[];
  /** how a figure reads (grouped digits, a currency); the default shows it as given */
  format?: (value: string) => string;
  missingText: string;
  /** a second column of figures (the period before), with its heading */
  compare?: { heading: string; values: readonly (string | null)[] };
  className?: string;
}

export function ChartTable({ caption, keyHeading, valueHeading, points, format = (value) => value, missingText, compare, className = "" }: ChartTableProps) {
  const cell = (value: string | null): ReactNode => (value === null ? <span className="text-neutral-500">{missingText}</span> : format(value));
  return (
    <table className={`w-full border-collapse text-left text-base ${className}`}>
      <caption className="sr-only">{caption}</caption>
      <thead>
        <tr className="border-b border-neutral-200 text-sm text-neutral-600">
          <th scope="col" className="py-2 pr-4 font-medium">
            {keyHeading}
          </th>
          <th scope="col" className="py-2 pr-4 text-right font-medium">
            {valueHeading}
          </th>
          {compare ? (
            <th scope="col" className="py-2 pr-4 text-right font-medium">
              {compare.heading}
            </th>
          ) : null}
        </tr>
      </thead>
      <tbody>
        {points.map((point, i) => (
          <tr key={point.key} className="border-b border-neutral-100">
            <th scope="row" className="py-2 pr-4 font-normal text-neutral-900">
              {point.label}
            </th>
            <td className="py-2 pr-4 text-right tabular-nums">{cell(point.value)}</td>
            {compare ? <td className="py-2 pr-4 text-right tabular-nums">{cell(compare.values[i] ?? null)}</td> : null}
          </tr>
        ))}
      </tbody>
    </table>
  );
}

/** The table folded under the chart: open it to read every figure (and for screen readers, the chart's long description). */
export function ChartFallback({ summary, ...table }: ChartTableProps & { summary: string }) {
  return (
    <details className="group mt-3">
      <summary className={`inline-flex min-h-12 cursor-pointer items-center rounded-md px-2 text-base font-medium text-blue-800 hover:bg-blue-50 ${focusRing}`}>{summary}</summary>
      <div className="mt-2 max-h-96 overflow-auto">
        <ChartTable {...table} />
      </div>
    </details>
  );
}
