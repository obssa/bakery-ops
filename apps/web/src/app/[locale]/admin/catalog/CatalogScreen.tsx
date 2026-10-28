"use client";

import { useLocale, useTranslations } from "next-intl";
import { useSearchParams } from "next/navigation";
import { useState, type FormEvent } from "react";
import { focusRing } from "@/components/styles";
import { Alert, Button, Field, PageHeader, Panel, Select, Status, TextInput } from "@/components/ui";
import { ItemPage } from "@/components/catalog/ItemPage";
import { RefundToggles, useRefundSettingsText } from "@/components/catalog/RefundSettings";
import { Link } from "@/i18n/navigation";
import { useCategories, useCreateCategory, useCreateItem, useItems, useUnits } from "@/lib/api/hooks";
import { useIdempotencyKey } from "@/lib/api/idempotency";
import { FULFILMENTS, type Category, type Fulfilment, type ItemKind, type Locale, type NewCategory, type NewItem } from "@/lib/api/types";
import { useErrorText } from "@/lib/errors";
import { refundSettingsBody } from "@/lib/refund";
import { can, useSession } from "@/lib/session";

// Admin > Catalog (Phase 0 step 0.7): both inventories in one list (rule 2), filtered by kind through the API's
// ?kind=, and the forms to add an item of either kind and a category. Names are always entered in both languages
// (rule 9); the list shows the page's language first and the other one under it.

const KINDS: readonly ItemKind[] = ["ingredient", "product"];

function named(locale: Locale, row: { name_en: string; name_am: string }): { primary: string; secondary: string } {
  return locale === "am" ? { primary: row.name_am, secondary: row.name_en } : { primary: row.name_en, secondary: row.name_am };
}

function kindFrom(raw: string | null): ItemKind | null {
  return raw === "ingredient" || raw === "product" ? raw : null;
}

export function CatalogScreen() {
  const t = useTranslations("catalog");
  const me = useSession((s) => s.me);
  const manage = can(me, "catalog.manage");
  const params = useSearchParams();
  const kind = kindFrom(params.get("kind"));
  // one product's page (recipe versions, cost and margin) is the same route with ?item=<id>: a static export has no
  // dynamic segment for an id the build does not know
  const itemId = Number(params.get("item"));
  if (Number.isInteger(itemId) && itemId > 0) return <ItemPage id={itemId} />;

  return (
    <>
      <PageHeader title={t("title")} intro={t("intro")} />
      <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_26rem]">
        <ItemsPanel kind={kind} />
        <div className="space-y-6">
          {manage ? <NewItemPanel /> : null}
          <CategoriesPanel manage={manage} />
        </div>
      </div>
    </>
  );
}

function KindFilter({ kind }: { kind: ItemKind | null }) {
  const t = useTranslations("catalog");
  const options: { value: ItemKind | null; label: string }[] = [
    { value: null, label: t("filter_all") },
    { value: "ingredient", label: t("filter_ingredient") },
    { value: "product", label: t("filter_product") },
  ];
  return (
    <nav aria-label={t("filter")} className="mb-4 flex flex-wrap gap-2">
      {options.map(({ value, label }) => {
        const current = value === kind;
        return (
          <Link
            key={value ?? "all"}
            href={value ? { pathname: "/admin/catalog", query: { kind: value } } : "/admin/catalog"}
            aria-current={current ? "page" : undefined}
            className={`inline-flex min-h-12 items-center rounded-full border px-5 text-base ${
              current ? "border-neutral-900 bg-neutral-900 text-white" : "border-neutral-300 bg-white text-neutral-800 hover:bg-neutral-100"
            } ${focusRing}`}
          >
            {label}
          </Link>
        );
      })}
    </nav>
  );
}

