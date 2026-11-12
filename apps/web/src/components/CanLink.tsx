"use client";

import type { ComponentProps } from "react";
import { Link } from "@/i18n/navigation";
import { canOpen } from "@/lib/access";
import { useSession } from "@/lib/session";

type LinkProps = ComponentProps<typeof Link>;

/** The path a Link goes to, from a string or a { pathname, query } object. */
function hrefPath(href: LinkProps["href"]): string {
  return typeof href === "string" ? href : String(href.pathname ?? "/");
}

/**
 * A link drawn only for a person whose role opens its page (ADR 0027, lib/access.ts canOpen). Anyone else gets nothing (`otherwise`
 * "none", for a card's or a toolbar's link) or the link's words without the link ("text", where it sits inside a sentence).
 */
export function CanLink({ otherwise = "none", ...props }: LinkProps & { otherwise?: "none" | "text" }) {
  const me = useSession((s) => s.me);
  if (canOpen(me, hrefPath(props.href))) return <Link {...props} />;
  return otherwise === "text" ? <span>{props.children}</span> : null;
}
