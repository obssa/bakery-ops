"use client";

import { useLocale, useTranslations } from "next-intl";
import { useState, type FormEvent } from "react";
import { Alert, Button, Field, PageHeader, Panel, Select, Status, TextInput } from "@/components/ui";
import { usePatchStation, useStations } from "@/lib/api/catalog";
import { useCreateUser, useUsers } from "@/lib/api/hooks";
import { useIdempotencyKey } from "@/lib/api/idempotency";
import { ROLES, type Locale, type NewUser, type Role, type Station } from "@/lib/api/types";
import { useErrorText } from "@/lib/errors";
import { RequireAuth } from "@/components/RequireAuth";
import { focusRing } from "@/components/styles";
import { Link } from "@/i18n/navigation";
import { can, useSession } from "@/lib/session";

// Admin > People (Phase 0 step 0.7, "People minimal"): the branch's users and a form to add one with a role and the
// language their screens open in. Roles, stations, short codes and devices get their own screens later; the devices screen (step 5.1b)
// is linked from here.

const LOCALES: readonly Locale[] = ["en", "am"];

export function PeopleScreen() {
  const t = useTranslations("people");
  return (
    <RequireAuth permission="people.manage">
      <PageHeader title={t("title")} intro={t("intro")} />
      <DevicesLink />
      <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_26rem]">
        <div className="space-y-6">
          <PeoplePanel />
          <StationsPanel />
        </div>
        <NewPersonPanel />
      </div>
    </RequireAuth>
  );
}

/** The way to Admin > People > Devices, for whoever may manage devices (devices.manage, the admin). */
function DevicesLink() {
  const t = useTranslations("people");
  const me = useSession((s) => s.me);
  if (!can(me, "devices.manage")) return null;
  return (
    <p className="mb-6 flex flex-wrap items-center gap-3 text-base text-neutral-700">
      <Link
        href="/admin/people/devices"
        className={`inline-flex min-h-12 items-center rounded-md border border-neutral-300 bg-white px-5 font-medium text-neutral-900 hover:bg-neutral-100 ${focusRing}`}
      >
        {t("devices_link")}
      </Link>
      <span>{t("devices_desc")}</span>
    </p>
  );
}