function ItemsPanel({ kind }: { kind: ItemKind | null }) {
  const t = useTranslations("catalog");
  const tc = useTranslations("common");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const items = useItems(kind);
  const categories = useCategories();
  const refundText = useRefundSettingsText();
  const categoryName = (id: number | null): string => {
    const category = id === null ? undefined : categories.data?.find((c) => c.id === id);
    return category ? named(locale, category).primary : "—";
  };

  return (
    <Panel title={t("items")}>
      <KindFilter kind={kind} />
      {items.isPending ? (
        <p className="py-6 text-base text-neutral-600">{tc("loading")}</p>
      ) : items.isError ? (
        <div className="space-y-3">
          <Alert>{errorText.message(items.error)}</Alert>
          <Button variant="secondary" onClick={() => void items.refetch()}>
            {tc("retry")}
          </Button>
        </div>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full border-collapse text-left text-base">
            <caption className="sr-only">{t("items")}</caption>
            <thead>
              <tr className="border-b border-neutral-200 text-sm text-neutral-600">
                <th scope="col" className="py-2 pr-4 font-medium">{t("col_name")}</th>
                <th scope="col" className="py-2 pr-4 font-medium">{t("col_kind")}</th>
                <th scope="col" className="py-2 pr-4 font-medium">{t("col_fulfilment")}</th>
                <th scope="col" className="py-2 pr-4 font-medium">{t("col_refunds")}</th>
                <th scope="col" className="py-2 pr-4 font-medium">{t("col_category")}</th>
                <th scope="col" className="py-2 pr-4 font-medium">{t("col_unit")}</th>
                <th scope="col" className="py-2 font-medium">{t("col_sku")}</th>
              </tr>
            </thead>
            <tbody>
              {items.data.length === 0 ? (
                <tr>
                  <td colSpan={7} className="py-6 text-neutral-600">
                    {t("no_items")}
                  </td>
                </tr>
              ) : (
                items.data.map((item) => {
                  const name = named(locale, item);
                  return (
                    <tr key={item.id} className="border-b border-neutral-100 align-top last:border-0">
                      <td className="py-3 pr-4">
                        {item.kind === "product" ? (
                          <Link
                            href={{ pathname: "/admin/catalog", query: { item: item.id } }}
                            className={`block rounded-md font-medium text-blue-800 underline ${focusRing}`}
                          >
                            {name.primary}
                          </Link>
                        ) : (
                          <span className="block font-medium text-neutral-900">{name.primary}</span>
                        )}
                        <span className="block text-sm text-neutral-500" lang={locale === "am" ? "en" : "am"}>
                          {name.secondary}
                        </span>
                      </td>
                      <td className="py-3 pr-4">{t(`kind_${item.kind}`)}</td>
                      <td className="py-3 pr-4">{t(`fulfilment_${item.fulfilment}`)}</td>
                      <td className="py-3 pr-4 text-sm">{refundText(item)}</td>
                      <td className="py-3 pr-4">{categoryName(item.category_id)}</td>
                      <td className="py-3 pr-4">{item.unit_code}</td>
                      <td className="py-3 font-mono text-sm">{item.sku ?? ""}</td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>
      )}
    </Panel>
  );
}

function NewItemPanel() {
  const t = useTranslations("catalog");
  const tc = useTranslations("common");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const units = useUnits();
  const categories = useCategories();
  const create = useCreateItem();
  const idempotency = useIdempotencyKey();

  const [kind, setKind] = useState<ItemKind>("ingredient");
  const [fulfilment, setFulfilment] = useState<Fulfilment>("to_order");
  const [nameEn, setNameEn] = useState("");
  const [nameAm, setNameAm] = useState("");
  const [categoryId, setCategoryId] = useState("");
  const [unitId, setUnitId] = useState("");
  const [sku, setSku] = useState("");
  // the refund settings (step 4.5g): untouched, the API gives the natural values (a product refundable, back into stock when it can)
  const [refundable, setRefundable] = useState<boolean | null>(null);
  const [restock, setRestock] = useState<boolean | null>(null);
  const [created, setCreated] = useState<string | null>(null);
  const settingsFulfilment: Fulfilment = kind === "product" ? fulfilment : "bought_in";

  const ofKind: Category[] = (categories.data ?? []).filter((c) => c.kind === kind);

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const body: NewItem = {
      kind,
      ...(kind === "product" ? { fulfilment } : {}),
      name_en: nameEn.trim(),
      name_am: nameAm.trim(),
      category_id: categoryId ? Number(categoryId) : null,
      unit_id: Number(unitId),
      sku: sku.trim() || null,
      ...(refundable !== null || restock !== null ? refundSettingsBody(kind, settingsFulfilment, refundable ?? kind === "product", restock ?? true) : {}),
    };
    setCreated(null);
    create.mutate(
      { body, idempotencyKey: idempotency.keyFor(body) },
      {
        onSuccess: (item) => {
          idempotency.done();
          setCreated(named(locale, item).primary);
          setNameEn("");
          setNameAm("");
          setSku("");
        },
      },
    );
  }

  const fieldError = (field: string) => errorText.field(create.error, field);

  return (
    <Panel title={t("new_item")}>
      <form onSubmit={onSubmit} aria-label={t("new_item")} className="space-y-4">
        <Field label={t("field_kind")}>
          {(props) => (
            <Select
              {...props}
              value={kind}
              onChange={(e) => {
                setKind(e.target.value as ItemKind);
                setCategoryId("");
              }}
            >
              {KINDS.map((k) => (
                <option key={k} value={k}>
                  {t(`kind_${k}`)}
                </option>
              ))}
            </Select>
          )}
        </Field>
        {kind === "product" ? (
          <Field label={t("field_fulfilment")} error={fieldError("fulfilment")}>
            {(props) => (
              <Select {...props} value={fulfilment} onChange={(e) => setFulfilment(e.target.value as Fulfilment)}>
                {FULFILMENTS.map((f) => (
                  <option key={f} value={f}>
                    {t(`fulfilment_${f}`)}
                  </option>
                ))}
              </Select>
            )}
          </Field>
        ) : null}
        <Field label={t("field_name_en")} error={fieldError("name_en")}>
          {(props) => <TextInput {...props} lang="en" required maxLength={160} value={nameEn} onChange={(e) => setNameEn(e.target.value)} />}
        </Field>
        <Field label={t("field_name_am")} error={fieldError("name_am")}>
          {(props) => <TextInput {...props} lang="am" required maxLength={160} value={nameAm} onChange={(e) => setNameAm(e.target.value)} />}
        </Field>
        <Field label={t("field_category")} optionalLabel={t("optional")} error={fieldError("category_id")}>
          {(props) => (
            <Select {...props} value={categoryId} onChange={(e) => setCategoryId(e.target.value)}>
              <option value="">{t("no_category")}</option>
              {ofKind.map((c) => (
                <option key={c.id} value={c.id}>
                  {named(locale, c).primary}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <Field label={t("field_unit")} error={fieldError("unit_id")}>
          {(props) => (
            <Select {...props} required value={unitId} onChange={(e) => setUnitId(e.target.value)}>
              <option value="" disabled>
                {units.isPending ? tc("loading") : t("choose_unit")}
              </option>
              {(units.data ?? []).map((u) => (
                <option key={u.id} value={u.id}>
                  {`${named(locale, u).primary} (${u.code})`}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <Field label={t("field_sku")} optionalLabel={t("optional")} error={fieldError("sku")}>
          {(props) => <TextInput {...props} maxLength={64} autoComplete="off" value={sku} onChange={(e) => setSku(e.target.value)} />}
        </Field>
        <RefundToggles
          kind={kind}
          fulfilment={settingsFulfilment}
          refundable={refundable ?? kind === "product"}
          restock={restock ?? true}
          onRefundable={setRefundable}
          onRestock={setRestock}
        />
        {create.isError ? <Alert>{errorText.message(create.error)}</Alert> : null}
        <Status>{created ? t("item_created", { name: created }) : null}</Status>
        <Button type="submit" disabled={create.isPending}>
          {create.isPending ? tc("saving") : t("create_item")}
        </Button>
      </form>
    </Panel>
  );
}

function CategoriesPanel({ manage }: { manage: boolean }) {
  const t = useTranslations("catalog");
  const tc = useTranslations("common");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const categories = useCategories();
  const create = useCreateCategory();
  const idempotency = useIdempotencyKey();

  const [kind, setKind] = useState<ItemKind>("ingredient");
  const [nameEn, setNameEn] = useState("");
  const [nameAm, setNameAm] = useState("");
  const [created, setCreated] = useState<string | null>(null);

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const body: NewCategory = { kind, name_en: nameEn.trim(), name_am: nameAm.trim() };
    setCreated(null);
    create.mutate(
      { body, idempotencyKey: idempotency.keyFor(body) },
      {
        onSuccess: (category) => {
          idempotency.done();
          setCreated(named(locale, category).primary);
          setNameEn("");
          setNameAm("");
        },
      },
    );
  }

  return (
    <Panel title={t("categories")}>
      {categories.isPending ? (
        <p className="text-base text-neutral-600">{tc("loading")}</p>
      ) : categories.isError ? (
        <Alert>{errorText.message(categories.error)}</Alert>
      ) : categories.data.length === 0 ? (
        <p className="text-base text-neutral-600">{t("no_categories")}</p>
      ) : (
        <ul className="mb-5 divide-y divide-neutral-100">
          {categories.data.map((c) => {
            const name = named(locale, c);
            return (
              <li key={c.id} className="flex items-start justify-between gap-3 py-2">
                <span>
                  <span className="block text-base text-neutral-900">{name.primary}</span>
                  <span className="block text-sm text-neutral-500" lang={locale === "am" ? "en" : "am"}>
                    {name.secondary}
                  </span>
                </span>
                <span className="shrink-0 rounded-full bg-neutral-100 px-3 py-1 text-sm text-neutral-700">{t(`kind_${c.kind}`)}</span>
              </li>
            );
          })}
        </ul>
      )}
      {manage ? (
        <form onSubmit={onSubmit} className="space-y-4 border-t border-neutral-200 pt-4">
          <Field label={t("field_kind")}>
            {(props) => (
              <Select {...props} value={kind} onChange={(e) => setKind(e.target.value as ItemKind)}>
                {KINDS.map((k) => (
                  <option key={k} value={k}>
                    {t(`kind_${k}`)}
                  </option>
                ))}
              </Select>
            )}
          </Field>
          <Field label={t("field_name_en")} error={errorText.field(create.error, "name_en")}>
            {(props) => <TextInput {...props} lang="en" required maxLength={120} value={nameEn} onChange={(e) => setNameEn(e.target.value)} />}
          </Field>
          <Field label={t("field_name_am")} error={errorText.field(create.error, "name_am")}>
            {(props) => <TextInput {...props} lang="am" required maxLength={120} value={nameAm} onChange={(e) => setNameAm(e.target.value)} />}
          </Field>
          {create.isError ? <Alert>{errorText.message(create.error)}</Alert> : null}
          <Status>{created ? t("category_created", { name: created }) : null}</Status>
          <Button type="submit" variant="secondary" disabled={create.isPending}>
            {create.isPending ? tc("saving") : t("add_category")}
          </Button>
        </form>
      ) : null}
    </Panel>
  );
}
