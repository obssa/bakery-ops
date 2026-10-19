import { getTranslations } from "next-intl/server";
import { focusRing } from "@/components/styles";
import { PageHeader } from "@/components/ui";
import { Link } from "@/i18n/navigation";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";

export const generateMetadata = titledBy("admin", "title");

const CARDS = [
  { href: "/admin/catalog", title: "catalog", body: "catalog_desc" },
  { href: "/admin/people", title: "people", body: "people_desc" },
] as const;

export default async function AdminHome(props: LocaleProps) {
  await enterLocale(props);
  const t = await getTranslations("admin");
  return (
    <>
      <PageHeader title={t("title")} intro={t("intro")} />
      <ul className="grid gap-4 sm:grid-cols-2">
        {CARDS.map(({ href, title, body }) => (
          <li key={href}>
            <Link
              href={href}
              className={`flex min-h-28 flex-col gap-1 rounded-lg border border-neutral-200 bg-white p-5 hover:border-neutral-400 ${focusRing}`}
            >
              <span className="text-lg font-semibold text-neutral-900">{t(title)}</span>
              <span className="text-base text-neutral-600">{t(body)}</span>
            </Link>
          </li>
        ))}
      </ul>
    </>
  );
}
