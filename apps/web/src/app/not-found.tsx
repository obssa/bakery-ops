import "@fontsource-variable/noto-sans-ethiopic/wght.css";
import "./globals.css";
import Link from "next/link";
import am from "../../messages/am.json";
import en from "../../messages/en.json";

// The bare 404 (out/404.html) for a URL outside both languages. Pages inside a language have their own
// /app/<locale>/not-found/ page, which LiteSpeed serves for unknown paths under that language (public/.htaccess).
export default function NotFound() {
  return (
    <html lang="en">
      <body className="min-h-screen bg-neutral-50 text-neutral-900 antialiased">
        <title>{en.not_found.title}</title>
        <main className="mx-auto flex max-w-xl flex-col gap-4 px-4 py-16">
          <h1 className="text-2xl font-semibold">
            {en.not_found.title} <span lang="am">· {am.not_found.title}</span>
          </h1>
          <p className="text-base text-neutral-600">
            {en.not_found.body} <span lang="am">{am.not_found.body}</span>
          </p>
          <ul className="flex gap-3">
            <li>
              <Link href="/en/" lang="en" className="inline-flex min-h-12 items-center rounded-md border border-neutral-300 bg-white px-5">
                {en.locale.en}
              </Link>
            </li>
            <li>
              <Link href="/am/" lang="am" className="inline-flex min-h-12 items-center rounded-md border border-neutral-300 bg-white px-5">
                {am.locale.am}
              </Link>
            </li>
          </ul>
        </main>
      </body>
    </html>
  );
}
