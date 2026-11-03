import type { Item, Line, ProductImageView } from "../api/types";
import { addDecimal, trimDecimal } from "../decimal";

// The product photos a station shows beside an order, as plain functions (e2e/p6/product-photo-thumbs-logic.spec.ts, phase-6 plan 6.3b
// and 10.2 Q7). Order lines, production requests and the analytics rows name a product but carry no photo: the web joins the photos from
// the catalog itself (GET /items?kind=product, lib/productImages.ts), so Orders, Production and Analytics are unchanged. Offline, or before
// the catalog was read, the map is empty and every thumb is the placeholder (ProductImage); the photos that were seen come from the
// service worker's cache.

/** A product's display photo by its item id; a product without one is not in the map. */
export type ProductImages = ReadonlyMap<number, ProductImageView>;

/** The catalog's products mapped by id to their photo (an ingredient, a product without a photo, or an answer from before 6.1a: left out). */
export function imagesById(items: readonly Pick<Item, "id" | "kind" | "image">[] | undefined): ProductImages {
  const map = new Map<number, ProductImageView>();
  for (const item of items ?? []) if (item.kind === "product" && item.image) map.set(item.id, item.image);
  return map;
}

export interface StackThumb {
  itemId: number;
  name_en: string;
  name_am: string;
  /** the product's photo, or null for its placeholder */
  image: ProductImageView | null;
}

/**
 * The till card's stack (fresh-counter.md 3.4): one thumb per product, in the order of the lines, at most `max`; `more` counts the products
 * beyond them (the "+N"). A product on two lines is one thumb.
 */
export function thumbStack(lines: readonly Pick<Line, "item">[], images: ProductImages, max = 3): { thumbs: StackThumb[]; more: number } {
  const seen = new Set<number>();
  const thumbs: StackThumb[] = [];
  let more = 0;
  for (const { item } of lines) {
    if (seen.has(item.id)) continue;
    seen.add(item.id);
    if (thumbs.length < max) thumbs.push({ itemId: item.id, name_en: item.name_en, name_am: item.name_am, image: images.get(item.id) ?? null });
    else more += 1;
  }
  return { thumbs, more };
}

/**
 * How many items the lines hold: the quantities added as decimal strings (ADR 0004). A whole number of units is also a `count` for the
 * plural ("4 items"); a part of a unit (a product sold by weight) is shown as the quantity itself, never rounded.
 */
export function unitCount(lines: readonly Pick<Line, "qty">[]): { whole: boolean; count: number | null; text: string } {
  const sum = lines.reduce((total, line) => addDecimal(total, line.qty, 4), "0.0000");
  const text = trimDecimal(sum);
  // a count of units for the plural, read from digits only (a till card never holds a billion items)
  const whole = /^\d{1,9}$/.test(text);
  return { whole, count: whole ? Number.parseInt(text, 10) : null, text };
}
