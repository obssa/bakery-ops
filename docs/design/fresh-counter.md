# Fresh Counter: the Bakery Ops design system

Status: approved by Ramin on 2026-11-03 for Phase 6 ("check and then implement"). The source is a design canvas in Ramin's
account. This file is the written spec the code follows. Where this file and the canvas disagree, this file wins. Change it in the
same PR as the code.

What the look is: a calm, bright counter. It has a neutral ground, white surfaces with a hairline border, one teal accent for "go"
actions and the current choice, and status colours that only ever mean status. Product photos carry the colour. The chrome stays quiet
so the food stands out. The kitchen display is the one dark screen: it hangs on a wall in a hot room and has to be read across it.

## 1. Tokens

All tokens live in `apps/web/src/app/globals.css`, in the Tailwind 4 `@theme` block. Components use the Tailwind names below and
never write a hex value.

### 1.1 Neutral (remapped)

The design's greys are cooler than Tailwind's stock `neutral`. Remapping `--color-neutral-*` moves every existing screen at once
without touching a class.

| Token | Hex | Use |
|---|---|---|
| `neutral-50` | `#F9FAFB` | disabled fill, table zebra |
| `neutral-100` | `#F2F4F7` | subtle fill: neutral chips, steppers, empty image well |
| `neutral-200` | `#E4E7EC` | hairline borders of cards, panels and dividers |
| `neutral-300` | `#D0D5DD` | input and secondary button borders |
| `neutral-400` | `#98A2B3` | disabled text, placeholder icons (never body text) |
| `neutral-500` | `#667085` | icons |
| `neutral-600` | `#5A6474` | secondary text (5.9:1 on white, 5.5:1 on ground) |
| `neutral-700` | `#344054` | strong secondary text, neutral chip text |
| `neutral-800` | `#1D2939` | |
| `neutral-900` | `#0E1726` | ink: primary text, the dark selected state |
| `neutral-950` | `#0B1220` | the kitchen night ground |
| `ground` | `#F4F5F7` | page background behind surfaces |

### 1.2 Brand teal (new)

| Token | Hex | Use |
|---|---|---|
| `brand-50` | `#E6F4F0` | selected fill, "online" chip |
| `brand-100` | `#CDE9E1` | selected border, chart bars (other) |
| `brand-200` | `#9DD3C4` | |
| `brand-300` | `#5FB8A2` | |
| `brand-400` | `#2DD4BF` | kitchen-night accent (dark ground only) |
| `brand-500` | `#138A72` | hover of the primary button on dark |
| `brand-600` | `#0E7C66` | primary buttons, focus ring, links, the highlighted chart bar (white text 5.1:1) |
| `brand-700` | `#0A5F4E` | primary hover/pressed; text on `brand-50` (6.7:1) |
| `brand-800` | `#084C3F` | |
| `brand-900` | `#063A30` | |

### 1.3 Status

These use Tailwind's stock scales and keep their meaning everywhere:
- amber means low or waiting (`amber-50` fill, `amber-800` text, `amber-700` dot);
- red means out, void, late or an error (`red-50` / `red-700`);
- blue means information or in progress (`blue-50` / `blue-700`);
- green is used only for money confirmed (`green-50` / `green-800`).

Never tell two states apart by hue alone. Each one also carries a word ("Low", "Out") or an icon.

### 1.4 Type

- **Inter Variable** for Latin and **Noto Sans Ethiopic Variable** for Ge'ez. Both are self-hosted through `@fontsource-variable/*`, so
  the PWA works offline. Never load Google Fonts at runtime.
- `--font-sans: "Inter Variable", "Noto Sans Ethiopic Variable", ui-sans-serif, system-ui, sans-serif;` puts Inter first. Ethiopic
  glyphs fall through to Noto, so mixed lines render in both scripts.
- `font-variant-numeric: tabular-nums` on every amount, count, timer and table number (Tailwind `tabular-nums`).
- Amharic keeps `:lang(am) { line-height: 1.65 }`.

