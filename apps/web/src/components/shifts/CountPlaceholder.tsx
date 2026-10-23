"use client";

import { useTranslations } from "next-intl";
import { useRouter } from "@/i18n/navigation";
import { Button, PageHeader } from "../ui";

/** The stand-in for the count screen until step 3.7b replaces it: it says so, and goes back to the station screen. */
export function CountPlaceholder() {
  const t = useTranslations("shifts");
  const router = useRouter();
  return (
    <div className="max-w-xl space-y-4">
      <PageHeader title={t("count_title")} intro={t("count_soon")} />
      <Button type="button" variant="secondary" onClick={() => router.back()}>
        {t("count_back")}
      </Button>
    </div>
  );
}
