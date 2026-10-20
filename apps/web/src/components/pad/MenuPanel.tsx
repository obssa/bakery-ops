"use client";

import { useLocale, useTranslations } from "next-intl";
import { useEffect, useMemo, useRef, useState } from "react";
import type { Locale, Menu, MenuItem } from "@/lib/api/types";
import { compareDecimal, trimDecimal } from "@/lib/decimal";
import { Alert, Badge, Button } from "../ui";
import { focusRing } from "../styles";

// The menu: category tabs (a "New" tab first for products marked new, an "Other" tab for products without a category),
// and a tile per product with its price and availability ("3 left" when the API says low, the count otherwise, greyed
// with the reason when it cannot be sold). Tap adds one; long-press, or the visible "More" button, opens the sheet
// with quantity, modifiers and a note.

type TabId = "new" | "other" | number;

const LONG_PRESS_MS = 500;

export function isUnavailable(item: MenuItem): boolean {
  return item.unavailable_reason !== undefined || compareDecimal(item.available, "0") <= 0;
}

interface TabSpec {
  id: TabId;
  label: string;
  items: MenuItem[];
}

export function tabsOf(menu: Menu, locale: Locale, labels: { new: string; other: string }): TabSpec[] {
  const tabs: TabSpec[] = [];
  const fresh = menu.items.filter((item) => item.is_new);
  if (fresh.length > 0) tabs.push({ id: "new", label: labels.new, items: fresh });
  for (const category of [...menu.categories].sort((a, b) => a.sort - b.sort || a.id - b.id)) {
    const items = menu.items.filter((item) => item.category_id === category.id);
    if (items.length > 0) tabs.push({ id: category.id, label: locale === "am" ? category.name_am : category.name_en, items });
  }
  const known = new Set(menu.categories.map((c) => c.id));
  const other = menu.items.filter((item) => item.category_id === null || !known.has(item.category_id));
  if (other.length > 0) tabs.push({ id: "other", label: labels.other, items: other });
  return tabs;
}

function Tile({ item, onAdd, onMore }: { item: MenuItem; onAdd: (item: MenuItem) => void; onMore: (item: MenuItem) => void }) {
  const t = useTranslations("pad");
  const locale = useLocale() as Locale;
  const name = locale === "am" ? item.name_am : item.name_en;
  const unavailable = isUnavailable(item);
  const reason = unavailable ? t(`reason_${item.unavailable_reason ?? "out_of_stock"}`) : null;
  const availability = item.low ? t("left", { count: trimDecimal(item.available) }) : t("available_count", { count: trimDecimal(item.available) });

  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const pressed = useRef(false);
  const clear = () => {
    if (timer.current !== null) clearTimeout(timer.current);
    timer.current = null;
  };
  useEffect(() => clear, []);

  return (
    <li
      className={`relative flex flex-col rounded-lg border-2 ${
        unavailable ? "border-neutral-200 bg-neutral-100 text-neutral-500" : "border-neutral-300 bg-white text-neutral-900"
      }`}
    >
      <button
        type="button"
        disabled={unavailable}
        aria-label={unavailable ? t("unavailable_item", { name, price: item.price, currency: item.currency, reason: reason ?? "" }) : t("add_item", { name, price: item.price, currency: item.currency, availability })}
        onPointerDown={() => {
          pressed.current = false;
          clear();
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
          onAdd(item);
        }}
        className={`flex min-h-28 flex-1 flex-col items-start justify-between gap-2 rounded-t-md p-3 text-left disabled:cursor-not-allowed ${unavailable ? "" : "hover:bg-neutral-50 active:bg-neutral-100"} ${focusRing}`}
      >
        <span className="text-lg font-semibold leading-snug">{name}</span>
        <span className="flex w-full items-end justify-between gap-2">
          <span className="text-base font-medium">
            {item.price} {item.currency}
          </span>
          {unavailable ? <Badge tone="neutral">{reason}</Badge> : <Badge tone={item.low ? "amber" : "green"}>{availability}</Badge>}
        </span>
      </button>
      {unavailable ? null : (
        <button
          type="button"
          aria-label={t("more_options", { name })}
          onClick={() => onMore(item)}
          className={`min-h-12 rounded-b-md border-t border-neutral-200 text-base font-medium text-blue-800 hover:bg-blue-50 ${focusRing}`}
        >
          {t("more")}
        </button>
      )}
    </li>
  );
}

