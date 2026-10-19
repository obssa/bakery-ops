import { getTranslations } from "next-intl/server";
import { PageHeader } from "@/components/ui";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";
import { LoginForm } from "./LoginForm";

export const generateMetadata = titledBy("login", "title");

export default async function LoginPage(props: LocaleProps) {
  await enterLocale(props);
  const t = await getTranslations("login");
  return (
    <div className="mx-auto max-w-md">
      <PageHeader title={t("title")} intro={t("intro")} />
      <LoginForm />
    </div>
  );
}
