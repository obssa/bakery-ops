"use client";

import { useTranslations } from "next-intl";
import { useCallback } from "react";
import { waitText } from "./durations";

/** `(seconds) => "5:07" | "1 h 05" | "3 d"` in the page's language (lib/durations.ts): the one way the station screens print a wait. */
export function useWaitText(): (seconds: number) => string {
  const t = useTranslations("common");
  return useCallback((seconds: number) => waitText(seconds, { hours: (values) => t("wait_hours", values), days: (values) => t("wait_days", values) }), [t]);
}
