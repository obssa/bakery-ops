import type { NextConfig } from "next";
import createNextIntlPlugin from "next-intl/plugin";

const withNextIntl = createNextIntlPlugin("./src/i18n/request.ts");

// No `output: "standalone"`: pnpm's symlinked node_modules make the standalone copy fail on Windows
// (EPERM symlink) and CI serves the app with `next start` from .next anyway.
const nextConfig: NextConfig = {
  reactStrictMode: true,
  env: {
    NEXT_PUBLIC_API_URL: process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000",
  },
};

export default withNextIntl(nextConfig);
