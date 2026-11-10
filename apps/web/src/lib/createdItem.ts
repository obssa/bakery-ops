// What the new item page hands the item's own page when it opens it (phase-6/new-item-page): the item it just created, and why its first
// price was not saved, when it was not. It lives in the page's memory, not the URL, so a reload or a shared link shows the page as it is,
// without a "Created" line or an old refusal. Read without taking (a component's state initialiser may run twice), let go once shown.
// phase-6/new-item-recipe: also whether the recipe typed on the new item page was saved, and when it was refused, the recipe as typed,
// so the product page opens its recipe drawer with it filled in and says why.

import type { RecipeSpec } from "@/lib/api/types";

export interface CreatedItem {
  id: number;
  /** the API's words for the refused price, in the page's language; null when the price was saved or none was given */
  priceError: string | null;
  /** true when the recipe typed with the item was saved as its version 1 */
  recipeSaved?: boolean;
  /** the API's words for the refused recipe; null when it was saved or none was given */
  recipeError?: string | null;
  /** the refused recipe as typed, to fill the product page's form */
  recipeDraft?: RecipeSpec | null;
}

let handed: CreatedItem | null = null;

export function handCreated(created: CreatedItem): void {
  handed = created;
}

/** The hand-off for item `id`, if the new item page just made it. */
export function createdFor(id: number): CreatedItem | null {
  return handed && handed.id === id ? handed : null;
}

export function letGoCreated(id: number): void {
  if (handed?.id === id) handed = null;
}
