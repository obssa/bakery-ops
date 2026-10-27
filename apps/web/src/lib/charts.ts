import { compareDecimal, decimalRatio, subtractDecimal } from "./decimal";

// The geometry of the dashboards' charts (step 4.5e; no chart library, phase-4 plan 10.8 R-H): components/charts/{Sparkline,Bars,Line}.tsx
// draw what these pure functions lay out. A figure arrives as the API's decimal string and stays one: it is placed by its share of the
// scale, worked on scaled integers (decimal.ts decimalRatio), and the only numbers made are pixel positions, rounded to 0.01 px. What a
// chart shows as text (its table, its labels) is the string itself. A point that is null (a day not built) is never drawn as zero: a bar
// is marked missing, a line breaks.

export interface ChartBox {
  width: number;
  height: number;
}

/** The scale of a chart: from the smallest to the largest figure, zero always inside it (a bar starts at zero). */
export interface Extent {
  min: string;
  max: string;
}

/** The resolution of a position: a figure is placed to one part in ten thousand of the chart. */
const STEPS = 10_000;

function round2(value: number): number {
  return Math.round(value * 100) / 100;
}

export function extentOf(values: readonly (string | null)[]): Extent {
  let min = "0";
  let max = "0";
  for (const value of values) {
    if (value === null) continue;
    if (compareDecimal(value, max) > 0) max = value;
    if (compareDecimal(value, min) < 0) min = value;
  }
  return { min, max };
}

/** Where a figure sits on the scale, in steps from the top (the largest figure) to the bottom (the smallest); null on a flat scale. */
function fromTop(value: string, extent: Extent): number | null {
  const span = subtractDecimal(extent.max, extent.min, 4);
  if (compareDecimal(span, "0") <= 0) return null;
  return decimalRatio(subtractDecimal(extent.max, value, 4), span, STEPS);
}

export interface Bar {
  x: number;
  y: number;
  width: number;
  height: number;
  /** the figure as given, or null for a point not built */
  value: string | null;
  missing: boolean;
  negative: boolean;
}

/**
 * Bars over a common scale: vertical (a series, the zero line across the chart) or horizontal (categories, the zero line at the left for
 * figures that are not negative). Each bar takes 80 % of its slot, centred; a missing figure is a bar of no size on the zero line.
 */
export function barsLayout(values: readonly (string | null)[], box: ChartBox, orientation: "vertical" | "horizontal" = "vertical", extent: Extent = extentOf(values)): Bar[] {
  const vertical = orientation === "vertical";
  const length = vertical ? box.height : box.width; // the axis the figures run along
  const across = vertical ? box.width : box.height;
  const slot = values.length > 0 ? across / values.length : 0;
  const zeroTop = fromTop("0", extent);
  // along a vertical axis a position is measured from the top; along a horizontal one from the left (the smallest figure)
  const at = (steps: number | null): number => {
    const fromStart = steps === null ? STEPS : steps;
    return round2((length * (vertical ? fromStart : STEPS - fromStart)) / STEPS);
  };
  const zero = at(zeroTop);

  return values.map((value, i) => {
    const offset = round2(i * slot + slot * 0.1);
    const thickness = round2(slot * 0.8);
    let start = zero;
    let size = 0;
    const negative = value !== null && compareDecimal(value, "0") < 0;
    if (value !== null && zeroTop !== null) {
      const position = at(fromTop(value, extent));
      start = Math.min(position, zero);
      size = round2(Math.abs(position - zero));
    }
    return vertical
      ? { x: offset, y: start, width: thickness, height: size, value, missing: value === null, negative }
      : { x: start, y: offset, width: size, height: thickness, value, missing: value === null, negative };
  });
}

export interface Point {
  x: number;
  y: number;
  value: string;
}

/** A line over a common scale, cut into segments where a point is null (a day not built is a gap, never a zero). */
export function lineSegments(values: readonly (string | null)[], box: ChartBox, extent: Extent = extentOf(values)): Point[][] {
  const segments: Point[][] = [];
  let current: Point[] = [];
  values.forEach((value, i) => {
    if (value === null) {
      if (current.length > 0) segments.push(current);
      current = [];
      return;
    }
    const steps = fromTop(value, extent);
    const x = values.length === 1 ? round2(box.width / 2) : round2((i * box.width) / (values.length - 1));
    const y = steps === null ? box.height : round2((box.height * steps) / STEPS);
    current.push({ x, y, value });
  });
  if (current.length > 0) segments.push(current);
  return segments;
}

/** An SVG path of a segment: "M0 75 L100 0". */
export function pathOf(segment: readonly Point[]): string {
  return segment.map((point, i) => `${i === 0 ? "M" : "L"}${point.x} ${point.y}`).join(" ");
}

/** Where the zero line of a vertical chart is, from the top. */
export function zeroLine(extent: Extent, height: number): number {
  const steps = fromTop("0", extent);
  return steps === null ? height : round2((height * steps) / STEPS);
}