/** Quantity, modifiers and a note for one product, as a dialog over the pad. */
function OptionsSheet({ item, onAdd, onClose }: { item: MenuItem; onAdd: (item: MenuItem, extra: { qty: string; modifierIds: number[]; notes: string }) => void; onClose: () => void }) {
  const t = useTranslations("pad");
  const locale = useLocale() as Locale;
  const name = locale === "am" ? item.name_am : item.name_en;
  const [qty, setQty] = useState("1");
  const [modifierIds, setModifierIds] = useState<number[]>([]);
  const [notes, setNotes] = useState("");
  const first = useRef<HTMLInputElement>(null);

  useEffect(() => {
    first.current?.select();
    function onKey(event: KeyboardEvent) {
      if (event.key === "Escape") onClose();
    }
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [onClose]);

  const valid = /^\d{1,3}$/.test(qty) && compareDecimal(qty, "0", 0) > 0;

  return (
    <div className="fixed inset-0 z-40 flex items-end justify-center bg-neutral-900/50 p-4 sm:items-center" onClick={onClose}>
      <form
        role="dialog"
        aria-modal="true"
        aria-label={t("options_title", { name })}
        className="w-full max-w-md space-y-4 rounded-lg bg-white p-5 shadow-xl"
        onClick={(event) => event.stopPropagation()}
        onSubmit={(event) => {
          event.preventDefault();
          if (valid) onAdd(item, { qty: qty.replace(/^0+(?=\d)/, ""), modifierIds, notes });
        }}
      >
        <h3 className="text-lg font-semibold text-neutral-900">{name}</h3>
        <div className="space-y-1.5">
          <label htmlFor="pad-options-qty" className="block text-sm font-medium text-neutral-800">
            {t("options_qty")}
          </label>
          <input
            id="pad-options-qty"
            ref={first}
            inputMode="numeric"
            value={qty}
            onChange={(event) => setQty(event.target.value.replace(/\D/g, "").slice(0, 3))}
            className={`block min-h-12 w-28 rounded-md border border-neutral-300 px-3 text-lg ${focusRing}`}
          />
        </div>
        {item.modifiers.length > 0 ? (
          <fieldset className="space-y-1">
            <legend className="text-sm font-medium text-neutral-800">{t("options_modifiers")}</legend>
            {item.modifiers.map((modifier) => (
              <label key={modifier.id} className="flex min-h-12 cursor-pointer items-center gap-3 rounded-md px-2 text-base hover:bg-neutral-100">
                <input
                  type="checkbox"
                  className="size-5"
                  checked={modifierIds.includes(modifier.id)}
                  onChange={(event) => setModifierIds(event.target.checked ? [...modifierIds, modifier.id] : modifierIds.filter((id) => id !== modifier.id))}
                />
                <span className="flex-1">{locale === "am" ? modifier.name_am : modifier.name_en}</span>
                {compareDecimal(modifier.price_delta, "0") !== 0 ? <span className="text-neutral-700">{compareDecimal(modifier.price_delta, "0") > 0 ? "+" : ""}{modifier.price_delta}</span> : null}
              </label>
            ))}
          </fieldset>
        ) : null}
        <div className="space-y-1.5">
          <label htmlFor="pad-options-note" className="block text-sm font-medium text-neutral-800">
            {t("options_note")} <span className="font-normal text-neutral-500">({t("optional")})</span>
          </label>
          <input id="pad-options-note" value={notes} maxLength={200} onChange={(event) => setNotes(event.target.value)} className={`block min-h-12 w-full rounded-md border border-neutral-300 px-3 text-base ${focusRing}`} />
        </div>
        <div className="flex gap-2">
          <Button type="submit" disabled={!valid}>
            {t("options_add")}
          </Button>
          <Button type="button" variant="secondary" onClick={onClose}>
            {t("options_cancel")}
          </Button>
        </div>
      </form>
    </div>
  );
}

export function MenuPanel({
  menu,
  loading,
  error,
  onAdd,
}: {
  menu: Menu | null;
  loading: boolean;
  error: string | null;
  onAdd: (item: MenuItem, extra?: { qty: string; modifierIds: number[]; notes: string }) => void;
}) {
  const t = useTranslations("pad");
  const locale = useLocale() as Locale;
  const [chosen, setChosen] = useState<TabId | null>(null);
  const [sheet, setSheet] = useState<MenuItem | null>(null);

  const tabs = useMemo(() => (menu ? tabsOf(menu, locale, { new: t("tab_new"), other: t("tab_other") }) : []), [menu, locale, t]);
  const current = tabs.find((tab) => tab.id === chosen) ?? tabs[0];

  if (!menu) {
    return error ? <Alert>{error}</Alert> : (
      <p role="status" className="text-base text-neutral-600">
        {loading ? t("loading_menu") : null}
      </p>
    );
  }

  return (
    <section className="space-y-3">
      <div role="tablist" aria-label={t("menu_tabs")} className="flex flex-wrap gap-2">
        {tabs.map((tab) => (
          <button
            key={String(tab.id)}
            type="button"
            role="tab"
            aria-selected={tab.id === current?.id}
            onClick={() => setChosen(tab.id)}
            className={`min-h-12 rounded-full px-5 text-base font-medium ${tab.id === current?.id ? "bg-neutral-900 text-white" : "border border-neutral-300 bg-white text-neutral-800 hover:bg-neutral-100"} ${focusRing}`}
          >
            {tab.label}
          </button>
        ))}
      </div>
      {error ? (
        <p role="status" className="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-base text-amber-900">
          {error}
        </p>
      ) : null}
      <div role="tabpanel">
        {current && current.items.length > 0 ? (
          <ul className="grid grid-cols-2 gap-3 lg:grid-cols-3">
            {current.items.map((item) => (
              <Tile key={item.item_id} item={item} onAdd={(i) => onAdd(i)} onMore={setSheet} />
            ))}
          </ul>
        ) : (
          <p className="text-base text-neutral-600">{t("no_items")}</p>
        )}
      </div>
      {sheet ? (
        <OptionsSheet
          item={sheet}
          onClose={() => setSheet(null)}
          onAdd={(item, extra) => {
            onAdd(item, extra);
            setSheet(null);
          }}
        />
      ) : null}
    </section>
  );
}
