"use client";

import { useLocale, useTranslations } from "next-intl";
import { usePathname } from "@/i18n/navigation";
import type { Locale, PaymentListRow, PaymentStatus, PaymentView } from "@/lib/api/types";
import { chipsOf, type PaymentChip } from "@/lib/adminPayments";
import { stampText, type DateFormatLike } from "@/lib/dates";
import { otherDayLabel } from "@/lib/orderNo";
import { can, useSession } from "@/lib/session";
import { useDateFormat } from "@/lib/useDates";
import { SectionTabs } from "../admin/kit";
import { Chip, type Tone } from "../ui";

// Pieces the admin payments screens share (step 4.5c): the section tabs, names in the page's language, a status with its chips, an order's
// label and a money amount.

const SECTIONS = [
  { href: "/admin/payments", key: "payments", permission: "payments.view" },
  { href: "/admin/payments/reconciliation", key: "reconciliation", permission: "payments.verify" },
  { href: "/admin/payments/channels", key: "channels", permission: "channels.manage" },
  { href: "/admin/payments/receipts", key: "receipts", permission: "payments.take" },
] as const;

/**
 * The payments screens the person may open: the navigation "Payment sections" with the same link names, drawn as segmented tabs under the
 * title row (step 6.4c, components/admin/kit.tsx SectionTabs), as the catalog's Products and Ingredients switch is.
 */
export function PaymentsNav() {
  const t = useTranslations("adminPayments");
  const me = useSession((s) => s.me);
  const pathname = usePathname().replace(/\/+$/, "");
  const tabs = SECTIONS.filter((section) => can(me, section.permission)).map(({ href, key }) => ({ href, label: t(`nav.${key}`), current: pathname === href }));
  return <SectionTabs label={t("sections")} tabs={tabs} />;
}

/** A channel's (or any bilingual row's) name in the page's language; a channel the API no longer names shows its code. */
export function useChannelName(): (channel: { code: string | null; name_en: string | null; name_am: string | null }) => string {
  const locale = useLocale() as Locale;
  return (channel) => (locale === "am" ? channel.name_am || channel.name_en : channel.name_en || channel.name_am) || channel.code || "—";
}

/** "3" for an order of today, "3 of 21 Oct" for an earlier business day's (numbers restart daily), the date in the person's calendar. */
export function useOrderLabel(): (order: PaymentListRow["order"]) => string {
  const t = useTranslations("common");
  const dates = useDateFormat();
  return (order) => {
    if (!order || order.order_no === null) return "—";
    const no = String(order.order_no);
    const date = otherDayLabel(order.business_date, dates);
    return date ? t("order_no_dated", { no, date }) : no;
  };
}

// green is money confirmed only (fresh-counter.md 1.3): a verified payment; recorded is still to check (blue), a dispute red
const STATUS_TONE: Record<PaymentStatus, Tone> = {
  recorded: "blue",
  verified: "green",
  disputed: "red",
  voided: "neutral",
};

const CHIP_TONE: Record<PaymentChip, "amber" | "red"> = { disputed: "red", duplicate_reference: "amber", duplicate_receipt: "amber" };

/** The payment's status and its chips: a dispute is its own chip (so "Disputed" shows once), then the duplicates the API flagged. */
export function PaymentStatusCell({ payment }: { payment: PaymentView }) {
  const t = useTranslations("adminPayments");
  const chips = chipsOf(payment);
  return (
    <span className="flex flex-wrap gap-1.5">
      {payment.status !== "disputed" ? (
        <Chip tone={STATUS_TONE[payment.status]} dot>
          {t(`status.${payment.status}`)}
        </Chip>
      ) : null}
      {chips.map((chip) => (
        <Chip key={chip} tone={CHIP_TONE[chip]} dot>
          {t(`chip.${chip}`)}
        </Chip>
      ))}
    </span>
  );
}

/** An amount as the API sent it, with its currency (never parsed). */
export function Money({ amount, currency = "ETB" }: { amount: string | null; currency?: string }) {
  const t = useTranslations("adminPayments");
  if (amount === null) return <>—</>;
  return <span className="tabular-nums">{t("money", { amount, currency })}</span>;
}

/**
 * An ISO instant as "2026-10-27 14:05" in the bakery's time zone (Africa/Addis_Ababa), the same in both languages; with the person's date
 * format (lib/useDates.ts useDateFormat, step 4.5f) in their calendar: "Tikimt 17, 2019 14:05", or both. Every timestamp of the admin
 * screens goes through here (lib/dates.ts stampText).
 */
export function addisStamp(iso: string | null, fmt: DateFormatLike = "en"): string {
  return stampText(iso, fmt);
}

/** Saves a file the API answered (a Blob) under a name: the export, read with the bearer token. */
export function saveBlob(blob: Blob, name: string): void {
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement("a");
  anchor.href = url;
  anchor.download = name;
  document.body.appendChild(anchor);
  anchor.click();
  anchor.remove();
  setTimeout(() => URL.revokeObjectURL(url), 60_000);
}

// The cells of the tables of Phase 4 (the audit log reads them still); the payments screens are on the admin TableCard since step 6.4c.
export const TH = "py-2 pr-4 font-medium";
export const TD = "py-3 pr-4 align-top";
