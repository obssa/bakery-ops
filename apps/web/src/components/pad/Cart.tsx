"use client";

import { ArrowRight, Minus, NotebookPen, Plus, ShoppingBasket, Trash } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useEffect, useRef, useState } from "react";
import type { Locale, Menu, MenuItem } from "@/lib/api/types";
import { addDecimal, trimDecimal } from "@/lib/decimal";
import { blocker, cartTotal, isSoldOut, lineTotalOf, setLineNotes, setQuantity, stepLine, unitPriceOf, removeLine, type Cart as CartState, type CartLine, type Refusal } from "@/lib/cart";
import { Alert, Button, IconButton, ProductImage } from "../ui";
import { controlClass, focusRing } from "../styles";

// The order's lines and its send (fresh-counter.md 3.2, step 6.3a), the "Cart" region of the order panel: each line with its 48 px thumb
// (the photo the menu has for the product now, so a photo changed or removed since it was added shows as it is), the name and the unit
// price with the modifiers, the line total, a stepper around the count, a note and remove; then the item count and the subtotal, the
// total (30 / 700) computed here with decimal strings (the API's totals confirm it once the order is sent, on "My orders"), and the `xl`
// Send button. A refused send keeps the cart exactly as it was and marks the line the API names: the shortfall (ingredient, quantity
// short, station) or another refusal, in the page language.

const stepKey = `inline-flex size-11 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-900 hover:bg-neutral-200 disabled:cursor-not-allowed disabled:bg-neutral-50 disabled:text-neutral-400 ${focusRing}`;

function refusalText(refusal: Refusal, locale: Locale): string {
  return (locale === "am" ? refusal.message_am : refusal.message_en) || refusal.message_en || refusal.key;
}

function LineRow({
  line,
  cart,
  menu,
  category,
  onChange,
}: {
  line: CartLine;
  cart: CartState;
  menu: MenuItem[] | undefined;
  category: string | null;
  onChange: (change: (cart: CartState) => CartState) => void;
}) {
  const t = useTranslations("pad");
  const locale = useLocale() as Locale;
  const name = locale === "am" ? line.item.name_am : line.item.name_en;
  const modifiers = line.item.modifiers.filter((m) => line.modifierIds.includes(m.id)).map((m) => (locale === "am" ? m.name_am : m.name_en));
  const short = cart.shortfall.filter((s) => s.line_client_uuid === line.uuid);
  const refusal = cart.lineErrors[line.uuid];
  const soldOut = isSoldOut(line, menu);
  const busy = cart.sending;
  // the menu's photo of the product as it is now; the line's own copy of the menu entry only when the menu no longer lists it
  const now = menu?.find((m) => m.item_id === line.item.item_id);
  const image = now ? now.image : line.item.image;
  const [writing, setWriting] = useState(false);
  const noteOpen = writing || line.notes !== "";
  // "Add a note" opens the field with the caret in it; left empty, it closes again
  const note = useRef<HTMLInputElement>(null);
  useEffect(() => {
    if (writing) note.current?.focus();
  }, [writing]);

  return (
    <li className="space-y-2.5 py-3.5">
      <div className="flex items-start gap-3">
        <ProductImage image={image} name={name} category={category} size="thumb" className="w-12 shrink-0 rounded-lg" />
        <div className="min-w-0 flex-1">
          <p className="line-clamp-2 text-[15px] font-semibold leading-snug text-neutral-900">{name}</p>
          <p className="text-[13px] tabular-nums text-neutral-600">
            {unitPriceOf(line)} {line.item.currency}
            {modifiers.length > 0 ? ` · ${modifiers.join(", ")}` : ""}
          </p>
        </div>
        <p className="shrink-0 text-[15px] font-semibold tabular-nums text-neutral-900">
          {lineTotalOf(line)} {line.item.currency}
        </p>
      </div>
      <div className="flex items-center gap-2 pl-15">
        <div className="flex items-center gap-0.5 rounded-xl border border-neutral-200 p-0.5">
          <button type="button" aria-label={t("decrease", { name })} className={stepKey} disabled={busy} onClick={() => onChange((c) => stepLine(c, line.uuid, -1))}>
            <Minus className="size-[18px]" strokeWidth={2.25} aria-hidden="true" />
          </button>
          <input
            aria-label={t("qty_label", { name })}
            inputMode="numeric"
            value={trimDecimal(line.qty)}
            disabled={busy}
            onChange={(event) => onChange((c) => setQuantity(c, line.uuid, event.target.value))}
            className={`block h-11 w-11 rounded-lg bg-white text-center text-base font-semibold tabular-nums text-neutral-900 disabled:text-neutral-500 ${focusRing}`}
          />
          <button type="button" aria-label={t("increase", { name })} className={stepKey} disabled={busy} onClick={() => onChange((c) => stepLine(c, line.uuid, 1))}>
            <Plus className="size-[18px]" strokeWidth={2.25} aria-hidden="true" />
          </button>
        </div>
        {noteOpen ? null : (
          <IconButton label={t("note_add", { name })} size="md" disabled={busy} className="text-neutral-600" aria-expanded={false} onClick={() => setWriting(true)}>
            <NotebookPen className="size-5" strokeWidth={1.75} aria-hidden="true" />
          </IconButton>
        )}
        <IconButton label={t("remove", { name })} size="md" disabled={busy} className="ml-auto text-red-700 hover:bg-red-50" onClick={() => onChange((c) => removeLine(c, line.uuid))}>
          <Trash className="size-5" strokeWidth={1.75} aria-hidden="true" />
        </IconButton>
      </div>
      {noteOpen ? (
        <div className="pl-15">
          <input
            ref={note}
            aria-label={t("note_label", { name })}
            placeholder={t("options_note")}
            value={line.notes}
            maxLength={200}
            disabled={busy}
            onChange={(event) => onChange((c) => setLineNotes(c, line.uuid, event.target.value))}
            onBlur={() => setWriting(false)}
            className={`${controlClass("md")} text-[15px]`}
          />
        </div>
      ) : null}
      {soldOut && short.length === 0 && !refusal ? <p className="pl-15 text-[13px] font-semibold text-amber-800">{t("sold_out_line")}</p> : null}
      {short.length > 0 || refusal ? (
        <div role="alert" className="space-y-1 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-[15px] text-red-800">
          {short.map((s, index) => {
            const ingredient = (locale === "am" ? s.ingredient_name_am : s.ingredient_name_en) ?? s.ingredient_name_en ?? "";
            const station = locale === "am" ? s.station_name_am : s.station_name_en;
            const values = { ingredient, qty: trimDecimal(s.qty_short), unit: s.unit, station: station ?? "" };
            return (
              <p key={`${s.ingredient_item_id}-${index}`} className="font-semibold">
                {station ? t("shortfall_line", values) : t("shortfall_line_no_station", values)}
              </p>
            );
          })}
          {short.length > 0 ? <p className="text-[13px]">{t("shortfall_hint")}</p> : null}
          {refusal ? <p className="font-semibold">{refusalText(refusal, locale)}</p> : null}
        </div>
      ) : null}
    </li>
  );
}

