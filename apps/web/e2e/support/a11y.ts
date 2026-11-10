import AxeBuilder from "@axe-core/playwright";
import { expect, type Page } from "@playwright/test";

// The accessibility gate of Phase 6 (p6-a11y; phase-6 plan D7, section 7 and step 6.2b; ADR 0023): axe, with the WCAG 2.0 and 2.1 rules of
// levels A and AA, finds no serious or critical violation on a screen. Moderate and minor findings are not the gate's, so they never fail a
// run. Each step that restyles a screen adds it to e2e/p6/a11y.spec.ts in English and in Amharic through `expectAccessible`.
//
// The contrast rule reads the font a text is drawn in, so the check waits for document.fonts.ready first: Inter and Noto Sans Ethiopic are
// self-hosted (fresh-counter.md 1.4) and arrive after the first paint, and a fallback font measured instead would give other numbers.

/** The axe tags the gate runs: WCAG 2.0 and 2.1, levels A and AA. */
export const A11Y_TAGS = ["wcag2a", "wcag2aa", "wcag21a", "wcag21aa"] as const;

/** The impacts that fail the gate. */
export const BLOCKING_IMPACTS: readonly string[] = ["serious", "critical"];

export interface A11yFinding {
  rule: string;
  impact: string;
  help: string;
  /** the selectors of the elements axe names, at most five */
  targets: string[];
}

/** Resolves once every font the page asked for has loaded (or failed), so text is measured in the font it is drawn in. */
export async function fontsReady(page: Page): Promise<void> {
  await page.evaluate(async () => {
    await document.fonts.ready;
  });
}

/**
 * Resolves once every animation and transition that ends has ended (step 6.3a): a colour measured half way through a 150 ms transition (a
 * button just enabled, a sheet sliding in) is neither the colour before nor after, so axe's contrast check would read a colour the screen
 * never rests on. Endless ones (a skeleton's pulse) are not waited for, nor those on an element the page does not draw: a switch that
 * changes inside a closed <details> (the new item page's "More options") starts a transition the browser never runs, so it never ends.
 */
export async function settledAnimations(page: Page): Promise<void> {
  await page.evaluate(async () => {
    const drawn = (animation: Animation): boolean => {
      const target = animation.effect instanceof KeyframeEffect ? animation.effect.target : null;
      return !target || target.checkVisibility();
    };
    const ending = document.getAnimations().filter((animation) => animation.effect?.getTiming().iterations !== Infinity && drawn(animation));
    await Promise.all(ending.map((animation) => animation.finished.catch(() => undefined)));
  });
}

/** The serious and critical violations axe finds on the page as it is now; an empty list on a screen that passes. */
export async function axeFindings(page: Page, options: { exclude?: string[] } = {}): Promise<A11yFinding[]> {
  await fontsReady(page);
  // the screens' transitions are all written under motion-safe: with reduced motion they are off, so every colour is the one at rest;
  // anything still running (a transition already started) is waited for
  await page.emulateMedia({ reducedMotion: "reduce" });
  await settledAnimations(page);
  let builder = new AxeBuilder({ page }).withTags([...A11Y_TAGS]);
  for (const selector of options.exclude ?? []) builder = builder.exclude(selector);
  const { violations } = await builder.analyze();
  return violations
    .filter((violation) => BLOCKING_IMPACTS.includes(violation.impact ?? ""))
    .map((violation) => ({
      rule: violation.id,
      impact: violation.impact ?? "",
      help: violation.help,
      targets: violation.nodes.slice(0, 5).map((node) => node.target.join(" ")),
    }));
}

/** One line per finding, for the failure message. */
export function describeFindings(findings: A11yFinding[]): string[] {
  return findings.map(({ rule, impact, help, targets }) => `${impact} ${rule}: ${help} (${targets.join(", ")})`);
}

/** Fails the test when axe finds a serious or critical violation on the page, naming every one of them. */
export async function expectAccessible(page: Page, screen: string, options: { exclude?: string[] } = {}): Promise<void> {
  const findings = await axeFindings(page, options);
  expect(findings, `serious or critical axe violations on ${screen}:\n${describeFindings(findings).join("\n")}`).toEqual([]);
}
