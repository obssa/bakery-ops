// Generates the shell cache (out/sw.js) after a static export (NEXT_OUTPUT=export). It precaches the shell of the
// export (HTML, JS, CSS, fonts) so a tablet that loses Wi-Fi still opens the app, and never touches /api/: there is no
// runtime caching at all, and the worker only answers URLs of its own precache list. Served from /app/sw.js, so its
// scope is /app/. Without out/ (a plain `next build` for `next start`) there is nothing to do.
import { existsSync } from "node:fs";
import { generateSW } from "workbox-build";

if (process.env.NEXT_OUTPUT !== "export" || !existsSync("out/index.html")) {
  console.log("build-sw: not a static export build, skipping the shell cache");
  process.exit(0);
}

const { count, size, warnings } = await generateSW({
  globDirectory: "out",
  swDest: "out/sw.js",
  globPatterns: ["**/*.{html,js,css,woff2,woff,svg,ico,png,webmanifest}"],
  globIgnores: ["sw.js", "workbox-*.js", "**/*.map"],
  // /app/en/admin/catalog/?kind=product is the same page as /app/en/admin/catalog/
  ignoreURLParametersMatching: [/.*/],
  directoryIndex: "index.html",
  cleanupOutdatedCaches: true,
  clientsClaim: true,
  skipWaiting: true,
  // no navigation fallback and no runtime routes: anything not precached goes to the network untouched
  runtimeCaching: [],
  maximumFileSizeToCacheInBytes: 3 * 1024 * 1024,
});
for (const warning of warnings) console.warn(`build-sw: ${warning}`);
console.log(`build-sw: precached ${count} files (${(size / 1024).toFixed(0)} KiB) in out/sw.js`);
