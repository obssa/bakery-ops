"use client";

import { useTranslations } from "next-intl";
import type { ComponentProps } from "react";
import { useOnline } from "@/lib/useOnline";
import { Button } from "./ui";

// The writes that never join the outbox (step 5.2a, plan 10.2 D10): one that needs a PIN or a second person (a void, a refund, a count's
// approval or rejection, the handover co-signature, an unlock) and the admin screens. Their buttons work as before while the browser is
// online; offline they are disabled and say that they need a connection, so nothing looks saved that is not.

/** A Button for an online-only write: disabled while offline, with "Needs a connection" in its text. */
export function OnlineOnlyButton({ disabled, children, ...props }: ComponentProps<typeof Button>) {
  const t = useTranslations("outbox");
  const online = useOnline();
  return (
    <Button {...props} disabled={disabled || !online}>
      {children}
      {online ? null : <span className="ml-2 text-sm font-normal">({t("online_only")})</span>}
    </Button>
  );
}
