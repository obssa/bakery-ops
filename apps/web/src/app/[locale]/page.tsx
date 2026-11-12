import { getTranslations } from "next-intl/server";
import { StartScreens } from "@/components/StartScreens";
import { PageHeader } from "@/components/ui";
import { enterLocale, type LocaleProps } from "@/lib/page";

// The start page of a language (step 6.2b): the app's name and what it does, then a card per screen this person can open (ADR 0027,
// StartScreens): one screen only and they go straight to it; signed out, the way to sign in.
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
        <StartScreens />
      </section>
    </div>
  );
}
