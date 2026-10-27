"use client";

import { Link } from "@/i18n/navigation";
import { RECORD_PATH, RECORD_PERMISSION, recordQuery } from "@/lib/orderRecord";
import { can, useSession } from "@/lib/session";
import { focusRing } from "../styles";

/**
 * A link to an order's record page (step 4.5d, /admin/orders/record?id=), for the people who may open it (payments.verify: the admin and the
 * manager): from the orders board's timeline, the payment dialog and an audit entry about an order. Nothing for anyone else.
 */
export function RecordLink({ orderId, label, inline = false }: { orderId: number; label: string; inline?: boolean }) {
  const allowed = useSession((s) => can(s.me, RECORD_PERMISSION));
  if (!allowed) return null;
  const href = { pathname: RECORD_PATH, query: recordQuery(orderId) };
  if (inline) {
    return (
      <Link href={href} className={`inline-flex min-h-12 items-center text-base text-blue-800 underline underline-offset-2 ${focusRing}`}>
        {label}
      </Link>
    );
  }
  return (
    <Link
      href={href}
      className={`inline-flex min-h-12 items-center rounded-md border border-neutral-300 bg-white px-4 text-base font-medium text-neutral-900 hover:bg-neutral-100 ${focusRing}`}
    >
      {label}
    </Link>
  );
}
