"use client";

import { useTranslations } from "next-intl";
import { useEffect, type ReactNode } from "react";
import { usePathname, useRouter } from "@/i18n/navigation";
import { can, useSession } from "@/lib/session";
import { Alert, Button } from "./ui";

/**
 * Pages that need a signed-in user (and optionally a permission). The API enforces every permission itself (rule 6);
 * this only keeps people from staring at an empty page: signed out -> the login page, which comes back here after.
 */
export function RequireAuth({ permission, children }: { permission?: string; children: ReactNode }) {
  const t = useTranslations();
  const router = useRouter();
  const pathname = usePathname();
  const status = useSession((s) => s.status);
  const me = useSession((s) => s.me);
  const unreachable = useSession((s) => s.unreachable);
  const init = useSession((s) => s.init);

  useEffect(() => {
    if (status === "signed-out") router.replace({ pathname: "/login", query: { next: pathname } });
  }, [status, pathname, router]);

  if (status === "unknown" && unreachable) {
    return (
      <div className="max-w-xl space-y-4">
        <Alert>{t("errors.network")}</Alert>
        <Button variant="secondary" onClick={() => void init()}>
          {t("common.retry")}
        </Button>
      </div>
    );
  }

  if (status !== "signed-in" || !me) {
    return (
      <p role="status" className="text-base text-neutral-600">
        {t("admin.checking")}
      </p>
    );
  }

  if (permission && !can(me, permission)) {
    return (
      <div className="max-w-xl">
        <Alert>{t("admin.no_access")}</Alert>
      </div>
    );
  }

  return <>{children}</>;
}
