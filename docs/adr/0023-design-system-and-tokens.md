# ADR 0023: The design system and its tokens

- Status: **accepted** (step 6.5, 2026-11-04; proposed in step 6.2a, 2026-11-03, from phase-6 plan D6, D7, sections 6.0.2 and 9, and
  `docs/design/fresh-counter.md`). The decision is accepted as built in steps 6.2a to 6.4d, which moved every screen onto it. What the
  build changed or made concrete, including Ramin's design-fidelity rule of 2026-11-03, is in the dated note below.
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

## Note, 2026-11-04: accepted at the Phase 6 gate (step 6.5)

This note records steps 6.2a to 6.4d, from their As built notes in phase-6 plan section 6. What the build changed from, or made concrete
in, the decision:

- **Design fidelity** (Ramin, 2026-11-03, after comparing the live screens with the canvas). Restyling a screen with the tokens is not
  enough: a screen is done only when it looks like its board, region by region (the same layout and regions, order, sizes, colours, radii,
  type and spacing). A region the old screen had and the board does not show moves where the board puts it, or behind the control the
  board shows (the pad's "Tables" sheet, the product page's "Recipe and cost" drawer), reachable in one step. This sharpens decision 7: a
  spec that has to follow a moved control changes in the same commit as the screen, keeps the accessible names, and the step's As built
  note names the old selector, the new one and why. Steps 6.3a to 6.4b changed such specs (the pad's tables and "My orders", the till's
  Void and typed cash, the shift's menu in the station bar, the admin's account menu and Today, the catalog's new item drawer, the
  recipe drawer and the product page's one "Save changes"); 6.4c and 6.4d changed none.
- **Screenshots for review, not goldens** (decision 9). `e2e/p6/screens.spec.ts` (in `p6-a11y`) captures every rebuilt screen at
  1180 × 820 (stations) or 1440 × 900 (admin), in `en` with one `am` capture per screen, after its loading lines are gone, its fonts and
  photos loaded and its animations ended; `e2e-p6` uploads them as the artifact `screens` on every run. Nothing compares them: a person
  reviews them against the boards before a step merges.
- **Two frames.** Every station screen has one 68 px station top bar (`StationBar`), named by the screen through `useStationHeader`
  (`lib/topBar.ts`) and carrying its buttons through `StationHeaderActions` (a portal, so they stay in the screen's tree), with the
  "Screens" navigation, the person and the shift as menus in the bar; the shift's own menu is `ShiftBar look="menu"`. An admin page has no
  top bar at all (6.4a): the 264 px sidebar holds the mark, the sections, the "Screens" navigation, the sync chip, the bell and the
  person's account menu (dates, language, sign out); below 1024 px it is a drawer.
- **Kitchen night** (decision 1) is the `night-*` tokens plus a scoped remap: the shell puts `kitchen-night` on the display's frame, and
  `globals.css` maps `white`, `ground`, `neutral`, `brand` and the status scales onto the night tokens inside that class only, so the
  shared parts of the shell turn dark on the KDS without a second set of classes. The scrim is a token too (`bg-scrim`).
- **Tokens added**: `shadow-float`, `animate-skeleton`, `animate-drawer-in` and `animate-sidebar-in`, all under `motion-safe:`, and
  `bg-scrim`. Green means money confirmed only, so `Status`, the open shift, a ready line, a locked count and a confirmed purchase moved
  to brand teal.
- **Shared screen parts** beside the primitives: `ProductCard` (`components/pad/ProductCard.tsx`, the pad's card, also the catalog's grid
  and the photo manager's live preview, `preview` mode), the admin patterns of `components/admin/kit.tsx` (`SectionTabs`, `Toolbar` and
  its segments, selects, fields and checks, `SearchBox`, `TableCard`, `InnerTable`, `Notice`, `FORM_GRID`, `Fact`, `ProductThumb`) and
  `components/admin/rows.tsx`, the charts of `components/charts/*` in one style (brand-600 for the point that matters, brand-100 for the
  rest, no gradient), and the title row's Amharic twin (`PageHeader twin`, `lib/twin.ts`, outside the `h1` in its own `lang`). A wait is
  printed one way everywhere (`lib/durations.ts`: "5:07", "1 h 05", "3 d").
- **Axe as built** (decision 8). The check emulates reduced motion and waits for every ending animation before it reads contrast: a colour
  half way through a transition is neither the one before nor after. Its `label-content-name-mismatch` rule (WCAG 2.5.3) compares whole
  words, so a control is named by its own text (an sr-only span holding the name the specs read, what it draws hidden from readers) instead
  of an `aria-label` over different words, and an Amharic name must contain the visible word whole (a prefix such as `ለ` or a suffix such
  as `ን` glued to it breaks the match). A few names on screens `p6-a11y` does not cover still break the rule and are left, because fixing
  them changes names the specs read (6.4c, 6.4d).
- **The login photo** is self-hosted too (`apps/web/public/images/login-counter.webp`, its licence in `LICENSES.json`), precached by the
  service worker with the shell.

Enforcement, added to the list above: `p6-a11y` (login, the pad, the KDS and bar, the till, production, Today, the catalog list and the
product page, in `en` and `am`); `e2e/p6/screens.spec.ts` and the `screens` artifact; `product-photo-thumbs-logic.spec.ts` and
`screens-durations-logic.spec.ts`; every p0 to p5 project, unchanged but for the selectors the As built notes name.

The rest (the tokens in `@theme` with the `neutral` remap, what a colour may mean, the self-hosted fonts, the primitives keeping their
names and props, `lucide-react`, `ProductImage` as the one way a photo reaches a screen, no golden screenshots) is as proposed.