function PeoplePanel() {
  const t = useTranslations();
  const errorText = useErrorText();
  const users = useUsers();

  return (
    <Panel title={t("people.list")}>
      {users.isPending ? (
        <p className="py-6 text-base text-neutral-600">{t("common.loading")}</p>
      ) : users.isError ? (
        <div className="space-y-3">
          <Alert>{errorText.message(users.error)}</Alert>
          <Button variant="secondary" onClick={() => void users.refetch()}>
            {t("common.retry")}
          </Button>
        </div>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full border-collapse text-left text-base">
            <caption className="sr-only">{t("people.list")}</caption>
            <thead>
              <tr className="border-b border-neutral-200 text-sm text-neutral-600">
                <th scope="col" className="py-2 pr-4 font-medium">{t("people.col_name")}</th>
                <th scope="col" className="py-2 pr-4 font-medium">{t("people.col_phone")}</th>
                <th scope="col" className="py-2 pr-4 font-medium">{t("people.col_role")}</th>
                <th scope="col" className="py-2 pr-4 font-medium">{t("people.col_language")}</th>
                <th scope="col" className="py-2 font-medium">{t("people.col_status")}</th>
              </tr>
            </thead>
            <tbody>
              {users.data.length === 0 ? (
                <tr>
                  <td colSpan={5} className="py-6 text-neutral-600">
                    {t("people.no_people")}
                  </td>
                </tr>
              ) : (
                users.data.map((user) => (
                  <tr key={user.id} className="border-b border-neutral-100 last:border-0">
                    <td className="py-3 pr-4 font-medium text-neutral-900">{user.name}</td>
                    <td className="py-3 pr-4 font-mono text-sm">{user.phone}</td>
                    <td className="py-3 pr-4">{user.roles.map((r) => t(`people.roles.${r}`)).join(", ")}</td>
                    <td className="py-3 pr-4" lang={user.locale}>
                      {t(`locale.${user.locale}`)}
                    </td>
                    <td className="py-3">{user.is_active ? t("people.active") : t("people.inactive")}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      )}
    </Panel>
  );
}

function NewPersonPanel() {
  const t = useTranslations();
  const errorText = useErrorText();
  const create = useCreateUser();
  const idempotency = useIdempotencyKey();
  const [name, setName] = useState("");
  const [phone, setPhone] = useState("");
  const [password, setPassword] = useState("");
  const [role, setRole] = useState<Role>("waiter");
  const [locale, setLocale] = useState<Locale>("am");
  const [created, setCreated] = useState<string | null>(null);

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const body: NewUser = { name: name.trim(), phone: phone.trim(), password, role, locale };
    setCreated(null);
    create.mutate(
      { body, idempotencyKey: idempotency.keyFor(body) },
      {
        onSuccess: (user) => {
          idempotency.done();
          setCreated(user.name);
          setName("");
          setPhone("");
          setPassword("");
        },
      },
    );
  }

  const fieldError = (field: string) => errorText.field(create.error, field);

  return (
    <Panel title={t("people.new_person")}>
      <form onSubmit={onSubmit} className="space-y-4">
        <Field label={t("people.field_name")} error={fieldError("name")}>
          {(props) => <TextInput {...props} required maxLength={120} autoComplete="off" value={name} onChange={(e) => setName(e.target.value)} />}
        </Field>
        <Field label={t("people.field_phone")} error={fieldError("phone")}>
          {(props) => (
            <TextInput
              {...props}
              type="tel"
              inputMode="tel"
              required
              pattern="\+?[0-9]{9,15}"
              autoComplete="off"
              value={phone}
              onChange={(e) => setPhone(e.target.value)}
            />
          )}
        </Field>
        <Field label={t("people.field_password")} hint={t("people.password_hint")} error={fieldError("password")}>
          {(props) => (
            <TextInput
              {...props}
              type="password"
              required
              minLength={8}
              autoComplete="new-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
            />
          )}
        </Field>
        <Field label={t("people.field_role")} error={fieldError("role")}>
          {(props) => (
            <Select {...props} value={role} onChange={(e) => setRole(e.target.value as Role)}>
              {ROLES.map((r) => (
                <option key={r} value={r}>
                  {t(`people.roles.${r}`)}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <Field label={t("people.field_language")} error={fieldError("locale")}>
          {(props) => (
            <Select {...props} value={locale} onChange={(e) => setLocale(e.target.value as Locale)}>
              {LOCALES.map((l) => (
                <option key={l} value={l} lang={l}>
                  {t(`locale.${l}`)}
                </option>
              ))}
            </Select>
          )}
        </Field>
        {create.isError ? <Alert>{errorText.message(create.error)}</Alert> : null}
        <Status>{created ? t("people.person_created", { name: created }) : null}</Status>
        <Button type="submit" disabled={create.isPending}>
          {create.isPending ? t("common.saving") : t("people.add_person")}
        </Button>
      </form>
    </Panel>
  );
}

// The stations of the branch and the one switch they carry: whether a batch made there must be signed off by an overseer
// (PATCH /stations/{id}, people.manage). The switch moves at once and goes back if the server refuses.
function StationsPanel() {
  const t = useTranslations("people");
  const tc = useTranslations("common");
  const errorText = useErrorText();
  const stations = useStations();
  const [saved, setSaved] = useState<string | null>(null);

  return (
    <Panel title={t("stations")}>
      <p className="mb-4 text-base text-neutral-600">{t("stations_intro")}</p>
      {stations.isPending ? (
        <p className="text-base text-neutral-600">{tc("loading")}</p>
      ) : stations.isError ? (
        <div className="space-y-3">
          <Alert>{errorText.message(stations.error)}</Alert>
          <Button variant="secondary" onClick={() => void stations.refetch()}>
            {tc("retry")}
          </Button>
        </div>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full border-collapse text-left text-base">
            <caption className="sr-only">{t("stations")}</caption>
            <thead>
              <tr className="border-b border-neutral-200 text-sm text-neutral-600">
                <th scope="col" className="py-2 pr-4 font-medium">{t("col_station")}</th>
                <th scope="col" className="py-2 pr-4 font-medium">{t("col_overseer")}</th>
                <th scope="col" className="py-2 font-medium">{t("col_state")}</th>
              </tr>
            </thead>
            <tbody>
              {stations.data.map((station) => (
                <StationRow key={station.id} station={station} onSaved={setSaved} />
              ))}
            </tbody>
          </table>
        </div>
      )}
      <div className="mt-4">
        <Status>{saved}</Status>
      </div>
    </Panel>
  );
}

function StationRow({ station, onSaved }: { station: Station; onSaved: (message: string | null) => void }) {
  const t = useTranslations("people");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const patch = usePatchStation();
  const idempotency = useIdempotencyKey();
  const name = locale === "am" ? station.name_am : station.name_en;
  const [shown, setShown] = useState<boolean | null>(null);
  const required = shown ?? station.requires_overseer;

  function toggle(next: boolean) {
    const body = { requires_overseer: next };
    setShown(next);
    onSaved(null);
    patch.mutate(
      { id: station.id, body, idempotencyKey: idempotency.keyFor({ id: station.id, ...body }) },
      {
        onSuccess: () => {
          idempotency.done();
          setShown(null);
          onSaved(t("station_saved", { name }));
        },
        onError: () => setShown(null),
      },
    );
  }

  return (
    <tr className="border-b border-neutral-100 align-top last:border-0">
      <td className="py-3 pr-4">
        <span className="block font-medium text-neutral-900">{name}</span>
        <span className="block font-mono text-sm text-neutral-500">{station.code}</span>
      </td>
      <td className="py-3 pr-4">
        <input
          type="checkbox"
          className="size-6"
          aria-label={t("require_overseer", { name })}
          checked={required}
          disabled={patch.isPending}
          onChange={(event) => toggle(event.target.checked)}
        />
        {patch.isError ? <span role="alert" className="mt-1 block text-sm font-medium text-red-700">{errorText.message(patch.error)}</span> : null}
      </td>
      <td className="py-3">{required ? t("overseer_required") : t("overseer_not_required")}</td>
    </tr>
  );
}
