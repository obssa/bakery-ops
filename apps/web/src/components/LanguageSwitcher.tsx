"use client";

import { useLocale, useTranslations } from "next-intl";
import { useSearchParams } from "next/navigation";
import { Link, usePathname } from "@/i18n/navigation";
import { routing, type Locale } from "@/i18n/routing";
import { focusRing } from "./styles";

/** The switcher with the current query string kept (?kind=..., ?id=...); needs a Suspense boundary. */
export function LanguageSwitcherWithQuery() {
  const search = useSearchParams();
  return <LanguageSwitcher query={Object.fromEntries(search.entries())} />;
}

/**
 * The same page in the other language: a plain link to /app/<other>/<same path>/ (a static export has no server to
 * negotiate the language). Each language is named in its own script. Rendered without the query in the static HTML.
 */
export function LanguageSwitcher({ query = {} }: { query?: Record<string, string> }) {
  const t = useTranslations();
  const locale = useLocale() as Locale;
  const pathname = usePathname();

  return (
    <nav aria-label={t("common.language")} className="flex items-stretch overflow-hidden rounded-md border border-neutral-300 bg-white">
      {routing.locales.map((target) => {
        const current = target === locale;
        return (
          <Link
            key={target}
            href={{ pathname, query }}
            locale={target}
            lang={target}
            hrefLang={target}
            aria-current={current ? "true" : undefined}
            className={`inline-flex min-h-12 min-w-12 items-center justify-center px-4 text-base ${
              current ? "bg-neutral-900 text-white" : "text-neutral-700 hover:bg-neutral-100"
            } ${focusRing}`}
          >
            {t(`locale.${target}`)}
          </Link>
        );
      })}
    </nav>
  );
}
