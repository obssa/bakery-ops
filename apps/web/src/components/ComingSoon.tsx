import { getTranslations } from "next-intl/server";

/** A screen of a later phase: its name and the phase it arrives in (from the dictionaries). */
export async function ComingSoon({ namespace, titleKey = "title" }: { namespace: string; titleKey?: string }) {
  const t = await getTranslations(namespace);
  return (
    <section className="max-w-2xl space-y-3">
      <h1 className="text-2xl font-semibold tracking-tight">{t(titleKey)}</h1>
      <p className="inline-block rounded-md border border-neutral-200 bg-white px-4 py-3 text-base text-neutral-700">{t("coming")}</p>
    </section>
  );
}
