import type { NextConfig } from "next";
import createNextIntlPlugin from "next-intl/plugin";

const withNextIntl = createNextIntlPlugin("./src/i18n/request.ts");

// The shell lives under /app on the API's own origin (ADR 0006): the live host is shared cPanel (LiteSpeed, no Node
// server), so deploy.yml builds a static export (NEXT_OUTPUT=export -> out/) and copies it to apps/api/public/app/.
// Locally and in CI `next start` serves the same pages under the same /app base path.
//
// Export-safe by construction: every route is prerendered (both locales via generateStaticParams), no middleware,
// no cookies()/headers(), no server actions, no dynamic segments beyond [locale]; record pages use ?id= queries.
// trailingSlash: every page is a folder with index.html, which LiteSpeed serves without rewrites.
//
// NEXT_PUBLIC_API_URL is the API origin baked into the bundle. Default: http://localhost:8000 (compose) for
// `next build`, empty for the export, i.e. same origin: the live shell calls /api/v1 on whatever host served it.
const basePath = "/app";
const exporting = process.env.NEXT_OUTPUT === "export";

const nextConfig: NextConfig = {
  reactStrictMode: true,
  basePath,
  trailingSlash: true,
  images: { unoptimized: true },
  ...(exporting ? { output: "export" as const } : {}),
  env: {
    NEXT_PUBLIC_API_URL: process.env.NEXT_PUBLIC_API_URL ?? (exporting ? "" : "http://localhost:8000"),
    NEXT_PUBLIC_BASE_PATH: basePath,
    // the shell cache (public/sw.js) exists only in the static export, so only that build registers it
    NEXT_PUBLIC_SHELL_CACHE: exporting ? "1" : "",
    // Step 5.5a (ADR 0021): error tracking is a build-time switch (the GitHub variable NEXT_PUBLIC_SENTRY_DSN, read by deploy.yml). Always
    // defined, "" when unset, so the compiler sees a constant and leaves the Sentry SDK out of the bundle (components/ErrorTracking.tsx).
    NEXT_PUBLIC_SENTRY_DSN: process.env.NEXT_PUBLIC_SENTRY_DSN ?? "",
  },
};

export default withNextIntl(nextConfig);
