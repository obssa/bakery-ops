"use client";

import { useLocale, useTranslations } from "next-intl";
import { ApiError } from "./api/client";
import type { Locale } from "./api/types";

/**
 * The API's errors carry a message_key with message_en and message_am (rule 9): show the one for the page's
 * language. A network failure, or an error without a message, falls back to the shell's own dictionary.
 */
export function useErrorText() {
  const t = useTranslations("errors");
  const locale = useLocale() as Locale;

  return {
    message(error: unknown): string {
      if (error instanceof ApiError) {
        if (error.isNetwork) return t("network");
        if (error.status === 401 && error.key === "auth.unauthenticated") return t("session_expired");
        return error.messages[locale] || error.messages.en || t("unknown");
      }
      return t("unknown");
    },
    /** A field's validation error: the API's own text in English, a plain Amharic "check this field" otherwise
     * (Laravel's validation messages are English only). */
    field(error: unknown, field: string): string | undefined {
      if (!(error instanceof ApiError)) return undefined;
      const messages = error.fieldErrors[field];
      if (!messages?.length) return undefined;
      return locale === "en" ? messages[0] : t("field");
    },
  };
}
