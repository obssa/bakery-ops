import { SearchX } from "lucide-react";
import { getTranslations } from "next-intl/server";
import { ICON_STROKE } from "@/components/icons";
import { buttonClass } from "@/components/styles";
import { Link } from "@/i18n/navigation";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";

// /app/<locale>/not-found/: what LiteSpeed serves for an unknown page inside a language (public/.htaccess), so a mistyped address still
// shows the shell, in the right language, with a way back. Step 6.2b: an empty state (as ui.tsx EmptyState draws one) on a white card.
export const generateMetadata = titledBy("not_found", "title");

export default async function NotFoundPage(props: LocaleProps) {
  await enterLocale(props);
  const t = await getTranslations("not_found");
  return (
    <section className="mx-auto mt-6 flex max-w-xl flex-col items-center gap-3 rounded-[20px] border border-neutral-200 bg-white px-6 py-12 text-center">
      <span aria-hidden="true" className="inline-flex size-12 items-center justify-center rounded-full bg-neutral-100 text-neutral-500">
        <SearchX className="size-6" strokeWidth={ICON_STROKE} aria-hidden="true" />
      </span>
      <h1 className="text-2xl font-semibold tracking-tight text-neutral-900">{t("title")}</h1>
      <p className="max-w-md text-[15px] text-neutral-600">{t("body")}</p>
      <Link href="/" className={`${buttonClass({ variant: "secondary" })} mt-2`}>
        {t("home")}
      </Link>
    </section>
  );
}
