import { getTranslations } from "next-intl/server";
import { focusRing } from "@/components/styles";
import { PermittedCard } from "@/components/PermittedCard";
import { StationsPanel } from "@/components/shifts/StationsPanel";
import { PageHeader } from "@/components/ui";
import { Link } from "@/i18n/navigation";
import { enterLocale, titledBy, type LocaleProps } from "@/lib/page";

export const generateMetadata = titledBy("admin", "title");

const CARD = `flex min-h-28 flex-col gap-1 rounded-lg border border-neutral-200 bg-white p-5 hover:border-neutral-400 ${focusRing}`;

const CARDS = [
  { href: "/admin/catalog", title: "catalog", body: "catalog_desc" },
  { href: "/admin/people", title: "people", body: "people_desc" },
  { href: "/admin/orders", title: "orders", body: "orders_desc" },
  { href: "/admin/inventory", title: "inventory", body: "inventory_desc" },
  { href: "/admin/planner", title: "planner", body: "planner_desc" },
  { href: "/admin/shifts", title: "shifts", body: "shifts_desc" },
  { href: "/admin/payments", title: "payments", body: "payments_desc" },
  { href: "/admin/analytics", title: "analytics", body: "analytics_desc" },
] as const;

export default async function AdminHome(props: LocaleProps) {
  await enterLocale(props);
  const t = await getTranslations("admin");
  return (
    <>
      <PageHeader title={t("title")} intro={t("intro")} />
      <ul className="grid gap-4 sm:grid-cols-2">
        {CARDS.map(({ href, title, body }) => (
          <li key={href}>
            <Link href={href} className={CARD}>
              <span className="text-lg font-semibold text-neutral-900">{t(title)}</span>
              <span className="text-base text-neutral-600">{t(body)}</span>
            </Link>
          </li>
        ))}
        {/* the audit log is shown to the holders of audit.view only (step 4.5f) */}
        <PermittedCard permission="audit.view" href="/admin/audit" title={t("audit")} body={t("audit_desc")} className={CARD} />
      </ul>
      <StationsPanel />
    </>
  );
}
