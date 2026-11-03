"use client";

import { Ellipsis } from "lucide-react";
import { useTranslations } from "next-intl";
import { useEffect, useRef, useState } from "react";
import type { Locale, MenuItem } from "@/lib/api/types";
import { compareDecimal, trimDecimal } from "@/lib/decimal";
import { focusRing } from "../styles";
import { IconButton, ProductImage } from "../ui";

// A product as the waiter sees it (fresh-counter.md 3.2, step 6.3a): a white card with the 4:3 photo on top (the card rendition, or the
// category's placeholder), the name (two lines at most) and the price under it. Over the photo: "3 left" in amber when the API says the
// stock is low (nothing when there is plenty), the brand-teal "New" chip, or, for a product that cannot be sold, the photo in grey with the
// reason in a red chip. The whole card is the add button, named as it always was (name, price, availability; or why it is not available),
// so the earlier specs still find it; a tap adds one, a long press or the small "More" button beside the price opens the options. An
// unavailable card is aria-disabled, not disabled: it stays focusable and a tap says why. Also drawn without handlers as a `preview`
// (the catalog's grid view, step 6.4a, and the photo card's live preview, step 6.4b).

/** What a card shows: a menu entry, or a catalog row put in the same shape. */
export type ProductCardItem = Pick<
  MenuItem,
  "item_id" | "name_en" | "name_am" | "price" | "currency" | "available" | "low" | "is_new" | "unavailable_reason" | "image"
>;

export interface ProductCardProps<T extends ProductCardItem> {
  item: T;
  /** the page's language: the card shows the product's name in it, and only that one (phase-6 plan 10.2 Q13) */
  locale: Locale;
  /** the product's category name in either language, for the placeholder's icon */
  category?: string | null;
  /** a tap on an available card */
  onAdd?: (item: T) => void;
  /** a long press or the "More" button: quantity, modifiers and a note */
  onMore?: (item: T) => void;
  /** a tap on a card that cannot be sold, with the reason in the page's language (the screen announces it) */
  onUnavailable?: (item: T, reason: string) => void;
  /** drawn as the waiter would see it, without a button or handlers */
  preview?: boolean;
}

const LONG_PRESS_MS = 500;
/** How long the reason chip stays outlined after a tap on an unavailable card. */
const NUDGE_MS = 1200;

/** A product the pad cannot sell now: the API gave a reason, or there is nothing left. */
export function isUnavailable(item: Pick<MenuItem, "available" | "unavailable_reason">): boolean {
  return item.unavailable_reason !== undefined || compareDecimal(item.available, "0") <= 0;
}

