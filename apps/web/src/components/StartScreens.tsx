"use client";

import { ArrowRight, LogIn } from "lucide-react";
import { useTranslations } from "next-intl";
import { useEffect, type ComponentType } from "react";
import { Link, useRouter } from "@/i18n/navigation";
import { screensFor } from "@/lib/access";
import { useSession } from "@/lib/session";
import { ICON_STROKE, SCREEN_ICONS } from "./icons";
import { focusRing } from "./styles";

// The start page's cards (step 6.2b; ADR 0027): one per screen this person can open, each named by the screen and "Open". A person with a
// single screen is taken straight to it; a signed-out visitor gets the way to sign in (no screen opens without it).

const CARD = `group flex min-h-32 flex-col justify-between gap-4 rounded-2xl border border-neutral-200 bg-white p-5 hover:border-brand-200 hover:bg-brand-50/40 motion-safe:transition-colors motion-safe:duration-150 ${focusRing}`;

function Card({ href, icon: Icon, name, action }: { href: string; icon: ComponentType<{ className?: string; strokeWidth?: number; "aria-hidden"?: boolean | "true" }>; name: string; action: string }) {
  return (
    <li>
      <Link href={href} className={CARD}>
        <span className="flex items-center gap-3">
          <span aria-hidden="true" className="inline-flex size-11 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-700">
            <Icon className="size-[22px]" strokeWidth={ICON_STROKE} aria-hidden="true" />
          </span>
          <span className="text-lg font-semibold text-neutral-900">{name}</span>
        </span>
        <span className="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700">
          {action}
          <ArrowRight className="size-4 motion-safe:transition-transform motion-safe:duration-150 group-hover:translate-x-0.5" strokeWidth={2} aria-hidden="true" />
        </span>
      </Link>
    </li>
  );
}

export function StartScreens() {
  const t = useTranslations();
  const router = useRouter();
  const status = useSession((s) => s.status);
  const me = useSession((s) => s.me);
  const screens = screensFor(me);
  const only = status === "signed-in" && screens.length === 1 ? screens[0]!.href : null;

  useEffect(() => {
    if (only) router.replace(only);
  }, [only, router]);

  if (status === "unknown" || only) {
    return (
      <p role="status" className="text-base text-neutral-600">
        {t("admin.checking")}
      </p>
    );
  }

  return (
    <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
      {status === "signed-out" ? (
        <Card href="/login" icon={LogIn} name={t("nav.login")} action={t("home.open")} />
      ) : (
        screens.map(({ href, key }) => <Card key={href} href={href} icon={SCREEN_ICONS[key]} name={t(`nav.${key}`)} action={t("home.open")} />)
      )}
    </ul>
  );
}
