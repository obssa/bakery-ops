import type { Metadata } from "next";
import { NextIntlClientProvider } from "next-intl";
import { getLocale, getMessages, getTranslations } from "next-intl/server";
import Link from "next/link";
import "./globals.css";

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("app");
  return { title: t("name"), description: t("tagline") };
}

const routes = [
  ["/login", "login"],
  ["/pad", "pad"],
  ["/till", "till"],
  ["/kds/kitchen", "kds_kitchen"],
  ["/kds/bar", "kds_bar"],
  ["/production", "production"],
  ["/admin", "admin"],
] as const;

export default async function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  const locale = await getLocale();
  const messages = await getMessages();
  const t = await getTranslations("nav");
  return (
    <html lang={locale}>
      <body className="min-h-screen bg-neutral-50 text-neutral-900 antialiased">
        <NextIntlClientProvider locale={locale} messages={messages}>
          <nav className="flex flex-wrap gap-3 border-b border-neutral-200 bg-white px-4 py-3 text-sm">
            {routes.map(([href, key]) => (
              <Link key={href} href={href} className="rounded px-3 py-2 hover:bg-neutral-100">
                {t(key)}
              </Link>
            ))}
          </nav>
          <main className="mx-auto max-w-5xl p-6">{children}</main>
        </NextIntlClientProvider>
      </body>
    </html>
  );
}
