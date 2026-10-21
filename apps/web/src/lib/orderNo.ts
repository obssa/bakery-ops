"use client";

import { useLocale, useTranslations } from "next-intl";
import type { Locale, Order } from "./api/types";

// Order numbers restart each business day (Africa/Addis_Ababa), so the unpaid queue can hold order 3 of today and order 3 of an
// earlier day. An order of today is named by its number; any other carries its date too, in every label and accessible name.

/** Today's business date, YYYY-MM-DD, in the bakery's time zone. */
export function businessToday(now: Date = new Date()): string {
  return new Intl.DateTimeFormat("en-CA", { timeZone: "Africa/Addis_Ababa" }).format(now);
}

/** The date of a business day for display ("21 Oct"), or null for an order of today or without a date. */
export function otherDayLabel(businessDate: string | null, locale: Locale, today: string = businessToday()): string | null {
  if (!businessDate || businessDate === today) return null;
  const date = new Date(`${businessDate}T12:00:00Z`);
  if (Number.isNaN(date.getTime())) return businessDate;
  return date.toLocaleDateString(locale === "am" ? "am-ET" : "en-GB", { day: "numeric", month: "short", timeZone: "UTC" });
}

/** "3" for an order of today, "3 of 21 Oct" ("3፣ 21 ጥቅምት" in Amharic) for an earlier day's. */
export function useOrderNo(order: Pick<Order, "order_no" | "business_date">): string {
  const t = useTranslations("common");
  const locale = useLocale() as Locale;
  const no = String(order.order_no);
  const date = otherDayLabel(order.business_date, locale);
  return date ? t("order_no_dated", { no, date }) : no;
}
