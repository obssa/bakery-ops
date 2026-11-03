"use client";

import { ArrowRight, Minus, NotebookPen, Plus, ShoppingBasket } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useEffect, useRef, useState } from "react";
import type { Locale, Menu, MenuItem } from "@/lib/api/types";
import { addDecimal, trimDecimal } from "@/lib/decimal";
import { blocker, cartTotal, isSoldOut, lineTotalOf, setLineNotes, setQuantity, stepLine, unitPriceOf, removeLine, type Cart as CartState, type CartLine, type Refusal } from "@/lib/cart";
import { Alert, Button, Modal, ProductImage } from "../ui";
import { controlClass, focusRing } from "../styles";

// The order's lines and its send, the "Cart" region of the order panel, as the design board draws it (fresh-counter.md 3.2, step 6.3a):
// each line on one row, its 48 px thumb (the photo the menu has for the product now, so a photo changed or removed since it was added
// shows as it is), the name with its note or modifiers under it, the stepper (− and + around the count) and the line total; then the item
// count and the subtotal, the total (30 / 700), the `xl` Send with its arrow and the secondary button under it. A tap on a line's name
// opens it: its note and "Remove". The total is computed here with decimal strings (the API's totals confirm it once the order is sent,
// on "My orders"). A refused send keeps the cart exactly as it was and marks the line the API names: the shortfall (ingredient, quantity
// short, station) or another refusal, in the page language.

const stepKey = `inline-flex h-10 w-9 shrink-0 items-center justify-center rounded-[10px] bg-ground text-neutral-900 hover:bg-neutral-200 disabled:cursor-not-allowed disabled:text-neutral-400 ${focusRing}`;

function refusalText(refusal: Refusal, locale: Locale): string {
  return (locale === "am" ? refusal.message_am : refusal.message_en) || refusal.message_en || refusal.key;
}

