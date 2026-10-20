"use client";

import { useLocale, useTranslations } from "next-intl";
import type { Locale, MenuItem } from "@/lib/api/types";
import { blocker, cartTotal, isSoldOut, lineTotalOf, setLineNotes, setQuantity, stepLine, unitPriceOf, removeLine, type Cart as CartState, type CartLine, type Refusal } from "@/lib/cart";
import { trimDecimal } from "@/lib/decimal";
import { Alert, Button } from "../ui";
import { focusRing } from "../styles";

// The cart: lines with modifiers, notes and line totals, the order total computed here with decimal strings (the API
// totals confirm it once the order is sent, and show on "My orders"), and one big Send button. A refused send keeps
// the cart exactly as it was and marks the line the API names: the shortfall (ingredient, quantity short, station)
// or another refusal, in the page language.

const stepper = `inline-flex size-12 items-center justify-center rounded-md border border-neutral-300 bg-white text-2xl font-semibold text-neutral-900 hover:bg-neutral-100 disabled:cursor-not-allowed disabled:text-neutral-300 ${focusRing}`;

function refusalText(refusal: Refusal, locale: Locale): string {
  return (locale === "am" ? refusal.message_am : refusal.message_en) || refusal.message_en || refusal.key;
}

function LineRow({ line, cart, menu, onChange }: { line: CartLine; cart: CartState; menu: MenuItem[] | undefined; onChange: (change: (cart: CartState) => CartState) => void }) {
  const t = useTranslations("pad");
  const locale = useLocale() as Locale;
  const name = locale === "am" ? line.item.name_am : line.item.name_en;
  const modifiers = line.item.modifiers.filter((m) => line.modifierIds.includes(m.id)).map((m) => (locale === "am" ? m.name_am : m.name_en));
  const short = cart.shortfall.filter((s) => s.line_client_uuid === line.uuid);
  const refusal = cart.lineErrors[line.uuid];
  const soldOut = isSoldOut(line, menu);
  const busy = cart.sending;

  return (
    <li className="space-y-2 py-3">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-lg font-semibold text-neutral-900">{name}</p>
          <p className="text-sm text-neutral-600">
            {unitPriceOf(line)} {line.item.currency}
            {modifiers.length > 0 ? ` · ${modifiers.join(", ")}` : ""}
          </p>
        </div>
        <p className="shrink-0 text-lg font-semibold text-neutral-900">
          {lineTotalOf(line)} {line.item.currency}
        </p>
      </div>
      <div className="flex items-center gap-2">
        <button type="button" aria-label={t("decrease", { name })} className={stepper} disabled={busy} onClick={() => onChange((c) => stepLine(c, line.uuid, -1))}>
          −
        </button>
        <input
          aria-label={t("qty_label", { name })}
          inputMode="numeric"
          value={trimDecimal(line.qty)}
          disabled={busy}
          onChange={(event) => onChange((c) => setQuantity(c, line.uuid, event.target.value))}
          className={`block min-h-12 w-16 rounded-md border border-neutral-300 text-center text-lg font-semibold ${focusRing}`}
        />
        <button type="button" aria-label={t("increase", { name })} className={stepper} disabled={busy} onClick={() => onChange((c) => stepLine(c, line.uuid, 1))}>
          +
        </button>
        <button type="button" aria-label={t("remove", { name })} disabled={busy} onClick={() => onChange((c) => removeLine(c, line.uuid))} className={`ml-auto min-h-12 rounded-md px-3 text-base font-medium text-red-700 hover:bg-red-50 disabled:text-neutral-300 ${focusRing}`}>
          ✕
        </button>
      </div>
      <input
        aria-label={t("note_label", { name })}
        placeholder={t("options_note")}
        value={line.notes}
        maxLength={200}
        disabled={busy}
        onChange={(event) => onChange((c) => setLineNotes(c, line.uuid, event.target.value))}
        className={`block min-h-12 w-full rounded-md border border-neutral-300 px-3 text-base placeholder:text-neutral-400 ${focusRing}`}
      />
      {soldOut && short.length === 0 && !refusal ? <p className="text-sm font-medium text-amber-800">{t("sold_out_line")}</p> : null}
      {short.length > 0 || refusal ? (
        <div role="alert" className="space-y-1 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-base text-red-800">
          {short.map((s, index) => {
            const ingredient = (locale === "am" ? s.ingredient_name_am : s.ingredient_name_en) ?? s.ingredient_name_en ?? "";
            const station = locale === "am" ? s.station_name_am : s.station_name_en;
            const values = { ingredient, qty: trimDecimal(s.qty_short), unit: s.unit, station: station ?? "" };
            return (
              <p key={`${s.ingredient_item_id}-${index}`} className="font-medium">
                {station ? t("shortfall_line", values) : t("shortfall_line_no_station", values)}
              </p>
            );
          })}
          {short.length > 0 ? <p className="text-sm">{t("shortfall_hint")}</p> : null}
          {refusal ? <p className="font-medium">{refusalText(refusal, locale)}</p> : null}
        </div>
      ) : null}
    </li>
  );
}

export function Cart({
  cart,
  menu,
  currency,
  onChange,
  onSend,
  onClear,
}: {
  cart: CartState;
  menu: MenuItem[] | undefined;
  currency: string;
  onChange: (change: (cart: CartState) => CartState) => void;
  onSend: () => void;
  onClear: () => void;
}) {
  const t = useTranslations("pad");
  const locale = useLocale() as Locale;
  const stop = blocker(cart);

  return (
    <section aria-label={t("cart")} className="flex flex-col gap-3 rounded-lg border border-neutral-200 bg-white p-4">
      <h2 className="text-lg font-semibold text-neutral-900">{t("cart")}</h2>
      {cart.error ? <Alert>{refusalText(cart.error, locale)}</Alert> : null}
      {cart.lines.length === 0 ? (
        <p className="text-base text-neutral-600">{t("cart_empty")}</p>
      ) : (
        <ul className="divide-y divide-neutral-200">
          {cart.lines.map((line) => (
            <LineRow key={line.uuid} line={line} cart={cart} menu={menu} onChange={onChange} />
          ))}
        </ul>
      )}
      {cart.lines.length > 0 ? (
        <>
          <label className="block space-y-1.5">
            <span className="block text-sm font-medium text-neutral-800">
              {t("order_note")} <span className="font-normal text-neutral-500">({t("optional")})</span>
            </span>
            <input
              value={cart.notes}
              maxLength={200}
              disabled={cart.sending}
              onChange={(event) => onChange((c) => ({ ...c, notes: event.target.value }))}
              className={`block min-h-12 w-full rounded-md border border-neutral-300 px-3 text-base ${focusRing}`}
            />
          </label>
          <p className="flex items-baseline justify-between border-t border-neutral-200 pt-3 text-xl font-semibold text-neutral-900">
            <span>{t("total")}</span>
            <span>
              {cartTotal(cart)} {currency}
            </span>
          </p>
        </>
      ) : null}
      {stop === "table" ? <p className="text-sm text-neutral-600">{t("need_table")}</p> : null}
      <Button type="button" className="min-h-16 text-xl" disabled={stop !== null || cart.sending} onClick={onSend}>
        {cart.sending ? t("sending") : t("send")}
      </Button>
      {cart.lines.length > 0 ? (
        <Button type="button" variant="secondary" disabled={cart.sending} onClick={onClear}>
          {cart.serverOrderId !== null ? t("cancel_unsent") : t("clear_cart")}
        </Button>
      ) : null}
    </section>
  );
}
