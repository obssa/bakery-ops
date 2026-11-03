"use client";

import { Search, SearchX, X } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useEffect, useId, useMemo, useRef, useState, type KeyboardEvent as ReactKeyboardEvent } from "react";
import type { Locale, Menu, MenuItem } from "@/lib/api/types";
import { compareDecimal } from "@/lib/decimal";
import { Alert, Button, IconButton, ProductImage, Skeleton } from "../ui";
import { controlClass, focusRing } from "../styles";
import { isUnavailable, ProductCard } from "./ProductCard";

// The menu (fresh-counter.md 3.2, step 6.3a): a 52 px search that filters the menu in the browser, by either name; the category chips in
// the "Menu categories" tablist ("New" first for products marked new, then the categories in their order, then "Other" for products
// without one), each with its count, hidden from readers so a tab is named by its label alone; and the grid of product cards, 2 columns
// on a phone, 3 from 640 px and 4 from 1152 px (72rem: the 1180 px tablet). While a search is typed, the chips and their counts follow what
// matches. Tap adds one; long-press, or the card's "More" button, opens the sheet with quantity, modifiers and a note.

export { isUnavailable };

type TabId = "new" | "other" | number;

/** The announcement of a tap on a card that cannot be sold stays this long (it is read out once). */
const SAID_MS = 4000;
const SKELETON_CARDS = 8;

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

/** The search as it is compared: trimmed, in lower case (Ethiopic has no case, so an Amharic name compares as typed). */
function normalise(text: string): string {
  return text.trim().toLocaleLowerCase();
}

/** The products whose English or Amharic name holds the search; all of them for an empty one. */
export function searchMenu(menu: Menu, query: string): Menu {
  const q = normalise(query);
  if (q === "") return menu;
  return { ...menu, items: menu.items.filter((item) => normalise(item.name_en).includes(q) || normalise(item.name_am).includes(q)) };
}

