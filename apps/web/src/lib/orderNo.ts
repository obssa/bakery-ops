"use client";

import { useTranslations } from "next-intl";
import type { Order } from "./api/types";
import { dayText, type DateFormatLike } from "./dates";
import { useDateFormat } from "./useDates";

// Order numbers restart each business day (Africa/Addis_Ababa), so the unpaid queue can hold order 3 of today and order 3 of an
// earlier day. An order of today is named by its number; any other carries its date too, in every label and accessible name.

/** Today's business date, YYYY-MM-DD, in the bakery's time zone. */
export function businessToday(now: Date = new Date()): string {
  return new Intl.DateTimeFormat("en-CA", { timeZone: "Africa/Addis_Ababa" }).format(now);
}

/**
 * The date of a business day for display ("21 Oct"; in the person's calendar with their date format, step 4.5f), or null for an order of
 * today or without a date. A bare locale reads Gregorian.
 */
export function otherDayLabel(businessDate: string | null, fmt: DateFormatLike, today: string = businessToday()): string | null {
  if (!businessDate || businessDate === today) return null;
  return dayText(businessDate, fmt);
}

/** "3" for an order of today, "3 of 21 Oct" ("3፣ 21 ጥቅምት" in Amharic) for an earlier day's. */
export function useOrderNo(order: Pick<Order, "order_no" | "business_date">): string {
  const t = useTranslations("common");
  const dates = useDateFormat();
  const no = String(order.order_no);
  const date = otherDayLabel(order.business_date, dates);
  return date ? t("order_no_dated", { no, date }) : no;
}
