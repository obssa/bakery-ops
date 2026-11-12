"use client";

import { useTranslations } from "next-intl";
import { useEffect, type ReactNode } from "react";
import { Link, usePathname, useRouter } from "@/i18n/navigation";
import { homeOf } from "@/lib/access";
import { can, useSession } from "@/lib/session";
import { buttonClass } from "./styles";
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

  if (permission && !can(me, permission)) return <NoAccess />;

  return <>{children}</>;
}

/**
 * A page this person's role does not open, reached by a typed or old address (no menu or link leads here any more, ADR 0027): the page
 * says so and offers the way to the person's own screen (lib/access.ts homeOf, where they land after signing in).
 */
export function NoAccess() {
  const t = useTranslations();
  const me = useSession((s) => s.me);
  return (
    <div className="max-w-xl space-y-4">
      <Alert>{t("admin.no_access")}</Alert>
      {me ? (
        <Link href={homeOf(me)} className={buttonClass({ variant: "secondary", size: "md" })}>
          {t("admin.go_home")}
        </Link>
      ) : null}
    </div>
  );
}
