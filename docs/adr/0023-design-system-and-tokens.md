# ADR 0023: The design system and its tokens

- Status: **proposed** (step 6.2a, 2026-11-03, from phase-6 plan D6, D7, sections 6.0.2 and 9, and `docs/design/fresh-counter.md`). It is
  accepted at the Phase 6 gate (step 6.5), once steps 6.2b to 6.4d have moved every screen onto it.
- Date: 2026-11-03
- Phase: 6

## Context

Phases 0 to 5 built 53 route files on a handful of primitives (`components/ui.tsx`: `Button`, `Field`, `TextInput`, `Select`, `Alert`,
`Panel`, `Badge`, `Modal`, `Drawer`, ...), in Tailwind's stock `neutral` greys with a blue accent, and Noto Sans Ethiopic as the only
typeface for both scripts. Many screens also carried their own colours (blue selected states, green notices). The result worked and was
tested, but it was plain, and it did not look like one product. Ramin approved a design system, "Fresh Counter", on 2026-11-03
(`docs/design/fresh-counter.md`, from a design canvas in his account). Four constraints shape how it lands:

- **53 routes, one step at a time.** Phase 6 restyles them over eleven PRs (plan 6.0.1). The look has to change everywhere at once and
  then be refined screen by screen, without a big-bang PR.
- **The p0 to p5 Playwright projects are the regression guard.** They select by role, accessible name, test id and visible copy. A
  redesign that renames a button breaks a gate that has nothing to do with looks.
- **The shell is a static export that must work offline** (ADR 0006, ADR 0018). A tablet on a dropped Wi-Fi cannot fetch a font, and
  nothing about a sale should leave the shop for a third party.
- **Two scripts.** Amharic labels run about 30 % longer than English, and Ge'ez glyphs are taller than Latin ones.

## Decision

1. **The tokens live in `apps/web/src/app/globals.css`, in Tailwind 4's `@theme`**, exactly as the spec's section 1 lists them, and
   components use the Tailwind names they make (`bg-ground`, `text-brand-700`, `bg-night-surface`, `shadow-float`, ...), never a hex
   value:
   - `--color-neutral-50..950` **remapped** to the design's cooler greys, so every existing `neutral-*` class moves at once without an
     edit; `--color-ground` for the page behind the surfaces (`body` sits on it);
   - `--color-brand-50..900`, the teal accent;
   - `--color-night-*` for the kitchen display (ground, surface, border, divider, text, text-muted, accent, accent-ink, the fresh,
     waiting and late timer fills and texts, note): the one dark screen has its own tokens, not a dark mode;
   - `--font-sans` with Inter first, `--shadow-float` for floating layers, and `--animate-skeleton` / `--animate-drawer-in`, used only
     under `motion-safe:` (a reduced-motion rule turns off anything that slips through).
2. **What a colour may mean.** Brand teal is "go" and the current choice (primary buttons, the focus ring, links, selected states).
   Amber means low or waiting, red out, void, late or an error, blue information or in progress, and green is kept for money confirmed.
   Two states are never told apart by hue alone: each carries a word or an icon. `neutral-400` is for disabled text and placeholder
   icons only; text that carries information is at least `neutral-600` (5.9:1 on white), so step 6.2a moved the two `neutral-400`
   markers that did (the audit entry's "missing" dash, the roster's empty day) to `neutral-600`.
3. **The fonts are self-hosted.** `@fontsource-variable/inter` (5.3.0) and `@fontsource-variable/noto-sans-ethiopic` (5.3.0) are
   imported by the layouts (`[locale]/layout.tsx`, the start page, the bare 404) and bundled under `/app/_next/static/media`, where the
   service worker's shell cache keeps them. Inter draws Latin and tabular figures; Ethiopic glyphs fall through to Noto, so a mixed line
   renders in both. **Nothing is loaded from Google Fonts**: `web-build` fails an export that names `fonts.googleapis.com` or
   `fonts.gstatic.com`, or that lacks either font's files.
4. **The primitives keep their names and props, so screens restyle without edits.** `Button` (variants `primary`, `secondary`,
   `ghost`, `danger`, `dark`; sizes `md` 44 px, `lg` 52 px, `xl` 64 px; `compact`; `loading` with a spinner that keeps the width and the
   accessible name), `Field`, `TextInput`, `Textarea`, `Select`, `Alert`, `Status`, `Panel`, `PageHeader`, `Badge`, `Modal` and `Drawer`
   are restyled in place. A `Button` without a size keeps the 48 px it has had since Phase 0, so no screen's layout moves until its own
   step picks a size. Added beside them: `IconButton`, `Chip`, `MoneyInput`, `Switch` (a real `role="switch"`), `SegmentedControl`,
   `Card`, `Stat`, `Avatar`, `EmptyState`, `Skeleton`, `Table` and `ProductImage`. The class strings a server component or a link that
   looks like a button needs (`focusRing`, `buttonClass`, `controlClass`, `surface`, `tableNum`) stay in `components/styles.ts`, a plain
   module, because an export of a `"use client"` module is a client reference on the server (plan 10.5 item 5).
