import { Smartphone } from "lucide-react";
import { getTranslations } from "next-intl/server";
import { Suspense } from "react";
import { AppMark, ScreensNav } from "@/components/AppShell";
import { LanguageSwitcher, LanguageSwitcherWithQuery } from "@/components/LanguageSwitcher";
import { PageHeader } from "@/components/ui";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";
import { LoginForm } from "./LoginForm";

export const generateMetadata = titledBy("login", "title");

const BASE = process.env.NEXT_PUBLIC_BASE_PATH ?? "";

// The sign-in screen (step 6.2b, fresh-counter.md 3.1): a split screen without the station top bar. On the left, 45 % of the width, the
// bakery's photo (public/images/login-counter.webp, its licence beside it in LICENSES.json) with the app's name and one line over a band at
// its foot; on a phone the photo is a 160 px header. On the right, a white column: the language switch at the top right, the form (at most
// 420 px) with its 52 px fields and the 64 px button, the errors in the form, never a toast; at the foot, where to get a password and the
// "Screens" navigation, which keeps its name and its link names here (phase-6 plan 6.0.2). Phone and password only: the "Staff code" tab
// waits (plan 10.2 Q1; a short code unlocks a pad that is already signed in).
export default async function LoginPage(props: LocaleProps) {
  await enterLocale(props);
  const t = await getTranslations();

  return (
    <div className="flex flex-1 flex-col bg-white md:flex-row">
      <div className="relative h-40 shrink-0 overflow-hidden bg-neutral-900 md:h-auto md:w-[45%]">
        {/* decorative (the name is in the band): an empty alt; a static export serves no image optimiser (next.config images.unoptimized) */}
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img
          src={`${BASE}/images/login-counter.webp`}
          alt=""
          width={960}
          height={1500}
          decoding="async"
          fetchPriority="high"
          className="absolute inset-0 size-full object-cover"
        />
        <div className="absolute inset-x-0 bottom-0 flex flex-col gap-2.5 bg-neutral-900/60 px-6 py-5 text-white md:px-9 md:py-8">
          <p className="flex items-center gap-3">
            <AppMark size="lg" />
            <span className="text-[22px] font-bold tracking-tight">{t("app.name")}</span>
          </p>
          <p className="hidden text-[17px] leading-normal md:block">{t("app.tagline")}</p>
        </div>
      </div>

      <div className="flex min-w-0 flex-1 flex-col gap-8 px-4 py-6 sm:px-10 sm:py-7">
        <div className="flex justify-end">
          <Suspense fallback={<LanguageSwitcher look="pair" />}>
            <LanguageSwitcherWithQuery look="pair" />
          </Suspense>
        </div>

        <div className="my-auto w-full max-w-[420px] self-center">
          <PageHeader title={t("login.title")} intro={t("login.intro")} />
          <LoginForm />
          <p className="mt-6 flex items-start gap-3 rounded-2xl bg-ground px-4 py-3.5 text-sm leading-relaxed text-neutral-700">
            <Smartphone className="mt-0.5 size-5 shrink-0 text-neutral-600" strokeWidth={1.75} aria-hidden="true" />
            <span>{t("login.station_hint")}</span>
          </p>
        </div>

        <footer className="flex flex-col gap-2 border-t border-neutral-200 pt-4">
          <p className="text-[13px] text-neutral-600">{t("login.forgot")}</p>
          <div className="-mx-2.5">
            <ScreensNav look="links" />
          </div>
        </footer>
      </div>
    </div>
  );
}