/** One line opened: its note for the kitchen and "Remove", over the pad. */
function LineSheet({ line, busy, onChange, onClose }: { line: CartLine; busy: boolean; onChange: (change: (cart: CartState) => CartState) => void; onClose: () => void }) {
  const t = useTranslations("pad");
  const locale = useLocale() as Locale;
  const name = locale === "am" ? line.item.name_am : line.item.name_en;
  const modifiers = line.item.modifiers.filter((m) => line.modifierIds.includes(m.id)).map((m) => (locale === "am" ? m.name_am : m.name_en));
  const note = useRef<HTMLInputElement>(null);
  useEffect(() => note.current?.focus(), []);

  return (
    <Modal label={t("line_edit", { name })} onClose={onClose}>
      <div>
        <h3 className="text-lg font-semibold text-neutral-900">{name}</h3>
        <p className="text-[15px] tabular-nums text-neutral-600">
          {line.item.currency} {unitPriceOf(line)}
          {modifiers.length > 0 ? ` · ${modifiers.join(", ")}` : ""}
        </p>
      </div>
      <input
        ref={note}
        aria-label={t("note_label", { name })}
        placeholder={t("options_note")}
        value={line.notes}
        maxLength={200}
        disabled={busy}
        onChange={(event) => onChange((c) => setLineNotes(c, line.uuid, event.target.value))}
        onKeyDown={(event) => {
          if (event.key === "Enter") onClose();
        }}
        className={controlClass("lg")}
      />
      <div className="flex flex-wrap gap-2">
        <Button type="button" size="lg" onClick={onClose}>
          {t("done")}
        </Button>
        <Button
          type="button"
          size="lg"
          variant="danger"
          disabled={busy}
          onClick={() => {
            onChange((c) => removeLine(c, line.uuid));
            onClose();
          }}
        >
          {t("remove", { name })}
        </Button>
      </div>
    </Modal>
  );
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
  const meta = [line.notes.trim(), ...modifiers].filter((part) => part !== "").join(" · ");
  const short = cart.shortfall.filter((s) => s.line_client_uuid === line.uuid);
  const refusal = cart.lineErrors[line.uuid];
  const soldOut = isSoldOut(line, menu);
  const busy = cart.sending;
  // the menu's photo of the product as it is now; the line's own copy of the menu entry only when the menu no longer lists it
  const now = menu?.find((m) => m.item_id === line.item.item_id);
  const image = now ? now.image : line.item.image;
  const [open, setOpen] = useState(false);

  return (
    <li className="border-b border-neutral-100 py-3">
      <div className="flex items-center gap-3">
        <button
          type="button"
          aria-label={t("line_edit", { name })}
          aria-haspopup="dialog"
          onClick={() => setOpen(true)}
          className={`flex min-w-0 flex-1 items-center gap-3 rounded-lg text-left ${focusRing}`}
        >
          <ProductImage image={image} name={name} category={category} size="thumb" className="w-12 shrink-0 rounded-lg" />
          <span className="flex min-w-0 flex-col gap-0.5">
            <span className="truncate text-[15px] font-semibold text-neutral-900">{name}</span>
            {meta ? <span className="truncate text-[13px] text-neutral-600">{meta}</span> : null}
          </span>
        </button>
        <div className="flex shrink-0 items-center gap-0.5 rounded-xl border border-neutral-200 p-0.5">
          <button type="button" aria-label={t("decrease", { name })} className={stepKey} disabled={busy} onClick={() => onChange((c) => stepLine(c, line.uuid, -1))}>
            <Minus className="size-4" strokeWidth={2.25} aria-hidden="true" />
          </button>
          <input
            aria-label={t("qty_label", { name })}
            inputMode="numeric"
            value={trimDecimal(line.qty)}
            disabled={busy}
            onChange={(event) => onChange((c) => setQuantity(c, line.uuid, event.target.value))}
            className={`block h-10 w-7 rounded-md bg-transparent text-center text-base font-semibold tabular-nums text-neutral-900 disabled:text-neutral-500 ${focusRing}`}
          />
          <button type="button" aria-label={t("increase", { name })} className={stepKey} disabled={busy} onClick={() => onChange((c) => stepLine(c, line.uuid, 1))}>
            <Plus className="size-4" strokeWidth={2.25} aria-hidden="true" />
          </button>
        </div>
        <span className="min-w-[58px] shrink-0 text-right text-[15px] font-semibold tabular-nums text-neutral-900">{lineTotalOf(line)}</span>
      </div>
      {soldOut && short.length === 0 && !refusal ? <p className="mt-2 text-[13px] font-semibold text-amber-800">{t("sold_out_line")}</p> : null}
      {short.length > 0 || refusal ? (
        <div role="alert" className="mt-2 space-y-1 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-[15px] text-red-800">
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
      {open ? <LineSheet line={line} busy={busy} onChange={onChange} onClose={() => setOpen(false)} /> : null}
    </li>
  );
}

/** How many of everything the cart holds: the lines' counts added up, as a decimal string ("4"). */
export function unitsOf(cart: CartState): string {
  return trimDecimal(cart.lines.reduce((sum, line) => addDecimal(sum, line.qty, 4), "0"));
}

/** The order's note for the kitchen: a quiet "Add an order note" until there is one. */
function OrderNote({ cart, onChange }: { cart: CartState; onChange: (change: (cart: CartState) => CartState) => void }) {
  const t = useTranslations("pad");
  const [writing, setWriting] = useState(false);
  const field = useRef<HTMLInputElement>(null);
  useEffect(() => {
    if (writing) field.current?.focus();
  }, [writing]);

  if (!writing && cart.notes === "") {
    return (
      <button
        type="button"
        disabled={cart.sending}
        onClick={() => setWriting(true)}
        className={`mt-2 inline-flex min-h-11 items-center gap-2 rounded-xl px-2 text-[15px] font-semibold text-neutral-700 hover:bg-neutral-100 ${focusRing}`}
      >
        <NotebookPen className="size-[18px]" strokeWidth={1.75} aria-hidden="true" />
        {t("order_note_add")}
      </button>
    );
  }
  return (
    <label className="mt-2 block space-y-1.5 pb-2">
      <span className="block text-sm font-semibold text-neutral-900">
        {t("order_note")} <span className="font-normal text-neutral-600">({t("optional")})</span>
      </span>
      <input
        ref={field}
        value={cart.notes}
        maxLength={200}
        disabled={cart.sending}
        onChange={(event) => onChange((c) => ({ ...c, notes: event.target.value }))}
        onBlur={() => setWriting(false)}
        className={`${controlClass("md")} text-[15px]`}
      />
    </label>
  );
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
  const units = unitsOf(cart);
  const categoryOf = (line: CartLine) => {
    const category = line.item.category_id === null ? undefined : categories?.find((c) => c.id === line.item.category_id);
    return category ? `${category.name_en} ${category.name_am}` : null;
  };

  return (
    <section aria-label={t("cart")} className="flex min-h-0 flex-1 flex-col gap-3.5">
      {/* the lines scroll above the totals; the side padding gives the focus rings room inside the scroller */}
      <div className="-mx-2 min-h-0 flex-1 overflow-y-auto px-2">
        {cart.error ? (
          <div className="pb-2">
            <Alert>{refusalText(cart.error, locale)}</Alert>
          </div>
        ) : null}
        {cart.lines.length === 0 ? (
          <div className="flex flex-col items-center gap-3 px-4 py-8 text-center">
            <span aria-hidden="true" className="inline-flex size-12 items-center justify-center rounded-full bg-neutral-100 text-neutral-500">
              <ShoppingBasket className="size-6" strokeWidth={1.75} aria-hidden="true" />
            </span>
            <p className="max-w-60 text-[15px] text-neutral-600">{t("cart_empty")}</p>
          </div>
        ) : (
          <>
            <ul>
              {cart.lines.map((line) => (
                <LineRow key={line.uuid} line={line} cart={cart} menu={menu} category={categoryOf(line)} onChange={onChange} />
              ))}
            </ul>
            <OrderNote cart={cart} onChange={onChange} />
          </>
        )}
      </div>
      <div className="flex shrink-0 flex-col gap-2 pt-1">
        <p className="flex justify-between gap-3 text-[15px] text-neutral-600">
          {/* a count of whole products for the plural: the pad's quantities are whole numbers of 1 to 999 */}
          <span>{t("items_count", { count: Number(units) })}</span>
          <span className="tabular-nums">{t("subtotal", { total, currency })}</span>
        </p>
        <p className="flex items-baseline justify-between gap-3 text-neutral-900">
          <span className="text-[17px] font-semibold">{t("total")}</span>
          <span className="text-3xl font-bold tracking-tight tabular-nums">
            {currency} {total}
          </span>
        </p>
      </div>
      {stop === "table" ? <p className="-mt-1 text-[15px] font-medium text-amber-800">{t("need_table")}</p> : null}
      <div className="flex shrink-0 flex-col gap-3.5">
        <Button type="button" size="xl" className="w-full gap-2.5" disabled={stop !== null || cart.sending} onClick={onSend}>
          {cart.sending ? null : <ArrowRight className="size-[22px]" strokeWidth={2} aria-hidden="true" />}
          {cart.sending ? t("sending") : t("send")}
        </Button>
        <Button type="button" variant="secondary" className="w-full text-[15px]" disabled={cart.sending || cart.lines.length === 0} onClick={onClear}>
          {cart.serverOrderId !== null ? t("cancel_unsent") : t("clear_cart")}
        </Button>
      </div>
    </section>
  );
}
