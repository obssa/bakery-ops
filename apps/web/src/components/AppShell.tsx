"use client";

import { useTranslations } from "next-intl";
import { Suspense, type ReactNode } from "react";
import { Link, usePathname, useRouter } from "@/i18n/navigation";
import { useSession } from "@/lib/session";
import { LanguageSwitcher, LanguageSwitcherWithQuery } from "./LanguageSwitcher";
import { SCREENS } from "@/lib/screens";
import { focusRing } from "./styles";

// The frame around every page: brand, the screens of the system (later phases' screens say when they arrive), the
// language switcher and the sign-in state. One row on a 1280 px tablet, wrapping on smaller screens.


const navLink = `inline-flex min-h-12 items-center rounded-t-md px-3 text-base text-neutral-700 hover:bg-neutral-100 hover:text-neutral-900 ${focusRing}`;
const accountButton = `inline-flex min-h-12 items-center rounded-md px-4 text-base hover:bg-neutral-100 ${focusRing}`;

function isCurrent(pathname: string, href: string): boolean {
  const path = pathname.replace(/\/+$/, "") || "/";
  return path === href || path.startsWith(`${href}/`);
}

function Account() {
  const t = useTranslations("nav");
  const router = useRouter();
  const status = useSession((s) => s.status);
  const me = useSession((s) => s.me);
  const signOut = useSession((s) => s.signOut);

  if (status === "unknown") return <span className="min-h-12 w-24" aria-hidden="true" />;

  if (status !== "signed-in" || !me) {
    return (
      <Link href="/login" className={`${accountButton} font-medium text-blue-800`}>
        {t("login")}
      </Link>
    );
  }

  return (
    <div className="flex items-center gap-2">
      <span className="hidden text-sm text-neutral-600 lg:inline">{t("signed_in_as", { name: me.user.name })}</span>
      <button
        type="button"
        className={`${accountButton} border border-neutral-300 bg-white text-neutral-800`}
        onClick={async () => {
          await signOut();
          router.replace("/login");
        }}
      >
        {t("sign_out")}
      </button>
    </div>
  );
}

export function AppShell({ children }: { children: ReactNode }) {
  const t = useTranslations();
  const pathname = usePathname();

  return (
    <div className="flex min-h-screen flex-col">
      <a
        href="#content"
        className={`sr-only rounded-md bg-white px-4 py-3 text-base font-medium focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 ${focusRing}`}
      >
        {t("app.skip_to_content")}
      </a>
      <header className="border-b border-neutral-200 bg-white">
        <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-2 px-4 pt-2">
          <Link href="/" className={`inline-flex min-h-12 items-center rounded-md px-2 text-lg font-semibold text-neutral-900 ${focusRing}`}>
            {t("app.name")}
          </Link>
          <div className="flex items-center gap-2">
            <Suspense fallback={<LanguageSwitcher />}>
              <LanguageSwitcherWithQuery />
            </Suspense>
            <Account />
          </div>
        </div>
        <nav aria-label={t("nav.main")} className="mx-auto flex max-w-7xl flex-wrap gap-1 px-4 pb-1">
          {SCREENS.map(({ href, key }) => {
            const current = isCurrent(pathname, href);
            return (
              <Link
                key={href}
                href={href}
                aria-current={current ? "page" : undefined}
                className={`${navLink} border-b-2 ${current ? "border-neutral-900 font-medium text-neutral-900" : "border-transparent"}`}
              >
                {t(`nav.${key}`)}
              </Link>
            );
          })}
        </nav>
      </header>
      <main id="content" className="mx-auto w-full max-w-7xl flex-1 px-4 py-6">
        {children}
      </main>
    </div>
  );
}
