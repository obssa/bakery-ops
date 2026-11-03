"use client";

import { useLocale, useTranslations } from "next-intl";
import { useSearchParams } from "next/navigation";
import { Link, usePathname } from "@/i18n/navigation";
import { routing, type Locale } from "@/i18n/routing";
import { buttonClass, focusRing } from "./styles";

type Look = "toggle" | "pair";

/** The switcher with the current query string kept (?kind=..., ?id=...); needs a Suspense boundary. */
export function LanguageSwitcherWithQuery({ look }: { look?: Look }) {
  const search = useSearchParams();
  return <LanguageSwitcher look={look} query={Object.fromEntries(search.entries())} />;
}

/**
 * The same page in the other language: a plain link to /app/<other>/<same path>/ (a static export has no server to negotiate the
 * language). Each language is named in its own script. Rendered without the query in the static HTML. Two looks (step 6.2b,
 * fresh-counter.md 3 and 3.1): `toggle`, the station top bar's one button, which names the other language; `pair`, the sign-in screen's
 * two languages on a white track, the current one in ink.
 */
export function LanguageSwitcher({ query = {}, look = "pair" }: { query?: Record<string, string>; look?: Look }) {
  const t = useTranslations();
  const locale = useLocale() as Locale;
  const pathname = usePathname();

  if (look === "toggle") {
    const other = routing.locales.find((target) => target !== locale) ?? routing.defaultLocale;
    return (
      <Link href={{ pathname, query }} locale={other} lang={other} hrefLang={other} className={buttonClass({ variant: "secondary", compact: true })}>
        {t(`locale.${other}`)}
      </Link>
    );
  }

  return (
    <nav aria-label={t("common.language")} className="inline-flex items-stretch gap-1 rounded-full border border-neutral-200 bg-white p-1">
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
            className={`inline-flex min-h-11 min-w-11 items-center justify-center rounded-full px-4 text-sm font-semibold motion-safe:transition-colors motion-safe:duration-150 ${
              current ? "bg-neutral-900 text-white" : "text-neutral-900 hover:bg-neutral-100"
            } ${focusRing}`}
          >
            {t(`locale.${target}`)}
          </Link>
        );
      })}
    </nav>
  );
}
