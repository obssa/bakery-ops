"use client";

import { useMemo } from "react";
import { useItems } from "./api/hooks";
import { imagesById, type ProductImages } from "./images/orderThumbs";

export type { ProductImages } from "./images/orderThumbs";

/**
 * The display photo of every product, by item id (phase-6 plan 6.3b and 10.2 Q7): the till's waiting list, and from 6.3c and 6.4a the
 * production board and Today, put a product's thumb beside an order line, a request or a top seller with `images.get(itemId) ?? null`
 * and ProductImage. Every role holds catalog.view, so GET /items?kind=product answers each of them.
 *
 * One query key, one shape (trap "One query key, one shape"): this reads the catalog through `useItems("product")`, the key
 * ["items", "product"] that the planner and the refund dialog already read with the same Item[] shape, and derives the map from it; it
 * never caches a second shape under a key of its own, and a write that invalidates ["items"] moves the map with the list. Offline, or until the catalog was read, the map is empty and the placeholders show; the photos already seen come from the service
 * worker's cache (6.3a).
 */
export function useProductImages(): ProductImages {
  const products = useItems("product");
  return useMemo(() => imagesById(products.data), [products.data]);
}
