// Generates the shell cache (out/sw.js) after a static export (NEXT_OUTPUT=export). It precaches the shell of the
// export (HTML, JS, CSS, fonts, and the shell's own pictures: the sign-in screen's photo, public/images/, step 6.2b) so a
// tablet that loses Wi-Fi still opens the app. Served from /app/sw.js, so its scope is /app/. Without out/ (a plain
// `next build` for `next start`) there is nothing to do.
//
// One runtime route (step 6.3a, ADR 0022 decision 8 and the note on ADR 0018): the product photos. Their URLs are public and
// content-addressed (/api/v1/media/items/<sha256>/<rendition>.<ext>, served `immutable`), so a photo never changes under its
// URL and a cached one is always right: CacheFirst, in the cache "product-images", at most 400 files for 30 days, and only a
// 200 is kept (a 404 for a removed photo never is). That is how a pad keeps its photos offline. It matches GET requests on the
// worker's own origin under /api/v1/media/ only: every other /api/ answer (the menu, orders, anything signed in) still goes
// to the network untouched and is never cached.
import { existsSync } from "node:fs";
import { generateSW } from "workbox-build";

if (process.env.NEXT_OUTPUT !== "export" || !existsSync("out/index.html")) {
  console.log("build-sw: not a static export build, skipping the shell cache");
  process.exit(0);
}

const { count, size, warnings } = await generateSW({
  globDirectory: "out",
  swDest: "out/sw.js",
  globPatterns: ["**/*.{html,js,css,woff2,woff,svg,ico,png,webp,jpg,webmanifest}"],
  globIgnores: ["sw.js", "workbox-*.js", "push-sw.js", "**/*.map"],
  // the push and notification-click handlers of the inbox (public/push-sw.js)
  importScripts: ["push-sw.js"],
  // /app/en/admin/catalog/?kind=product is the same page as /app/en/admin/catalog/
  ignoreURLParametersMatching: [/.*/],
  directoryIndex: "index.html",
  cleanupOutdatedCaches: true,
  clientsClaim: true,
  skipWaiting: true,
  // no navigation fallback; one runtime route, the product photos (above): anything else not precached goes to the network untouched
  runtimeCaching: [
    {
      urlPattern: ({ url, sameOrigin }) => sameOrigin && url.pathname.startsWith("/api/v1/media/"),
      handler: "CacheFirst",
      method: "GET",
      options: {
        cacheName: "product-images",
        expiration: { maxEntries: 400, maxAgeSeconds: 30 * 24 * 60 * 60, purgeOnQuotaError: true },
        cacheableResponse: { statuses: [200] },
      },
    },
  ],
  maximumFileSizeToCacheInBytes: 3 * 1024 * 1024,
});
for (const warning of warnings) console.warn(`build-sw: ${warning}`);
console.log(`build-sw: precached ${count} files (${(size / 1024).toFixed(0)} KiB) in out/sw.js`);