/** How many of everything the cart holds: the lines' counts added up, as a decimal string ("4"). */
export function unitsOf(cart: CartState): string {
  return trimDecimal(cart.lines.reduce((sum, line) => addDecimal(sum, line.qty, 4), "0"));
}

export function Cart({
  cart,
  menu,
  categories,
  currency,
  onChange,
  onSend,
  onClear,
}: {
  cart: CartState;
  menu: MenuItem[] | undefined;
  categories?: Menu["categories"];
  currency: string;
  onChange: (change: (cart: CartState) => CartState) => void;
  onSend: () => void;
  onClear: () => void;
}) {
  const t = useTranslations("pad");
  const locale = useLocale() as Locale;
  const stop = blocker(cart);
  const total = cartTotal(cart);
  const categoryOf = (line: CartLine) => {
    const category = line.item.category_id === null ? undefined : categories?.find((c) => c.id === line.item.category_id);
    return category ? `${category.name_en} ${category.name_am}` : null;
  };

  return (
    <section aria-label={t("cart")} className="flex min-h-0 flex-1 flex-col">
      <div className="min-h-0 flex-1 space-y-3 overflow-y-auto px-5">
        {cart.error ? <Alert>{refusalText(cart.error, locale)}</Alert> : null}
        {cart.lines.length === 0 ? (
          <div className="flex flex-col items-center gap-3 px-4 py-10 text-center">
            <span aria-hidden="true" className="inline-flex size-12 items-center justify-center rounded-full bg-neutral-100 text-neutral-500">
              <ShoppingBasket className="size-6" strokeWidth={1.75} aria-hidden="true" />
            </span>
            <p className="max-w-60 text-[15px] text-neutral-600">{t("cart_empty")}</p>
          </div>
        ) : (
          <ul className="divide-y divide-neutral-100">
            {cart.lines.map((line) => (
              <LineRow key={line.uuid} line={line} cart={cart} menu={menu} category={categoryOf(line)} onChange={onChange} />
            ))}
          </ul>
        )}
        {cart.lines.length > 0 ? (
          <label className="block space-y-1.5 pb-4">
            <span className="block text-sm font-semibold text-neutral-900">
              {t("order_note")} <span className="font-normal text-neutral-600">({t("optional")})</span>
            </span>
            <input
              value={cart.notes}
              maxLength={200}
              disabled={cart.sending}
              onChange={(event) => onChange((c) => ({ ...c, notes: event.target.value }))}
              className={`${controlClass("md")} text-[15px]`}
            />
          </label>
        ) : null}
      </div>
      <div className="shrink-0 space-y-3 border-t border-neutral-200 px-5 pb-5 pt-4">
        {cart.lines.length > 0 ? (
          <div className="space-y-1">
            <p className="flex justify-between gap-3 text-[15px] tabular-nums text-neutral-600">
              <span>{t("items_count", { count: unitsOf(cart) })}</span>
              <span>{t("subtotal", { total, currency })}</span>
            </p>
            <p className="flex items-baseline justify-between gap-3 text-neutral-900">
              <span className="text-[17px] font-semibold">{t("total")}</span>
              <span className="text-3xl font-bold tracking-tight tabular-nums">
                {total} {currency}
              </span>
            </p>
          </div>
        ) : null}
        {stop === "table" ? <p className="text-[15px] font-medium text-amber-800">{t("need_table")}</p> : null}
        <Button type="button" size="xl" className="w-full" disabled={stop !== null || cart.sending} onClick={onSend}>
          {cart.sending ? t("sending") : t("send")}
          {cart.sending ? null : <ArrowRight className="size-[22px]" strokeWidth={2} aria-hidden="true" />}
        </Button>
        {cart.lines.length > 0 ? (
          <Button type="button" variant="secondary" size="lg" className="w-full" disabled={cart.sending} onClick={onClear}>
            {cart.serverOrderId !== null ? t("cancel_unsent") : t("clear_cart")}
          </Button>
        ) : null}
      </div>
    </section>
  );
}
