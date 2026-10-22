"use client";

import type { UseQueryResult } from "@tanstack/react-query";
import { useLocale, useTranslations } from "next-intl";
import type { ReactNode } from "react";
import { Link, usePathname } from "@/i18n/navigation";
import { trimDecimal } from "@/lib/decimal";
import { useErrorText } from "@/lib/errors";
import { can, useSession } from "@/lib/session";
import type { Locale } from "@/lib/api/types";
import { Alert, Button } from "../ui";
import { focusRing } from "../styles";

// Pieces the Inventory screens share: the section tabs, the loading and error states of a read, names in the page's
// language, quantities with their unit, and a page-by-page control.

const SECTIONS = [
  { href: "/admin/inventory", key: "stock", permission: "inventory.view" },
  { href: "/admin/inventory/ledger", key: "ledger", permission: "inventory.view" },
  { href: "/admin/inventory/purchases", key: "purchases", permission: "purchases.record" },
  { href: "/admin/inventory/suppliers", key: "suppliers", permission: "purchases.record" },
  { href: "/admin/inventory/transfers", key: "transfers", permission: "inventory.view" },
  { href: "/admin/inventory/reorder", key: "reorder", permission: "inventory.view" },
] as const;

/** The tabs under the admin tabs: the Inventory screens the person may open. */
export function InventoryNav() {
  const t = useTranslations("inventory");
  const me = useSession((s) => s.me);
  const pathname = usePathname().replace(/\/+$/, "");
  return (
    <nav aria-label={t("sections")} className="mb-6 flex flex-wrap gap-2">
      {SECTIONS.filter((section) => can(me, section.permission)).map(({ href, key }) => {
        const current = pathname === href;
        return (
          <Link
            key={href}
            href={href}
            aria-current={current ? "page" : undefined}
            className={`inline-flex min-h-12 items-center rounded-full border px-5 text-base ${
              current ? "border-neutral-900 bg-neutral-900 text-white" : "border-neutral-300 bg-white text-neutral-800 hover:bg-neutral-100"
            } ${focusRing}`}
          >
            {t(`nav.${key}`)}
          </Link>
        );
      })}
    </nav>
  );
}

/** The name of a place or an item in the page's language. */
export function useNamer(): (row: { name_en: string; name_am: string }) => string {
  const locale = useLocale() as Locale;
  return (row) => (locale === "am" ? row.name_am || row.name_en : row.name_en || row.name_am);
}

/** "2.5000" and "kg" -> "2.5 kg": a quantity as it reads on a screen, never converted. */
export function qty(value: string, unit: string): string {
  return unit ? `${trimDecimal(value)} ${unit}` : trimDecimal(value);
}

/** An ISO instant as "2026-10-22 14:05" in the browser's time zone, the same in both languages. */
export function stamp(iso: string | null): string {
  if (!iso) return "—";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  const two = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${two(d.getMonth() + 1)}-${two(d.getDate())} ${two(d.getHours())}:${two(d.getMinutes())}`;
}

/** Shows a read's three states: loading, the error with a retry, and its data. */
export function QueryBox<T>({ query, children }: { query: UseQueryResult<T>; children: (data: T) => ReactNode }) {
  const tc = useTranslations("common");
  const errorText = useErrorText();
  if (query.isPending) return <p className="py-6 text-base text-neutral-600">{tc("loading")}</p>;
  if (query.isError) {
    return (
      <div className="space-y-3">
        <Alert>{errorText.message(query.error)}</Alert>
        <Button variant="secondary" onClick={() => void query.refetch()}>
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
    <nav aria-label={t("paging")} className="mt-4 flex items-center justify-between gap-3">
      <Button variant="secondary" disabled={page <= 1} onClick={() => onPage(page - 1)}>
        {t("previous")}
      </Button>
      <span className="text-base text-neutral-700">{t("page_of", { page, last: lastPage })}</span>
      <Button variant="secondary" disabled={page >= lastPage} onClick={() => onPage(page + 1)}>
        {t("next")}
      </Button>
    </nav>
  );
}

export const TH = "py-2 pr-4 font-medium";
export const TD = "py-3 pr-4";