| Role | Size / weight | Tailwind |
|---|---|---|
| Display (amount due, KPI) | 44-48 / 700, tracking -0.02em | `text-5xl font-bold tracking-tight` |
| Page title | 30 / 700, -0.02em | `text-3xl font-bold tracking-tight` |
| Section title | 17-18 / 600 | `text-lg font-semibold` |
| Body (stations) | 16-17 / 400 | `text-base` |
| Body (admin) | 15 / 400 | `text-[15px]` |
| Meta | 13 / 400-500 | `text-[13px]` |
| Label caps | 13 / 600, 0.08em, uppercase | `text-[13px] font-semibold uppercase tracking-[0.08em]` |

### 1.5 Shape, depth, motion

- Radius:
  - `rounded-xl` (12 px): buttons and inputs on admin;
  - `rounded-2xl` (16 px): station buttons, product cards and panels;
  - `rounded-[20px]`: large surfaces (dialogs, the receipt panel);
  - `rounded-full`: chips and avatars.
- Depth:
  - surfaces are white with a `neutral-200` 1 px border; at rest there is no shadow or only `shadow-xs`;
  - floating layers (dialogs, drawers, menus, toasts) take `shadow-[0_12px_32px_-8px_rgb(14_23_38/0.18)]`;
  - no gradients, glass or coloured shadows.
- Touch:
  - station targets at least 48 px tall (primary actions 56-68 px); admin targets at least 44 px;
  - at least 8 px between targets.
- Focus: `focus-visible:ring-4 ring-brand-600/35 ring-offset-1`. It is always visible and never removed.
- Motion: 150 ms ease-out for colour and opacity, 200 ms for drawers. Everything is under `motion-safe:`; reduced motion turns
  transitions off.

### 1.6 Kitchen night (KDS only)

| Token | Hex |
|---|---|
| Ground | `neutral-950` `#0B1220` |
| Ticket surface | `#131C2E` |
| Ticket border | `#23304A` |
| Divider | `#1C2740` |
| Text | `#E6EAF2` |
| Secondary text | `#9AA6BC` (7.6:1) |
| Accent buttons | `brand-400` `#2DD4BF` with text `#04241F` (8.8:1) |
| Fresh timer | `#0F3D33` / `#5EEAD4` |
| Waiting timer | `#3D2A0B` / `#FBBF24` |
| Late timer | `#4A1515` / `#FCA5A5` |
| Note text | `#F5C66A` |

Define these as `--color-night-*` tokens.

The night is scoped to the kitchen and bar routes (step 6.3b): the shell's frame carries the class `kitchen-night` there, and inside it the
light scale is remapped onto the night tokens, so the top bar, the shift strip, the stock strip's sheet and every shared primitive are
drawn dark with the classes they already have, and no other screen changes. `white` is the ticket surface, `ground` the night ground,
`neutral-100` the divider, `neutral-200` the ticket border, `neutral-600` the secondary text, `neutral-900` the text; `brand-50`/`brand-700`
the fresh fill and text, `brand-600` the accent; `amber-50`/`amber-800` the waiting pair, `red-50`/`red-700` the late pair. The shades the
table above does not name are, in the same spirit: `neutral-50` `#161F33`, `neutral-300` `#3A4866` (the outline of Reject), `neutral-400`
`#5B6882`, `neutral-500` `#8794AB`, `neutral-700` `#B8C2D3`, `neutral-800` `#D3DAE6`, `amber-900` `#FCD34D`, `red-800` `#FECACA`, blue `50`
`#0E2A47` with `700` `#93C5FD`, green `50` `#0F3D24` with `800` `#86EFAC`. Text on a filled control becomes the dark surface (9.2:1 on the
accent). The dialog scrim is black at 60 % at night.

## 2. Components (`apps/web/src/components/ui.tsx`)

The primitives keep their current names and props, so that existing screens restyle without edits. New ones are added beside them.
Icons come from `lucide-react` at 1.75-2 px stroke, 18-22 px; an icon-only button always has an `aria-label`.

