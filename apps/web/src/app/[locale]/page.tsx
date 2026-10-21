import { getTranslations } from "next-intl/server";
import { focusRing } from "@/components/styles";
import { Link } from "@/i18n/navigation";
import { enterLocale, type LocaleProps } from "@/lib/page";
import { SCREENS } from "@/lib/screens";

export default async function Home(props: LocaleProps) {
  await enterLocale(props);
  const t = await getTranslations();

  return (
    <div className="space-y-8">
      <header className="space-y-2">
        <h1 className="text-3xl font-semibold tracking-tight">{t("app.name")}</h1>
        <p className="text-lg text-neutral-600">{t("app.tagline")}</p>
        <p className="max-w-3xl text-base text-neutral-600">{t("app.phase_notice")}</p>
      </header>
      <section aria-labelledby="screens" className="space-y-3">
        <h2 id="screens" className="text-lg font-semibold">
          {t("home.screens")}
        </h2>
        <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {SCREENS.map(({ href, key }) => {
            return (
              <li key={href}>
                <Link
                  href={href}
                  className={`flex min-h-24 flex-col justify-between gap-2 rounded-lg border border-neutral-200 bg-white p-4 hover:border-neutral-400 ${focusRing}`}
                >
                  <span className="text-lg font-medium text-neutral-900">{t(`nav.${key}`)}</span>
                  <span className="text-sm font-medium text-blue-800">{t("home.open")}</span>
                </Link>
              </li>
            );
          })}
        </ul>
      </section>
    </div>
  );
}
