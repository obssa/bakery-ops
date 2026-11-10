"use client";

import type { UseQueryResult } from "@tanstack/react-query";
import { ChevronLeft, ChevronRight } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import type { ReactNode } from "react";
import { usePathname } from "@/i18n/navigation";
import { groupDigits } from "@/lib/analytics";
import { stampText } from "@/lib/dates";
import { trimDecimal } from "@/lib/decimal";
import { useErrorText } from "@/lib/errors";
import { can, useSession } from "@/lib/session";
import type { Locale } from "@/lib/api/types";
import { useDateFormat } from "@/lib/useDates";
import { SectionTabs } from "../admin/kit";
import { Alert, Button, Skeleton } from "../ui";

// Pieces the Inventory screens share: the section tabs, the loading and error states of a read, names in the page's
// language, quantities with their unit, and a page-by-page control. Step 6.4c: the tabs are the admin's segmented tabs under each page's
// title row (components/admin/kit.tsx SectionTabs), a first load shows grey blocks where the content will be, the pager is the admin size.

const SECTIONS = [
  { href: "/admin/inventory", key: "stock", permission: "inventory.view" },
  { href: "/admin/inventory/ledger", key: "ledger", permission: "inventory.view" },
  { href: "/admin/inventory/purchases", key: "purchases", permission: "purchases.record" },
  { href: "/admin/inventory/suppliers", key: "suppliers", permission: "purchases.record" },
  { href: "/admin/inventory/transfers", key: "transfers", permission: "inventory.view" },
  { href: "/admin/inventory/reorder", key: "reorder", permission: "inventory.view" },
] as const;

/** The Inventory screens the person may open: the navigation "Inventory sections" with the same link names, as segmented tabs. */
export function InventoryNav() {
  const t = useTranslations("inventory");
  const me = useSession((s) => s.me);
  const pathname = usePathname().replace(/\/+$/, "");
  const tabs = SECTIONS.filter((section) => can(me, section.permission)).map(({ href, key }) => ({ href, label: t(`nav.${key}`), current: pathname === href }));
  return <SectionTabs label={t("sections")} tabs={tabs} />;
}

/** The name of a place or an item in the page's language. */
export function useNamer(): (row: { name_en: string; name_am: string }) => string {
  const locale = useLocale() as Locale;
  return (row) => (locale === "am" ? row.name_am || row.name_en : row.name_en || row.name_am);
}

/**
 * "2.5000" and "kg" -> "2.5 kg", "25000.0000" and "g" -> "25,000 g": a figure as it reads on a screen, grouped by the characters of its
 * decimal string (groupDigits), never converted and never a float. A field a person edits takes trimDecimal alone.
 */
export function qtyFigure(value: string): string {
  return groupDigits(trimDecimal(value));
}

export function qty(value: string, unit: string): string {
  return unit ? `${qtyFigure(value)} ${unit}` : qtyFigure(value);
}

/**
 * An ISO instant as the inventory pages write it: "2026-10-22 8:05 afternoon" in Addis Ababa's day and on the Ethiopian clock, in the
 * person's calendar (lib/dates.ts stampText; before, the browser's own time zone and the 24-hour clock).
 */
export function useStamp(): (iso: string | null) => string {
  const dates = useDateFormat();
  return (iso) => stampText(iso, dates);
}

/** Shows a read's three states: loading (the line for readers and grey rows), the error with a retry, and its data. */
export function QueryBox<T>({ query, children }: { query: UseQueryResult<T>; children: (data: T) => ReactNode }) {
  const tc = useTranslations("common");
  const errorText = useErrorText();
  if (query.isPending) {
    return (
      <div className="space-y-3">
        <p className="text-[15px] text-neutral-600">{tc("loading")}</p>
        <div aria-hidden="true" className="space-y-3 rounded-[18px] border border-neutral-200 bg-white p-5">
          {[0, 1, 2, 3].map((i) => (
            <Skeleton key={i} className="h-10" />
          ))}
        </div>
      </div>
    );
  }
  if (query.isError) {
    return (
      <div className="space-y-3">
        <Alert>{errorText.message(query.error)}</Alert>
        <Button variant="secondary" size="md" onClick={() => void query.refetch()}>
          {tc("retry")}
        </Button>
      </div>
    );
  }
  return <>{children(query.data)}</>;
}

export function Pager({ page, lastPage, onPage }: { page: number; lastPage: number; onPage: (page: number) => void }) {
  const t = useTranslations("inventory");
  if (lastPage <= 1) return null;
  return (
    <nav aria-label={t("paging")} className="flex items-center justify-between gap-3">
      <Button variant="secondary" size="md" disabled={page <= 1} onClick={() => onPage(page - 1)}>
        <ChevronLeft className="size-[18px]" strokeWidth={2} aria-hidden="true" />
        {t("previous")}
      </Button>
      <span className="text-[13px] tabular-nums text-neutral-600">{t("page_of", { page, last: lastPage })}</span>
      <Button variant="secondary" size="md" disabled={page >= lastPage} onClick={() => onPage(page + 1)}>
        {t("next")}
        <ChevronRight className="size-[18px]" strokeWidth={2} aria-hidden="true" />
      </Button>
    </nav>
  );
}

export const TH = "py-2 pr-4 font-medium";
export const TD = "py-3 pr-4";
