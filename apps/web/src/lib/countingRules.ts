import type { CountingRule, CountingRulesView } from "./api/types";

// The counting rules (Admin > System > Counting rules, Ramin 2026-11-12, ADR 0025): per station, the admin switches the opening count, the
// co-signature and the manager's approval. The page shows one card per rule with an "All stations" switch over the stations' own switches;
// this file is the arithmetic of that page, kept free of React so the logic spec can pin it.

/** The three rules in the order the page shows them: what happens first in a shift, first. */
export const RULES: readonly CountingRule[] = ["opening_count", "cosign", "approval"];

/** Where a rule stands across the stations: on at every one, off at every one, or on at some. */
export type RuleState = "on" | "off" | "some";

export function ruleState(view: CountingRulesView, rule: CountingRule): RuleState {
  const on = view.stations.filter((station) => station[rule]).length;
  if (view.stations.length > 0 && on === view.stations.length) return "on";
  return on === 0 ? "off" : "some";
}

/** One station's change as PUT /counting-rules takes it. */
export type RuleChange = { id: number } & Partial<Record<CountingRule, boolean>>;

/** The changes that switch a rule on or off at every station: only the stations it is not already so at (an empty list changes nothing). */
export function switchAll(view: CountingRulesView, rule: CountingRule, on: boolean): RuleChange[] {
  return view.stations.filter((station) => station[rule] !== on).map((station) => ({ id: station.id, [rule]: on }));
}

/** The change that switches a rule at one station. */
export function switchOne(stationId: number, rule: CountingRule, on: boolean): RuleChange[] {
  return [{ id: stationId, [rule]: on }];
}

/** The view as it will be once the changes land: what the page shows at once, before the server answers (and rolls back to on a refusal). */
export function applyChanges(view: CountingRulesView, changes: readonly RuleChange[]): CountingRulesView {
  const byId = new Map(changes.map((change) => [change.id, change]));
  return {
    ...view,
    stations: view.stations.map((station) => {
      const change = byId.get(station.id);
      if (!change) return station;
      const next = { ...station };
      for (const rule of RULES) {
        const value = change[rule];
        if (value !== undefined) next[rule] = value;
      }
      return next;
    }),
  };
}

/** Does a shift at this station start by counting? Yes unless its opening count is switched off (an API without the rules counts, as before). */
export function countsAtOpening(station: { counting_rules?: { opening_count: boolean } } | null | undefined): boolean {
  return station?.counting_rules?.opening_count ?? true;
}