- **Button.** Every variant has a disabled state with no hover, `neutral-50` fill and `neutral-400` text, plus a loading state with a
  spinner that keeps the width.
  - Variants:
    - `primary`: `brand-600`, white text;
    - `secondary`: white, `neutral-300` border, ink text;
    - `ghost`: no fill, ink text, `neutral-100` on hover;
    - `danger`: `red-50` fill, `red-700` text;
    - `dark`: `neutral-900` fill, white text.
  - Sizes:
    - `md`: 44 px, admin;
    - `lg`: 52 px, station;
    - `xl`: 64 px, the one main action of a station screen.
- **IconButton.** A square button in the same variants.
- **Chip / Badge.** A 26-30 px pill with an optional 8 px dot. Tones are `neutral`, `brand`, `amber`, `red` and `blue`.
- **TextInput / Select / Textarea.** 44 px (admin) or 52 px (station), `neutral-300` border, `rounded-xl`, a 2 px `brand-600` border on
  focus. An error shows a `red-700` border plus the message under it. A money input shows an "ETB" prefix in `neutral-600`.
- **Switch.** 52x32 track, `brand-600` when on, `neutral-300` when off. It is a real `<button role="switch" aria-checked>` with a
  visible label beside it.
- **Segmented control / Tabs.** A white track with a `neutral-200` border. The selected segment is `neutral-900` with white text
  (stations) or `brand-50` with `brand-700` text (admin).
- **Card / Panel.** White, `neutral-200` border, `rounded-2xl`, 20 px padding. A section title (17 / 600) sits at the top, with an
  optional right-side action link.
- **Stat (KPI).** A label (14, `neutral-600`), a value (30 / 700, tabular) and a delta (13 / 600, `brand-700`, or `red-700` when it is
  bad).
- **Avatar.** A circle with initials on `neutral-900`, or the user's photo.
- **EmptyState.** A 48 px icon in a `neutral-100` circle, a title, one line of help and at most one action.
- **Skeleton.** `neutral-100` blocks with a slow `motion-safe` pulse, used while a first load is pending.
- **Modal / Drawer.** Dialog semantics stay as they are. Modals are `rounded-[20px]` with 24 px padding and the floating shadow; the
  drawer is 480 px from the right; the scrim is `neutral-900/40`.
- **Table (admin).** 48 px rows with `neutral-200` dividers, no vertical rules, a 13 / 600 caps header in `neutral-600`, tabular numbers
  aligned right, and row hover in `neutral-50`.
- **ProductImage.** It shows a product's photo, or a placeholder that never looks broken.
  - Props: `image` (the API's `{ thumb, card, large, width, height } | null`), `name`, `category`, `size` (`thumb` | `card` | `large`)
    and `className`.
  - The frame is always 4:3 (`aspect-[4/3]`) with `object-cover`. It uses `srcSet` over the three renditions with a `sizes` hint, plus
    `loading="lazy"` and `decoding="async"`. Set `width`/`height` to avoid layout shift. `alt` is the product name in the current
    language.
  - The placeholder is a `neutral-100` well with the category's line icon (bread, cake, sandwich, pizza, cup, glass; a generic plate
    otherwise) in `neutral-400`, plus the product's initials at small size. It is `aria-hidden`, because the name is printed next to
    it.
  - An image that fails to load falls back to the placeholder.

## 3. Station screens (tablet first, 1180x820 landscape, also works at 820x1180 portrait)

