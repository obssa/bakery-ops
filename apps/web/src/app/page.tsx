"use client";

import "@fontsource-variable/noto-sans-ethiopic/wght.css";
import "./globals.css";
import { useEffect } from "react";
import am from "../../messages/am.json";
import en from "../../messages/en.json";
import { chooseLocale, startTarget } from "@/lib/start";

const BASE = process.env.NEXT_PUBLIC_BASE_PATH ?? "";

/**
 * /app/: the start page. There is no server to negotiate a language (static export, ADR 0006), so the browser picks
 * one (the signed-in user's, else the browser's, else English) and replaces this page with /app/<locale>/.... LiteSpeed
 * also serves this page for any /app path without a language, which keeps the rest of the path.
 */
export default function StartPage() {
  useEffect(() => {
    let cancelled = false;
    void chooseLocale().then((locale) => {
      if (cancelled) return;
      const { pathname, search, hash } = window.location;
      window.location.replace(startTarget(pathname, search, hash, locale));
    });
    return () => {
      cancelled = true;
    };
  }, []);

  return (
    <html lang="en">
      <body className="min-h-screen bg-neutral-50 text-neutral-900 antialiased">
        <title>{en.app.name}</title>
        <main className="mx-auto flex max-w-xl flex-col gap-6 px-4 py-16">
          <h1 className="text-2xl font-semibold">{en.app.name}</h1>
          <p role="status" className="text-base text-neutral-600">
            {en.app.opening} <span lang="am">{am.app.opening}</span>
          </p>
          <ul className="flex gap-3">
            <li>
              <a href={`${BASE}/en/`} lang="en" className="inline-flex min-h-12 items-center rounded-md border border-neutral-300 bg-white px-5">
                {en.locale.en}
              </a>
            </li>
            <li>
              <a href={`${BASE}/am/`} lang="am" className="inline-flex min-h-12 items-center rounded-md border border-neutral-300 bg-white px-5">
                {am.locale.am}
              </a>
            </li>
          </ul>
        </main>
      </body>
    </html>
  );
}