/** Quantity, modifiers and a note for one product, as a dialog over the pad. */
function OptionsSheet({
  item,
  category,
  onAdd,
  onClose,
}: {
  item: MenuItem;
  category: string | null;
  onAdd: (item: MenuItem, extra: { qty: string; modifierIds: number[]; notes: string }) => void;
  onClose: () => void;
}) {
  const t = useTranslations("pad");
  const locale = useLocale() as Locale;
  const name = locale === "am" ? item.name_am : item.name_en;
  const [qty, setQty] = useState("1");
  const [modifierIds, setModifierIds] = useState<number[]>([]);
  const [notes, setNotes] = useState("");
  const first = useRef<HTMLInputElement>(null);

  // the quantity is selected once, when the sheet opens (not again when the menu's next answer draws the pad anew)
  useEffect(() => first.current?.select(), []);
  useEffect(() => {
    function onKey(event: KeyboardEvent) {
      if (event.key === "Escape") onClose();
    }
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [onClose]);

  const valid = /^\d{1,3}$/.test(qty) && compareDecimal(qty, "0", 0) > 0;

  return (
    <div className="fixed inset-0 z-40 flex items-end justify-center bg-neutral-900/40 p-4 sm:items-center" onClick={onClose}>
      <form
        role="dialog"
        aria-modal="true"
        aria-label={t("options_title", { name })}
        className="max-h-full w-full max-w-md space-y-5 overflow-y-auto rounded-[20px] bg-white p-6 shadow-float"
        onClick={(event) => event.stopPropagation()}
        onSubmit={(event) => {
          event.preventDefault();
          if (valid) onAdd(item, { qty: qty.replace(/^0+(?=\d)/, ""), modifierIds, notes });
        }}
      >
        <div className="flex items-center gap-4">
          <ProductImage image={item.image} name={name} category={category} size="thumb" className="w-20 shrink-0 rounded-xl" />
          <div className="min-w-0">
            <h3 className="text-lg font-semibold leading-snug text-neutral-900">{name}</h3>
            <p className="text-base font-semibold tabular-nums text-neutral-700">
              {item.price} {item.currency}
            </p>
          </div>
        </div>
        <div className="space-y-1.5">
          <label htmlFor="pad-options-qty" className="block text-sm font-semibold text-neutral-900">
            {t("options_qty")}
          </label>
          <input
            id="pad-options-qty"
            ref={first}
            inputMode="numeric"
            value={qty}
            onChange={(event) => setQty(event.target.value.replace(/\D/g, "").slice(0, 3))}
            className={`${controlClass("lg")} w-28 text-lg font-semibold tabular-nums`}
          />
        </div>
        {item.modifiers.length > 0 ? (
          <fieldset className="space-y-1">
            <legend className="text-sm font-semibold text-neutral-900">{t("options_modifiers")}</legend>
            {item.modifiers.map((modifier) => (
              <label key={modifier.id} className="flex min-h-12 cursor-pointer items-center gap-3 rounded-xl px-2 text-base hover:bg-neutral-50">
                <input
                  type="checkbox"
                  className="size-5 accent-brand-600"
                  checked={modifierIds.includes(modifier.id)}
                  onChange={(event) => setModifierIds(event.target.checked ? [...modifierIds, modifier.id] : modifierIds.filter((id) => id !== modifier.id))}
                />
                <span className="flex-1">{locale === "am" ? modifier.name_am : modifier.name_en}</span>
                {compareDecimal(modifier.price_delta, "0") !== 0 ? (
                  <span className="tabular-nums text-neutral-700">
                    {compareDecimal(modifier.price_delta, "0") > 0 ? "+" : ""}
                    {modifier.price_delta}
                  </span>
                ) : null}
              </label>
            ))}
          </fieldset>
        ) : null}
        <div className="space-y-1.5">
          <label htmlFor="pad-options-note" className="block text-sm font-semibold text-neutral-900">
            {t("options_note")} <span className="font-normal text-neutral-600">({t("optional")})</span>
          </label>
          <input id="pad-options-note" value={notes} maxLength={200} onChange={(event) => setNotes(event.target.value)} className={controlClass("lg")} />
        </div>
        <div className="flex flex-wrap gap-2">
          <Button type="submit" size="lg" disabled={!valid}>
            {t("options_add")}
          </Button>
          <Button type="button" size="lg" variant="secondary" onClick={onClose}>
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
  sticky = false,
}: {
  menu: Menu | null;
  loading: boolean;
  error: string | null;
  onAdd: (item: MenuItem, extra?: { qty: string; modifierIds: number[]; notes: string }) => void;
  /** the search and the chips stay at the top while the grid scrolls under them (the pad's menu zone scrolls on its own); the zone then
   * leaves its top padding to them */
  sticky?: boolean;
}) {
  const t = useTranslations("pad");
  const locale = useLocale() as Locale;
  const ids = useId();
  const [query, setQuery] = useState("");
  const [chosen, setChosen] = useState<TabId | null>(null);
  const [sheet, setSheet] = useState<MenuItem | null>(null);
  const [said, setSaid] = useState("");

  useEffect(() => {
    if (!said) return;
    const off = setTimeout(() => setSaid(""), SAID_MS);
    return () => clearTimeout(off);
  }, [said]);

  const labels = useMemo(() => ({ new: t("tab_new"), other: t("tab_other") }), [t]);
  const found = useMemo(() => (menu ? searchMenu(menu, query) : null), [menu, query]);
  const tabs = useMemo(() => (found ? tabsOf(found, locale, labels) : []), [found, locale, labels]);
  const current = tabs.find((tab) => tab.id === chosen) ?? tabs[0];

  // the placeholder's icon reads the category's name, in either language
  const categories = useMemo(() => new Map((menu?.categories ?? []).map((c) => [c.id, `${c.name_en} ${c.name_am}`])), [menu]);
  const categoryOf = (item: MenuItem) => (item.category_id === null ? null : (categories.get(item.category_id) ?? null));

  const tabId = (id: TabId) => `${ids}-tab-${String(id)}`;
  const panelId = `${ids}-panel`;

  // the chips are a tablist: the arrow keys, Home and End move between them
  function onTabKey(event: ReactKeyboardEvent<HTMLDivElement>) {
    if (!current) return;
    const at = tabs.findIndex((tab) => tab.id === current.id);
    const next =
      event.key === "ArrowRight" ? (at + 1) % tabs.length : event.key === "ArrowLeft" ? (at - 1 + tabs.length) % tabs.length : event.key === "Home" ? 0 : event.key === "End" ? tabs.length - 1 : -1;
    if (next < 0) return;
    event.preventDefault();
    setChosen(tabs[next].id);
    document.getElementById(tabId(tabs[next].id))?.focus();
  }

  if (!menu) {
    if (error) return <Alert>{error}</Alert>;
    return (
      <div className="space-y-4">
        <p role="status" className="text-base text-neutral-600">
          {loading ? t("loading_menu") : null}
        </p>
        {loading ? (
          <ul aria-hidden="true" className="grid grid-cols-2 gap-3 sm:grid-cols-3 min-[72rem]:grid-cols-4">
            {Array.from({ length: SKELETON_CARDS }, (_, index) => (
              <li key={index} className="space-y-3 rounded-2xl border border-neutral-200 bg-white pb-3">
                <Skeleton className="aspect-[4/3] rounded-b-none rounded-t-[15px]" />
                <Skeleton className="mx-3 h-4" />
                <Skeleton className="mx-3 h-4 w-1/3" />
              </li>
            ))}
          </ul>
        ) : null}
      </div>
    );
  }

  return (
    <section className="space-y-3">
      <div className={`space-y-3 ${sticky ? "sticky top-0 z-10 bg-ground pb-1 pt-5" : ""}`}>
        <div className="relative">
          <Search className="pointer-events-none absolute left-4 top-1/2 size-5 -translate-y-1/2 text-neutral-500" strokeWidth={2} aria-hidden="true" />
          <input
            type="search"
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === "Escape" && query !== "") {
                event.stopPropagation();
                setQuery("");
              }
            }}
            aria-label={t("search")}
            placeholder={t("search")}
            autoComplete="off"
            spellCheck={false}
            enterKeyHint="search"
            className={`${controlClass("lg")} pl-12 ${query ? "pr-14" : ""} [&::-webkit-search-cancel-button]:appearance-none`}
          />
          {query ? (
            <IconButton label={t("search_clear")} size="md" className="absolute right-1 top-1/2 -translate-y-1/2" onClick={() => setQuery("")}>
              <X className="size-5" strokeWidth={2} aria-hidden="true" />
            </IconButton>
          ) : null}
        </div>
        {tabs.length > 0 ? (
          <div
            role="tablist"
            aria-label={t("menu_tabs")}
            onKeyDown={onTabKey}
            className="-mx-1.5 flex gap-2 overflow-x-auto px-1.5 py-1.5 [scrollbar-width:thin]"
          >
            {tabs.map((tab) => {
              const selected = tab.id === current?.id;
              return (
                <button
                  key={String(tab.id)}
                  id={tabId(tab.id)}
                  type="button"
                  role="tab"
                  aria-selected={selected}
                  aria-controls={panelId}
                  onClick={() => setChosen(tab.id)}
                  className={`inline-flex min-h-11 shrink-0 items-center gap-2 whitespace-nowrap rounded-full border px-4 text-[15px] font-semibold motion-safe:transition-colors motion-safe:duration-150 ${
                    selected ? "border-transparent bg-neutral-900 text-white" : "border-neutral-200 bg-white text-neutral-900 hover:bg-neutral-100"
                  } ${focusRing}`}
                >
                  {tab.label}
                  <span aria-hidden="true" className={`text-[13px] tabular-nums ${selected ? "text-white/70" : "font-medium text-neutral-600"}`}>
                    {tab.items.length}
                  </span>
                </button>
              );
            })}
          </div>
        ) : null}
      </div>
      {error ? (
        <p role="status" className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-base text-amber-900">
          {error}
        </p>
      ) : null}
      {/* a tap on a card that cannot be sold says why (the card outlines its reason too) */}
      <p role="status" className="sr-only">
        {said}
      </p>
      <div role="tabpanel" id={panelId} aria-labelledby={current ? tabId(current.id) : undefined}>
        {current && current.items.length > 0 ? (
          <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 min-[72rem]:grid-cols-4">
            {current.items.map((item) => (
              <li key={item.item_id}>
                <ProductCard
                  item={item}
                  locale={locale}
                  category={categoryOf(item)}
                  onAdd={(chosenItem) => onAdd(chosenItem)}
                  onMore={setSheet}
                  onUnavailable={(chosenItem, reason) =>
                    setSaid(t("unavailable_said", { name: locale === "am" ? chosenItem.name_am : chosenItem.name_en, reason }))
                  }
                />
              </li>
            ))}
          </ul>
        ) : query.trim() !== "" ? (
          <div className="flex flex-col items-center gap-3 px-6 py-12 text-center">
            <span aria-hidden="true" className="inline-flex size-12 items-center justify-center rounded-full bg-neutral-100 text-neutral-500">
              <SearchX className="size-6" strokeWidth={1.75} aria-hidden="true" />
            </span>
            <p className="text-base font-semibold text-neutral-900">{t("search_none", { query: query.trim() })}</p>
            <Button type="button" variant="secondary" size="lg" onClick={() => setQuery("")}>
              {t("search_clear")}
            </Button>
          </div>
        ) : (
          <p className="text-base text-neutral-600">{t("no_items")}</p>
        )}
      </div>
      {sheet ? (
        <OptionsSheet
          item={sheet}
          category={categoryOf(sheet)}
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
