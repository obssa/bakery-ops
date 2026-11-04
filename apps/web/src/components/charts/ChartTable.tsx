"use client";

import { ChevronDown } from "lucide-react";
import type { ReactNode } from "react";
import { focusRing } from "../styles";
import { Table } from "../ui";

// The table behind every chart (step 4.5e): the same points, each figure exactly as the API sent it (formatted for reading, never
// recomputed), and a point that was not built said in words. A chart is a picture of this table. Step 6.4d: the admin Table (13 px caps
// header, hairline rows, figures right-aligned in tabular numbers) inside a hairline frame, folded under its chart behind a brand link.

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

const NUM = "whitespace-nowrap text-right tabular-nums";

export function ChartTable({ caption, keyHeading, valueHeading, points, format = (value) => value, missingText, compare, className = "" }: ChartTableProps) {
  const cell = (value: string | null): ReactNode => (value === null ? <span className="text-neutral-600">{missingText}</span> : format(value));
  return (
    <Table className={`[&_tr>*:first-child]:pl-4 [&_tr>*:last-child]:pr-4 [&_tbody_tr:last-child]:border-b-0 ${className}`}>
      <caption className="sr-only">{caption}</caption>
      <thead>
        <tr>
          <th scope="col">{keyHeading}</th>
          <th scope="col" className={NUM}>
            {valueHeading}
          </th>
          {compare ? (
            <th scope="col" className={NUM}>
              {compare.heading}
            </th>
          ) : null}
        </tr>
      </thead>
      <tbody>
        {points.map((point, i) => (
          <tr key={point.key}>
            <th scope="row" className="!font-normal text-neutral-900">
              {point.label}
            </th>
            <td className={NUM}>{cell(point.value)}</td>
            {compare ? <td className={NUM}>{cell(compare.values[i] ?? null)}</td> : null}
          </tr>
        ))}
      </tbody>
    </Table>
  );
}

/** The table folded under the chart: open it to read every figure (and for screen readers, the chart's long description). */
export function ChartFallback({ summary, ...table }: ChartTableProps & { summary: string }) {
  return (
    <details className="group">
      <summary
        className={`inline-flex min-h-11 cursor-pointer list-none items-center gap-1.5 rounded-lg px-1 text-sm font-semibold text-brand-600 hover:text-brand-700 [&::-webkit-details-marker]:hidden ${focusRing}`}
      >
        {summary}
        <ChevronDown className="size-4 motion-safe:transition-transform group-open:rotate-180" strokeWidth={2} aria-hidden="true" />
      </summary>
      <div className="mt-2 max-h-96 overflow-auto rounded-xl border border-neutral-200">
        <ChartTable {...table} />
      </div>
    </details>
  );
}
