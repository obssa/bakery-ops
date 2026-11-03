import type { ProductImageView } from "../api/types";

// What ProductImage (components/ui.tsx) puts on the page, as plain functions the logic spec can call without a browser
// (e2e/p6/product-photo-logic.spec.ts, phase-6 plan 6.2a). docs/design/fresh-counter.md section 2 "ProductImage" and section 6.
//
// A product has at most one display photo, cropped to 4:3, in three renditions the server makes and never upscales (ADR 0022): thumb
// 320 × 240 (order lines, lists, the till's stack), card 800 × 600 (pad cards, production), large 1600 × 1200 or the crop's own size when
// it was smaller (the catalog's photo card). A product without one shows a placeholder: the line icon of its category and its initials.

export type ProductImageSize = "thumb" | "card" | "large";

/** The nominal size of each rendition. thumb and card always have it (the server's minimum is 800 × 600); large may be smaller. */
export const RENDITIONS: Readonly<Record<ProductImageSize, { readonly width: number; readonly height: number }>> = {
  thumb: { width: 320, height: 240 },
  card: { width: 800, height: 600 },
  large: { width: 1600, height: 1200 },
};

const SIZES: readonly ProductImageSize[] = ["thumb", "card", "large"];

// The API origin, as lib/api/client.ts reads it (not imported from there: this module stays free of the client's hooks, so a spec can
// load it in Node). Empty on the live host, where the shell and the API share an origin.
const API_ORIGIN = (process.env.NEXT_PUBLIC_API_URL ?? "").replace(/\/+$/, "");

/** A media path on the API origin. A URL that already names its scheme (or a protocol-relative one) is returned as it is. */
export function mediaUrl(path: string, origin: string = API_ORIGIN): string {
  if (/^[a-z][a-z0-9+.-]*:/i.test(path) || path.startsWith("//")) return path;
  return `${origin.replace(/\/+$/, "")}${path.startsWith("/") ? "" : "/"}${path}`;
}

/**
 * The pixel size of one rendition: the nominal one, or the photo's own when it is smaller, because nothing is upscaled. The img's
 * `width` and `height` attributes take it, so the 4:3 frame holds its place before the photo arrives.
 */
export function frameSize(image: ProductImageView, size: ProductImageSize): { width: number; height: number } {
  const own = image.width > 0 && image.height > 0 ? { width: image.width, height: image.height } : RENDITIONS.large;
  if (size === "large") return own;
  const nominal = RENDITIONS[size];
  return own.width >= nominal.width ? { width: nominal.width, height: nominal.height } : own;
}

/**
 * `srcSet` over the three renditions with their real widths: `thumb 320w, card 800w, large <width>w`. A rendition no wider than a
 * smaller one before it is left out, so no width is listed twice (an 800 × 600 photo's large is its card) and the browser never picks
 * a bigger file for the same pixels.
 */
export function srcSetOf(image: ProductImageView, origin: string = API_ORIGIN): string {
  const entries: string[] = [];
  let widest = 0;
  for (const size of SIZES) {
    const { width } = frameSize(image, size);
    if (width <= widest) continue;
    widest = width;
    entries.push(`${mediaUrl(image[size], origin)} ${width}w`);
  }
  return entries.join(", ");
}

/**
 * The `sizes` hint: how wide the frame is drawn at each size (fresh-counter.md 3.2 and 4.3). A 48 px order-line thumb, a pad card in a
 * grid of 4 at 1180 px (3 at 900 px, 2 on a phone), the catalog's photo card. On a 2x tablet a card then loads the 800 px rendition and
 * a thumb the 320 px one.
 */
export function sizesFor(size: ProductImageSize): string {
  switch (size) {
    case "thumb":
      return "64px";
    case "card":
      return "(max-width: 640px) 50vw, (max-width: 1024px) 33vw, 280px";
    case "large":
      return "(max-width: 640px) 100vw, 480px";
  }
}

/** The line icon of a placeholder (fresh-counter.md section 2): ui.tsx draws each one with lucide-react. */
export type PlaceholderIcon = "bread" | "cake" | "sandwich" | "pizza" | "cup" | "glass" | "plate";

// A category is a name the shop types (there is no category code), in English and in Amharic, so the icon is chosen by the words in
// either name. In this order: "Hot drinks" is a cup before it is a drink, "ትኩስ መጠጦች" (hot drinks) likewise. Short words match only
// as whole words, so "Photos" is not "hot" and "Steaks" not "tea".
const ICON_WORDS: readonly (readonly [PlaceholderIcon, RegExp])[] = [
  ["pizza", /pizza|ፒዛ/],
  ["sandwich", /sandwich|burger|\bwraps?\b|ሳንድዊች|በርገር/],
  ["cake", /cake|pastr|dessert|cookie|biscuit|muffin|donut|doughnut|ኬክ|ፓስትሪ|ጣፋጭ/],
  ["bread", /bread|baguette|\bloa(f|ves)\b|\bbuns?\b|\brolls?\b|toast|ዳቦ|ቶስት/],
  ["cup", /\bhot\b|coffee|\btea\b|espresso|macchiato|latte|cappuccino|\bbuna\b|ቡና|ሻይ|ትኩስ/],
  ["glass", /\bcold\b|juice|soda|water|smoothie|shake|drink|beverage|ጭማቂ|ቀዝቃዛ|ውሃ|ለስላሳ|መጠጥ/],
];

/** The placeholder icon of a category name (English or Amharic); the plate when there is none or no word matches. */
export function placeholderIcon(category: string | null | undefined): PlaceholderIcon {
  const name = (category ?? "").trim().toLowerCase();
  if (name === "") return "plate";
  for (const [icon, words] of ICON_WORDS) {
    if (words.test(name)) return icon;
  }
  return "plate";
}

// A word: a letter of any script with the marks that follow it. Ethiopic syllables are letters, so "የማንጎ ጭማቂ" is two words.
const WORD = /\p{L}[\p{L}\p{M}]*/gu;
// The Amharic possessive prefix ("of"), written as the front of the next word: "የማንጎ ጭማቂ" is "juice of mango". Half the demo
// products would otherwise start with the same initial.
const POSSESSIVE = "የ";

/**
 * Up to two initials from a name in either language: the first letter of the first two words, in capitals for Latin. A word in
 * brackets ("(slice)", "(ቁራጭ)") and numbers are skipped, unless nothing else is left. In Amharic a word's leading የ is passed over.
 */
export function initialsOf(name: string): string {
  let words = name.replace(/\([^)]*\)/g, " ").match(WORD) ?? [];
  if (words.length === 0) words = name.match(WORD) ?? [];
  return words
    .slice(0, 2)
    .map((word) => {
      const letters = Array.from(word);
      const first = letters[0] === POSSESSIVE && letters.length > 1 ? letters[1] : letters[0];
      return first.toUpperCase();
    })
    .join("");
}