5. **Icons come from `lucide-react`, pinned** (1.59.0): line icons at 1.75 to 2 px stroke, 18 to 22 px, `aria-hidden`; an icon-only
   control always has an `aria-label` (`IconButton` takes it as a required `label`).
6. **`ProductImage` is the one way a product photo reaches a screen.** It takes the API's `{thumb, card, large, width, height} | null`
   (`ProductImageView`, ADR 0022), draws a 4:3 frame with `object-cover`, a `srcSet` over the renditions at their real widths (the large
   one at the width the API reports, never an upscaled one, and no width listed twice), a `sizes` hint per size, `loading="lazy"`,
   `decoding="async"`, `width` and `height`, and the product's name in the current language as `alt`. Without a photo, or when one fails to
   load, it draws a placeholder that never looks broken: the category's line icon (bread, cake, sandwich, pizza, cup, glass, or a plate),
   chosen from the category's name in either language, and the product's initials, all `aria-hidden` because the name is printed beside
   it. The logic lives in `lib/images/productImage.ts` so it is tested without a browser.
7. **The selector rule keeps p0 to p5 the regression guard** (plan 6.0.2): accessible names, roles, test ids and visible copy do not
   change; new decorative text that would change a name is `aria-hidden`; a selector that truly has to change is named in the step's PR
   with its reason, and changed in the same commit as the screen.
8. **Accessibility is gated by axe**, not by eye: `@axe-core/playwright` (4.14.0, pinned) runs in `p6-a11y` from step 6.2b on the main
   screens in `en` and `am`, with the `wcag2a`, `wcag2aa`, `wcag21a` and `wcag21aa` tags, and fails on any serious or critical
   violation, contrast included, after `document.fonts.ready`.
9. **No golden screenshots.** Fonts render differently on each runner (trap "Golden images"); the look is reviewed by Ramin on the live
   URL after each step deploys.

## Consequences

- Step 6.2a changes the look of every screen at once (greys, the teal primary, Inter, 12 to 16 px corners, the focus ring), with no
  change of layout or copy. Screens that write their own accent classes (blue selected states, blue links, green notices) keep them until
  the step that rebuilds that screen (6.2b to 6.4d, plan 6.0.1), so for a few PRs a screen can show a teal button beside a blue tab.
- The shell cache grows by Inter's seven subset files, about 220 KB once per tablet; a tablet only ever draws the Latin ones.
- Three dependencies, pinned at versions at least two weeks old when added: `@fontsource-variable/inter`, `lucide-react`, and
  `@axe-core/playwright` (dev only).
- Every new screen part is built from the primitives: a screen that needs a look they do not have extends `ui.tsx` or `styles.ts`, it
  does not write its own colours.
- `Drawer` is 480 px wide by default, as the spec says; the audit log's entry, which shows a table of before and after, asks for `lg`
  (768 px), the width it had before.

## Enforcement

- `web-build`: the static export names no Google Fonts host and carries both fonts' files.
- `e2e/p6/product-photo-logic.spec.ts` (in `p6-product-photo`): the `srcSet` and its never-upscaled widths, the placeholder icon for every
  demo category in both languages and for none, initials in English and Amharic.
- `p0` to `p5`, unchanged, on every PR: the selector rule.
- `p6-a11y` (from step 6.2b): axe, serious and critical violations fail.
- `web-lint`: `no-literal-text` (every word from the message files, rule 9) and `tsc`.

## Alternatives considered

- **Keep Noto Sans Ethiopic for both scripts.** It covers Latin, but its Latin figures are not tabular and read smaller at arm's
  length; Inter is drawn for screens and has tabular figures for every amount, count and timer.
- **Load the fonts from Google Fonts.** One line of CSS, but a tablet offline gets a fallback font, the first paint waits on a third
  party, and every page view tells Google the shop is open. Self-hosting costs about 220 KB in the shell cache once.
- **A component library (Radix, shadcn/ui, MUI).** It would bring its own markup, roles and names, which the selector rule forbids
  changing, and a bundle a shared host and a pad on a weak line pay for. The handful of primitives here are small and already accessible.
- **A `dark:` theme for the kitchen display.** Only the KDS is dark, on purpose and always; a theme switch would add a variant to every
  class of every screen for one wall-mounted display. Its own `night-*` tokens keep it explicit.
- **Golden screenshots of each screen.** They fail on a font hinting change between runners, which teaches people to re-bless them
  without looking; axe and role-based assertions test what matters.
