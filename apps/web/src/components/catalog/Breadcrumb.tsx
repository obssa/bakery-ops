"use client";

import { useTranslations } from "next-intl";
import { Link } from "@/i18n/navigation";
import { focusRing } from "../styles";

/** "Catalog / <current>" over a catalog page (the ProductPhoto board's breadcrumb): the product page and the new item page. */
export function Breadcrumb({ current }: { current?: string }) {
  const t = useTranslations("product");
  const tcat = useTranslations("catalog");
  return (
    <nav aria-label={t("breadcrumb")} className="flex flex-wrap items-center gap-2 text-sm text-neutral-600">
      <Link href="/admin/catalog" className={`rounded-md font-semibold text-brand-600 hover:text-brand-700 ${focusRing}`}>
        {tcat("title")}
      </Link>
      {current ? (
        <>
          <span aria-hidden="true">/</span>
          <span aria-current="page">{current}</span>
        </>
      ) : null}
    </nav>
  );
}
