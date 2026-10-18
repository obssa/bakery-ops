import { getTranslations } from "next-intl/server";

export default async function Home() {
  const t = await getTranslations("app");
  return (
    <section className="space-y-3">
      <h1 className="text-3xl font-semibold">{t("name")}</h1>
      <p className="text-neutral-600">{t("tagline")}</p>
      <p className="rounded bg-amber-50 px-3 py-2 text-sm text-amber-800">{t("phase_notice")}</p>
    </section>
  );
}
