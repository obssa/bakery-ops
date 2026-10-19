import { getTranslations } from "next-intl/server";
import { focusRing } from "@/components/styles";
import { Link } from "@/i18n/navigation";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";

// /app/<locale>/not-found/: what LiteSpeed serves for an unknown page inside a language (public/.htaccess), so a
// mistyped address still shows the shell, in the right language, with a way back.
export const generateMetadata = titledBy("not_found", "title");

export default async function NotFoundPage(props: LocaleProps) {
  await enterLocale(props);
  const t = await getTranslations("not_found");
  return (
    <section className="max-w-2xl space-y-4">
      <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
      <p className="text-base text-neutral-600">{t("body")}</p>
      <Link href="/" className={`inline-flex min-h-12 items-center rounded-md border border-neutral-300 bg-white px-5 text-base ${focusRing}`}>
        {t("home")}
      </Link>
    </section>
  );
}
