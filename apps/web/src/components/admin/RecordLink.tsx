"use client";

import { Link } from "@/i18n/navigation";
import { RECORD_PATH, RECORD_PERMISSION, recordQuery } from "@/lib/orderRecord";
import { can, useSession } from "@/lib/session";
import { buttonClass } from "../styles";
import { TEXT_LINK } from "./kit";

/**
 * A link to an order's record page (step 4.5d, /admin/orders/record?id=), for the people who may open it (payments.verify: the admin and the
 * manager): from the orders board's timeline, the payment dialog and an audit entry about an order. Nothing for anyone else. Step 6.4c: a
 * secondary button of the admin size (44 px), or inline a brand-red text link.
 */
export function RecordLink({ orderId, label, inline = false }: { orderId: number; label: string; inline?: boolean }) {
  const allowed = useSession((s) => can(s.me, RECORD_PERMISSION));
  if (!allowed) return null;
  const href = { pathname: RECORD_PATH, query: recordQuery(orderId) };
  return (
    <Link href={href} className={inline ? `inline-flex min-h-11 items-center ${TEXT_LINK}` : buttonClass({ variant: "secondary", size: "md" })}>
      {label}
    </Link>
  );
}