export function ProductCard<T extends ProductCardItem>({ item, locale, category, onAdd, onMore, onUnavailable, preview = false }: ProductCardProps<T>) {
  const t = useTranslations("pad");
  const name = locale === "am" ? item.name_am : item.name_en;
  const unavailable = isUnavailable(item);
  const reason = unavailable ? t(`reason_${item.unavailable_reason ?? "out_of_stock"}`) : null;
  const count = trimDecimal(item.available);
  const availability = item.low ? t("left", { count }) : t("available_count", { count });
  const label = unavailable
    ? t("unavailable_item", { name, price: item.price, currency: item.currency, reason: reason ?? "" })
    : t("add_item", { name, price: item.price, currency: item.currency, availability });

  // a long press opens the options instead of adding one
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const pressed = useRef(false);
  const clear = () => {
    if (timer.current !== null) clearTimeout(timer.current);
    timer.current = null;
  };
  useEffect(() => clear, []);

  // a tap on an unavailable card outlines its reason for a moment (and the screen reads it out)
  const [nudged, setNudged] = useState(false);
  useEffect(() => {
    if (!nudged) return;
    const off = setTimeout(() => setNudged(false), NUDGE_MS);
    return () => clearTimeout(off);
  }, [nudged]);

  const face = (
    <>
      <span className="relative block overflow-hidden rounded-t-[15px]">
        <ProductImage
          image={item.image}
          name={name}
          category={category}
          size="card"
          // the photo in grey at 60 %; a placeholder is grey already and keeps its contrast
          className={unavailable ? "[&_img]:opacity-60 [&_img]:grayscale" : ""}
        />
        {!unavailable && item.low ? (
          <span className="absolute left-2 top-2 inline-flex min-h-6.5 items-center gap-1.5 rounded-full bg-amber-50 px-2.5 text-xs font-bold tabular-nums text-amber-800">
            <span aria-hidden="true" className="size-[7px] shrink-0 rounded-full bg-amber-700" />
            {t("left", { count })}
          </span>
        ) : null}
        {item.is_new ? (
          <span className="absolute right-2 top-2 inline-flex min-h-6.5 items-center rounded-full bg-brand-600 px-2.5 text-xs font-bold text-white">
            {t("tab_new")}
          </span>
        ) : null}
        {unavailable ? (
          <span
            className={`absolute left-1/2 top-1/2 inline-flex min-h-7.5 max-w-[calc(100%-1rem)] -translate-x-1/2 -translate-y-1/2 items-center rounded-full bg-white px-3 text-center text-[13px] font-bold text-red-700 shadow-xs motion-safe:transition-shadow motion-safe:duration-150 ${nudged ? "ring-4 ring-red-200" : ""}`}
          >
            {reason}
          </span>
        ) : null}
      </span>
      <span className="flex flex-1 flex-col gap-1.5 px-3 pb-3 pt-2.5">
        <span className="line-clamp-2 text-base font-semibold leading-snug text-neutral-900">{name}</span>
        <span className={`mt-auto pr-10 text-base font-bold tabular-nums ${unavailable ? "text-neutral-600" : "text-neutral-900"}`}>
          {item.price} {item.currency}
        </span>
      </span>
    </>
  );

  const surface = "flex h-full w-full flex-col rounded-2xl border border-neutral-200 bg-white text-left";

  if (preview) {
    return (
      <div className={`relative ${surface}`}>
        {face}
        {unavailable ? null : (
          <span aria-hidden="true" className="absolute bottom-1 right-1 inline-flex size-11 items-center justify-center text-neutral-600">
            <Ellipsis className="size-5" strokeWidth={2} aria-hidden="true" />
          </span>
        )}
      </div>
    );
  }

  return (
    <div className="relative h-full">
      <button
        type="button"
        aria-label={label}
        aria-disabled={unavailable || undefined}
        onPointerDown={() => {
          pressed.current = false;
          clear();
          if (unavailable || !onMore) return;
          timer.current = setTimeout(() => {
            pressed.current = true;
            onMore(item);
          }, LONG_PRESS_MS);
        }}
        onPointerUp={clear}
        onPointerLeave={clear}
        onPointerCancel={clear}
        onContextMenu={(event) => event.preventDefault()}
        onClick={() => {
          if (pressed.current) {
            pressed.current = false;
            return;
          }
          if (unavailable) {
            setNudged(true);
            onUnavailable?.(item, reason ?? "");
            return;
          }
          onAdd?.(item);
        }}
        className={`${surface} select-none motion-safe:transition motion-safe:duration-150 motion-safe:ease-out ${
          unavailable ? "cursor-not-allowed" : "hover:border-neutral-300 motion-safe:active:scale-[0.98]"
        } ${focusRing}`}
      >
        {face}
      </button>
      {unavailable || !onMore ? null : (
        // beside the price, over the card's corner (a sibling of the add button, never inside it)
        <span className="absolute bottom-1 right-1">
          <IconButton label={t("more_options", { name })} size="md" variant="ghost" className="text-neutral-600" onClick={() => onMore(item)}>
            <Ellipsis className="size-5" strokeWidth={2} aria-hidden="true" />
          </IconButton>
        </span>
      )}
    </div>
  );
}
