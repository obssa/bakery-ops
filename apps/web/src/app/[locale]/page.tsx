import { ArrowRight } from "lucide-react";
import { getTranslations } from "next-intl/server";
import { ICON_STROKE, SCREEN_ICONS } from "@/components/icons";
import { focusRing } from "@/components/styles";
import { PageHeader } from "@/components/ui";
import { Link } from "@/i18n/navigation";
import { enterLocale, type LocaleProps } from "@/lib/page";
import { SCREENS } from "@/lib/screens";

// The start page of a language (step 6.2b): the app's name and what it does, then a card per screen with its line icon. Each card is a
// link named by the screen and "Open", as before.
export default async function Home(props: LocaleProps) {
  await enterLocale(props);
  const t = await getTranslations();

  return (
    <div className="space-y-6">
      <PageHeader title={t("app.name")} intro={t("app.tagline")} />
      <section aria-labelledby="screens" className="space-y-3">
        <h2 id="screens" className="text-lg font-semibold text-neutral-900">
          {t("home.screens")}
        </h2>
        <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {SCREENS.map(({ href, key }) => {
            const Icon = SCREEN_ICONS[key];
            return (
              <li key={href}>
                <Link
                  href={href}
                  className={`group flex min-h-32 flex-col justify-between gap-4 rounded-2xl border border-neutral-200 bg-white p-5 hover:border-brand-200 hover:bg-brand-50/40 motion-safe:transition-colors motion-safe:duration-150 ${focusRing}`}
                >
                  <span className="flex items-center gap-3">
                    <span aria-hidden="true" className="inline-flex size-11 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-700">
                      <Icon className="size-[22px]" strokeWidth={ICON_STROKE} aria-hidden="true" />
                    </span>
                    <span className="text-lg font-semibold text-neutral-900">{t(`nav.${key}`)}</span>
                  </span>
                  <span className="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700">
                    {t("home.open")}
                    <ArrowRight className="size-4 motion-safe:transition-transform motion-safe:duration-150 group-hover:translate-x-0.5" strokeWidth={2} aria-hidden="true" />
                  </span>
                </Link>
              </li>
            );
          })}
        </ul>
      </section>
    </div>
  );
}
