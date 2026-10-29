// The i18n scripts' fixture screen: uses every pad key, two of them through a template, and never orphan.old.
export function Pad({ status, count, qty, unit, name }: { status: string; count: number; qty: string; unit: string; name: string }) {
  const t = useTranslations("pad");
  return (
    <main>
      <h1>{t("title")}</h1>
      <button>{t("send", { count })}</button>
      <p>{t("unit", { qty, unit })}</p>
      <p>{t("brand")} {t("same")} {t("greet", { name })}</p>
      <p>{t(`status_${status}`)}</p>
    </main>
  );
}