Every station screen shares a **top bar**, 68 px, white with a bottom hairline. Left: the station mark (a 36 px `brand-600` square with
the app's line icon) and the station name, then a chip with the shift. Right, in this order:
1. the sync chip ("Online · all sent" in `brand-50`, "Offline · 2 waiting" in `neutral-100`, "Conflicts" in `amber-50`);
2. the language button (shows the other language's name);
3. the notification bell where the screen has one;
4. the user's avatar with name and role.

**The shift's states.** The shift never floats over the work unasked. On the KDS, the till, the production board and the count screen the
bar holds its chip: amber "No shift", blue "Opening" or "Closing", brand teal "Open". A tap on the chip opens the floating panel with the
shift's words and every action; the chip, a tap outside it or Escape closes it (the focus returns to the chip), and it fits under the bar
and within the window, scrolling inside itself. The till's cash panel behaves the same way.

| State | Bar | Under the bar |
|---|---|---|
| No shift | the chip with a small attention dot | the strip: the headline, "Start shift", "More" |
| Opening or closing count in progress | the chip with the dot | the strip: the headline, "Open the count", "More" |
| A refusal (409 `inventory.shift.required`, `payments.shift.required`, a failed shift action) | the chip with the dot | the strip in amber: the message and the same action; "Dismiss" when no action answers it |
| Shift open | the chip alone | nothing: the page has its full height |
| The count screen, its own count in progress | the chip alone | nothing: the count is the screen (a refusal still shows) |

The strip is in the page's flow directly under the bar, so it pushes the page down and covers nothing: one row of about 52 px from 640 px
up (the state's chip and the headline on the left, the action and a secondary "More" on the right), and on a phone the message on one line
with "More", and the action the full width under it. It is `role="status"` and never takes the focus. While the panel is open the strip
keeps its words and its height and hides its controls, so a name never matches two visible controls.

### 3.1 Login

- Layout: a split screen. The left 45 % is a full-bleed bakery photo with the app name and one line of welcome over a bottom
  `neutral-900/60` band. The right side is a white form column of at most 420 px.
- Tabs at the top: "Phone and password" and "Staff code".
- Phone and password: phone and password fields (52 px) and a primary `xl` "Sign in" button.
- Staff code: a 3x4 keypad of 72 px keys and dots for the digits entered.
- The language switch sits top right. Errors appear inline under the field, never as a toast.
- On a phone the photo collapses to a 160 px header.

### 3.2 Waiter pad

Three zones from left to right.

1. **Menu, centre, fills the width.**
   - A 52 px search field with a search icon, then a horizontally scrolling row of category chips (44 px, the selected one
     `neutral-900` with white text, each with a count). The order is "New" first when there are new products, then the categories in
     their `sort` order, then "Other".
   - A grid of **product cards**: 4 columns at 1180 px wide, 3 at 900 px, 2 on a phone, 12 px gap.
   - Product card: white, `rounded-2xl`, hairline border, `overflow-hidden`.
     - Top: the 4:3 ProductImage (the `card` rendition).
     - Below, 12 px padding: the name (16 / 600, two lines at most, clamp) and the price (16 / 700, tabular, right-aligned on the same
       row as the stock chip).
     - The stock chip sits over the photo's top-left corner, 8 px in, white text on `neutral-900/75` with a blur-free solid fill:
       "3 left" in amber when low, nothing when plenty.
     - Unavailable: the photo turns grayscale at 60 % opacity, a centred "Out of stock" chip (red) covers it, and the card is
       `aria-disabled`. Tapping it says why ("Out of stock", "Ingredient short at the kitchen").
   - Tap adds one. Long-press, or the small "More" icon button in the card's bottom-right, opens the options sheet.
   - A "New" ribbon (a `brand-600` chip "New") sits top-right of the photo.
2. **Order panel, right, 368 px, white, left hairline.**
   - Header: a "New order" label in caps, the table name (22 / 600) and a "Change" button.
   - Lines: a 48 px thumb (the `thumb` rendition), the name and note, a stepper (40 px − and + keys around the count) and the line
     total.
   - Footer: item count and subtotal, then total (30 / 700). The primary `xl` "Send to kitchen" button carries an arrow icon, with
     "Save draft" as secondary under it.
3. **Tables and My orders** stay reachable from the top bar as they are now.

### 3.3 Kitchen display (kitchen night)

- Three columns: New, Cooking, Ready. Each has a count pill in the header.
- Tickets are `#131C2E` cards, radius 18. Each shows:
  - the order number (20 / 700) and the table and waiter;
  - a timer chip (fresh, waiting or late colours from 1.6);
  - lines as "2×" in `brand-400` 18 / 700, then the name 17 / 600 and the note in `#F5C66A`.
- Actions are 52 px:
  - New: Accept (`brand-400`) and Reject (outline);
  - Cooking: "Ready";
  - Ready: a "Waiting for pickup" strip with the waiter's name.
- The footer strip shows the kitchen stock chips (amber when low, red when out).
- No product photos here: the KDS reads at a glance, and the lines are text on purpose.

### 3.4 Till pay sheet

- Left, 360 px: the "Waiting to pay" list. Each order card shows its number and table, the total, items and waiter, its age, and a
  stack of up to 3 overlapping 28 px round product thumbs (+N) so the cashier recognises an order by its food. The selected card has a 2 px
  `brand-600` border.
- Centre:
  - "Amount due" (caps label) and the amount (48 / 700);
  - "Pay with": channel buttons, 64 px, in a 3-column grid, with the channel name and the hint of what it needs ("Photo of the slip",
    "Reference number"); the selected one is `brand-50` with a 2 px `brand-600` border;
  - "Received" money input and "Change" (`neutral-900` block with white amount);
  - quick cash keys (300, 500, 1,000, Exact) and a "Split payment" ghost button;
  - the primary `xl` "Complete payment".
- Right, 236 px: the receipt panel with the QR, "Copy receipt link", the Print button (disabled with "off in Admin" when printing is
  off) and the line summary.

### 3.5 Production board

- Four columns: Requested, In the oven, Check and confirm, Done today.
- Cards show:
  - a 56 × 42 (4:3) thumb of the product on the left of the name, with the Amharic name under it and the quantity (22 / 700) right;
  - a reason chip ("Sold out · asked automatically" amber, "Asked by the manager" blue);
  - for batches in the oven, a progress bar (8 px, `brand-600` on `neutral-100`) with "22 of 35 min";
  - a meta line;
  - the column's one action at 48 px.

## 4. Admin (fluid page)

### 4.1 Frame

- **Sidebar, 264 px, white, right hairline.**
  - The app mark and name, then the branch switcher.
  - Navigation in groups with 18 px line icons, 44 px rows, the active row `brand-50` with `brand-700` text:
    - Today;
    - Sell: Orders, Payments, Refunds;
    - Make: Catalog, Inventory, Production, Planner;
    - Team: People, Shifts, Devices;
    - Insight: Analytics, Audit log;
    - System.
  - The user card sits at the bottom: initials, name and role, with the bell beside it and the sync chip above it. The card opens the
    account menu: the calendar ("Dates"), the language and "Sign out".
  - Last, "Stations": the station screens (the "Screens" navigation).
- An admin page has no station top bar and no "Screens" strip: it is the sidebar and the content (step 6.4a).
- Below 1024 px the sidebar becomes a drawer behind a menu button in a 60 px top bar.
- **Content:** max width 1160 px, 32 px padding (16 on a phone). Each page has a title row: the page title (30 / 700) with the Amharic
  title in `neutral-600` beside it, a one-line intro, and the page actions on the right.

### 4.2 Today (admin home)

- KPI row of four Stats: Sales, Orders, Average ticket, Refunds.
- Then "Sales by hour" (bars, the current hour `brand-600`, the others `brand-100`) and "Payments by channel" (share bars).
- Then "Top sellers today": the 5 best products, each with a 52 × 39 thumb (4:3, as the Admin board draws it), name, units and amount.
- Then "Stock alerts", "Shifts" and "System" cards.

### 4.3 Catalog

- **List.**
  - A toolbar with search, a kind filter (Products, Ingredients), a category filter and "Add product".
  - The default view is a table: a 64 × 48 thumb, the name (English over Amharic), category, where it is sold from, price, a status
    chip (On the menu, Hidden, New) and a chevron.
  - "Add product" ("Add ingredient" in the ingredients) opens the new item page; "Categories" beside it opens the categories of both
    kinds in a drawer.
  - A grid toggle shows the products as pad cards, as a waiter would see them.
  - Products without a photo show the placeholder plus an "Add photo" link in the row.
- **New item page** (`admin/catalog/new/?kind=product|ingredient`, phase-6/new-item-page; it replaced a 480 px drawer that stacked
  every field). No board draws it: it takes the ProductPhoto board's cards (white, 18 px radius, 20 px in, 17 / 600 titles, 44 px
  controls) and the forms rule of 4.4 (two columns from 1024 px, one below). Under the breadcrumb and the title ("New product" or "New
  ingredient", following the choice), one column of cards, at most 960 px wide:
  1. "What are you adding?": Product and Ingredient as two choice cards (a 44 px icon well, the name, one plain sentence; the chosen one
     `brand-50` with a 2 px `brand-600` border and a check), one radio group named "Kind";
  2. Basics: the two names side by side, then the category (with a secondary "New category" that opens a small dialog for one of the
     kind being added, chosen once it exists) and the unit (its common ones first);
  3. "How it's sold", products only: Made to order, Made in batches, Bought in, three choice cards with a sentence each (the radio
     group "Fulfilment");
  4. Price, products only and optional (an ETB money input), saved after the item by the product page's own price call;
  5. "More options", folded: the SKU, and for a product the two refund switches. An ingredient shows no refund section at all;
  6. a white footer bar that stays in view at the bottom of the window (`shadow-float`, 16 px off the edge): Cancel, and "Create
     product" or "Create ingredient".
  The page checks the names, the unit and the price before it sends; each error, its own or the API's, shows under its field, a red line
  under the form says so, and the first field in error takes the focus. A create opens the item's own page with a status line ("Created
  ... Add a photo and a recipe"), where the photo and the recipe live. On a phone the cards and fields are one column and the footer's
  two buttons share its width.
- **Product page.** Two columns: the form on the left (names, category, price, station, refund settings, all as now) and a sticky
  right column with the **Photo** card.
- **Photo card.**
  - Empty: a 4:3 dropzone with a dashed `neutral-300` border, an image icon, "Drop a photo here or browse your files", and the rules
    line "JPG, PNG or WebP · best at 1200 × 900 px or larger". Dragging over turns the border to a solid `brand-600` and the fill to
    `brand-50`, with "Drop to upload".
  - After choosing a file, the **framing step**:
    - the photo sits inside a fixed 4:3 frame; drag to move, pinch or use the zoom slider to scale (1× to 3×);
    - a rule-of-thirds overlay and a "Drag to move" chip;
    - "Use this photo" (primary), "Choose another" (secondary) and "Cancel" (ghost);
    - a quality line under the frame:
      - `brand-50` "Sharp on every screen · crop W × H px" at 1200 × 900 source pixels or more;
      - amber "This photo may look soft on big screens" between 800 × 600 and 1200 × 900;
      - "Use this photo" disabled with a red line below 800 × 600, the server's minimum.
  - **Preview:** under the frame, the product as a pad card at real size (with its stock chip) and as a 48 px order-line thumb, both
    live.
  - Uploading: the new photo dimmed, with a white progress panel ("Uploading and making sizes", percent). The pad keeps the old photo
    until the new one is saved.
  - With a photo: the photo, "Added by <name> · <date> · W × H", then "Replace" (secondary) and "Remove" (danger, confirmed in a small
    dialog).
  - A refusal shows the server's message in a `red-50` box under the card.

### 4.4 Every other admin screen

They move onto the same primitives: Card/Panel, Table, Chip, Stat, Button sizes and the page title row. No screen keeps its own
colours. Forms use a two-column grid at 1024 px or wider and one column below.

As built for Sell and Make (step 6.4c, `components/admin/kit.tsx`), so each screen could sit beside the catalog list on the canvas:
- the title row: the title with its Amharic name in `neutral-600` on the English page, the intro, the actions at the right (the intro wraps
  in its own column, so the actions stay on the row);
- a section's own pages (Payment sections, Inventory sections) as a named navigation of links in a segment track under the title row,
  the current one `brand-50` with `brand-700` text, as the catalog's Products and Ingredients;
- the toolbar: segments (36 px in a 46 px track), 44 px selects whose first option says what they show unfiltered, a field with its label
  inside the box for a date or a number, the search with its icon, a filter switch as a 44 px pill; Apply as the primary button;
- tables on a white 18 px card: the 13 / 600 caps header on one line, 48 px rows on hairlines, the first and last columns 20 px in,
  figures right-aligned in tabular numbers, status chips with a dot, a row's actions on one line at its end, the open row `brand-50`;
  a table with a visible title carries it inside the card; a table of many figures (the reconciliation) uses 8 px columns;
- a notice about the screen itself (stale data, a view-only form) in the Alert's shape: amber, blue or neutral;
- the colours of a state: amber waiting, blue in progress, `brand` done or on, red refused or out, green only for money confirmed (a
  verified payment, an order paid in full).

As built for Team, Insight and System (step 6.4d, on 6.4c's `components/admin/kit.tsx`):
- the title row: the title with its Amharic name on the English page, the intro, the page's actions at the right (a page's exports as a
  group "Export", the way back from a record as a ghost button with an arrow);
- "Shifts sections" and "Analytics sections" as the kit's segmented tabs under each page's title row (the analytics ones keep the range);
- the toolbar: the analytics range as a segment track (Today, 7, 30, 90 days, Custom range) with the page's options after it (how fresh
  the figures are is a white pill in the title row, beside the exports); a list's filters as 44 px selects and boxed fields;
- tables on the kit's table card, figures right-aligned in tabular numbers, a row's actions at its end; a product beside its 52 × 39 photo;
- a form on a white card under its list in two columns, brought into view by Edit;
- charts in the style of Today's "Sales by hour": the highlighted bar (the latest point, or the busiest hour) `brand-600`, the others
  `brand-100`, rounded at the data end and square on the zero line, a shortage red under it; share bars `brand-600` on a `neutral-100`
  track; a line 2 px `brand-600` with its latest point as a dot, the period before dashed `neutral-400`; no gradient, no blue;
- the colours of a state as 6.4c's: an open shift, an active person, device, template or reason, a passing backup and a switch that is on
  are `brand`; amber waiting or stale; red revoked, failed or a shortage; green for money confirmed only.

## 5. Accessibility and language

- Contrast:
  - text at least 4.5:1, large text (24 px or more) at least 3:1;
  - every pair in section 1 was checked;
  - placeholder and disabled greys never carry information.
- `p6-a11y` runs axe on login, pad, KDS, till, production, admin Today and catalog, in `en` and `am`. It allows no serious or
  critical violation.
- Real `<button>`, `<a>`, `<label>` elements; every icon-only control has an `aria-label`; product cards are buttons named by the
  product and its price.
- Every visible string comes from `messages/{en,am}.json`. Amharic sits beside English only where the screens already show both.
- Layouts survive Amharic labels that are about 30 % longer: no fixed-width buttons with text, clamp names to two lines, and let
  chips wrap.

## 6. Product photos (with the API in ADR 0022)

- One display photo per product, cropped to **4:3**. Renditions:
  - `thumb` 320×240: order lines, lists, the till stack;
  - `card` 800×600: pad cards, production;
  - `large` 1600×1200: the catalog photo card, a future customer menu.
- Photos are served from content-addressed URLs and cached for good; the service worker keeps them for offline pads.
- Demo photos are stock photos under a free licence, for the demo branch only. The owner replaces them with photos of the real
  products.
- A good product photo:
  - one product, centred, filling about 70 % of the frame;
  - shot from 30-45° (drinks at eye level);
  - a plain light surface;
  - soft daylight, no flash;
  - no text, logos or hands in the frame.
