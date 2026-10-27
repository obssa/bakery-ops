"use client";

import { Link } from "@/i18n/navigation";
import { can, useSession } from "@/lib/session";

/** A card of the admin overview shown only to someone who holds its permission (step 4.5f: the audit log's, audit.view). */
export function PermittedCard({ permission, href, title, body, className }: { permission: string; href: string; title: string; body: string; className: string }) {
  const allowed = useSession((s) => can(s.me, permission));
  if (!allowed) return null;
  return (
    <li>
      <Link href={href} className={className}>
        <span className="text-lg font-semibold text-neutral-900">{title}</span>
        <span className="text-base text-neutral-600">{body}</span>
      </Link>
    </li>
  );
}
