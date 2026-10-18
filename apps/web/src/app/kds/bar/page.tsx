import { getTranslations } from "next-intl/server";

export default async function Page() {
  const t = await getTranslations("kds");
  return (
    <section className="space-y-3">
      <h1 className="text-2xl font-semibold">{t("bar_title")}</h1>
      <p className="text-neutral-600">{t("coming")}</p>
    </section>
  );
}
