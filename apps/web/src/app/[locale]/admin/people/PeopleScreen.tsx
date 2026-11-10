"use client";

import { Smartphone } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useId, useState, type FormEvent, type ReactNode } from "react";
import { CARD, EmptyRow, FORM_GRID, TableCard } from "@/components/admin/kit";
import { Alert, Avatar, Button, Chip, Field, PageHeader, Select, Skeleton, Status, TextInput } from "@/components/ui";
import { usePatchStation, useStations } from "@/lib/api/catalog";
import { useCreateUser, usePatchUserOnCall, useUsers } from "@/lib/api/hooks";
import { useIdempotencyKey } from "@/lib/api/idempotency";
import { ROLES, type Locale, type NewUser, type Role, type Station, type User } from "@/lib/api/types";
import { useErrorText } from "@/lib/errors";
import { RequireAuth } from "@/components/RequireAuth";
import { buttonClass } from "@/components/styles";
import { Link } from "@/i18n/navigation";
import { can, useSession } from "@/lib/session";

// Admin > People (Phase 0 step 0.7, "People minimal"): the branch's users and a form to add one with a role and the
// language their screens open in. Roles, stations, short codes and devices get their own screens later; the devices screen (step 5.1b)
// is linked from here. The go-live settings (2026-11-02) add the On call switch to each person: the admin, the shift manager on duty and
// everyone switched On call hear the ops alerts (backups, the restore drill) and the failed receipt prints.
//
// Step 6.4d (fresh-counter.md 4.4, the CatalogList board's title row and table): the title row with its Amharic twin and "Devices" as its
// action; the people a table card (each name beside its initials, the role, the language, the status as a chip, the On call box); the new
// person form a white card in two columns under it; the stations' overseer rule a table card last. The saved lines stay polite live
// regions inside their cards.

const LOCALES: readonly Locale[] = ["en", "am"];

/** A checkbox of a table row (On call, the overseer rule): 20 px in ink, named by its aria-label. */
const ROW_CHECK = "size-5 cursor-pointer accent-ink-900 disabled:cursor-not-allowed";

export function PeopleScreen({ titleAm }: { titleAm?: string }) {
  const t = useTranslations("people");
  return (
    <RequireAuth permission="people.manage">
      <PageHeader title={t("title")} intro={t("intro")} twin={titleAm ? { text: titleAm, lang: "am" } : undefined} actions={<DevicesLink />} />
      <div className="flex flex-col gap-6">
        <PeopleTable />
        <NewPersonCard />
        <StationsTable />
      </div>
    </RequireAuth>
  );
}

/** The way to Admin > People > Devices, for whoever may manage devices (devices.manage, the admin): the title row's action. */
function DevicesLink() {
  const t = useTranslations("people");
  const me = useSession((s) => s.me);
  if (!can(me, "devices.manage")) return null;
  return (
    <Link href="/admin/people/devices" title={t("devices_desc")} className={buttonClass({ variant: "secondary", size: "md" })}>
      <Smartphone className="size-[18px]" strokeWidth={2} aria-hidden="true" />
      {t("devices_link")}
    </Link>
  );
}

function LoadingRows({ span, rows = 4 }: { span: number; rows?: number }) {
  return (
    <>
      {Array.from({ length: rows }, (_, i) => (
        <tr key={i} aria-hidden="true">
          <td colSpan={span}>
            <Skeleton className="h-6" />
          </td>
        </tr>
      ))}
    </>
  );
}

function PeopleTable() {
  const t = useTranslations();
  const errorText = useErrorText();
  const users = useUsers();
  const [saved, setSaved] = useState<string | null>(null);

  if (users.isError) {
    return (
      <section className={`${CARD} space-y-3 p-5`}>
        <Alert>{errorText.message(users.error)}</Alert>
        <Button variant="secondary" size="md" onClick={() => void users.refetch()}>
          {t("common.retry")}
        </Button>
      </section>
    );
  }
  return (
    <TableCard caption={t("people.list")} title={t("people.list")} intro={t("people.on_call_intro")} action={<Status>{saved}</Status>} minWidth="min-w-[760px]">
      <thead>
        <tr>
          <th scope="col">{t("people.col_name")}</th>
          <th scope="col">{t("people.col_phone")}</th>
          <th scope="col">{t("people.col_role")}</th>
          <th scope="col">{t("people.col_language")}</th>
          <th scope="col">{t("people.col_status")}</th>
          <th scope="col" className="text-center">
            {t("people.col_on_call")}
          </th>
        </tr>
      </thead>
      <tbody>
        {users.isPending ? (
          <LoadingRows span={6} />
        ) : users.data.length === 0 ? (
          <EmptyRow span={6}>{t("people.no_people")}</EmptyRow>
        ) : (
          users.data.map((user) => (
            <tr key={user.id}>
              <td>
                <span className="flex items-center gap-3">
                  <Avatar name={user.name} />
                  <span className="font-semibold text-neutral-900">{user.name}</span>
                </span>
              </td>
              <td className="whitespace-nowrap tabular-nums text-neutral-700">{user.phone}</td>
              <td className="text-neutral-700">{user.roles.map((r) => t(`people.roles.${r}`)).join(", ")}</td>
              <td className="text-neutral-700" lang={user.locale}>
                {t(`locale.${user.locale}`)}
              </td>
              <td>
                <Chip tone={user.is_active ? "positive" : "neutral"} dot>
                  {user.is_active ? t("people.active") : t("people.inactive")}
                </Chip>
              </td>
              <OnCallCell user={user} onSaved={setSaved} />
            </tr>
          ))
        )}
      </tbody>
    </TableCard>
  );
}

// The On call switch of one person (PATCH /users/{id} {on_call}, people.manage, audited as user.update). It moves at once and goes back
// if the server refuses, as the stations' overseer switch does.
function OnCallCell({ user, onSaved }: { user: User; onSaved: (message: string | null) => void }) {
  const t = useTranslations("people");
  const errorText = useErrorText();
  const patch = usePatchUserOnCall();
  const idempotency = useIdempotencyKey();
  const [shown, setShown] = useState<boolean | null>(null);
  const onCall = shown ?? user.on_call;

  function toggle(next: boolean) {
    const body = { on_call: next };
    setShown(next);
    onSaved(null);
    patch.mutate(
      { id: user.id, body, idempotencyKey: idempotency.keyFor({ id: user.id, ...body }) },
      {
        onSuccess: () => {
          idempotency.done();
          setShown(null);
          onSaved(next ? t("on_call_saved_on", { name: user.name }) : t("on_call_saved_off", { name: user.name }));
        },
        onError: () => setShown(null),
      },
    );
  }

  return (
    <td className="text-center">
      <input
        type="checkbox"
        className={ROW_CHECK}
        aria-label={t("on_call_label", { name: user.name })}
        checked={onCall}
        disabled={patch.isPending}
        onChange={(event) => toggle(event.target.checked)}
      />
      {patch.isError ? (
        <span role="alert" className="mt-1 block text-[13px] font-medium text-red-700">
          {errorText.message(patch.error)}
        </span>
      ) : null}
    </td>
  );
}

function NewPersonCard() {
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
    <FormCard title={t("people.new_person")}>
      <form onSubmit={onSubmit} className="space-y-5">
        <div className={FORM_GRID}>
          <Field label={t("people.field_name")} error={fieldError("name")}>
            {(props) => <TextInput {...props} controlSize="md" required maxLength={120} autoComplete="off" value={name} onChange={(e) => setName(e.target.value)} />}
          </Field>
          <Field label={t("people.field_phone")} error={fieldError("phone")}>
            {(props) => (
              <TextInput
                {...props}
                controlSize="md"
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
          <Field label={t("people.field_role")} error={fieldError("role")}>
            {(props) => (
              <Select {...props} controlSize="md" value={role} onChange={(e) => setRole(e.target.value as Role)}>
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
              <Select {...props} controlSize="md" value={locale} onChange={(e) => setLocale(e.target.value as Locale)}>
                {LOCALES.map((l) => (
                  <option key={l} value={l} lang={l}>
                    {t(`locale.${l}`)}
                  </option>
                ))}
              </Select>
            )}
          </Field>
          {/* the password last, its hint under it, so the two columns above stay level */}
          <Field label={t("people.field_password")} hint={t("people.password_hint")} error={fieldError("password")}>
            {(props) => (
              <TextInput
                {...props}
                controlSize="md"
                type="password"
                required
                minLength={8}
                autoComplete="new-password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
              />
            )}
          </Field>
        </div>
        {create.isError ? <Alert>{errorText.message(create.error)}</Alert> : null}
        <Status>{created ? t("people.person_created", { name: created }) : null}</Status>
        <Button type="submit" size="md" loading={create.isPending}>
          {t("people.add_person")}
        </Button>
      </form>
    </FormCard>
  );
}

/** A form on the board's white card: a region named by its 17 / 600 title. */
function FormCard({ title, children }: { title: string; children: ReactNode }) {
  const id = useId();
  return (
    <section aria-labelledby={id} className={`${CARD} p-5`}>
      <h2 id={id} className="mb-4 text-[17px] font-semibold text-neutral-900">
        {title}
      </h2>
      {children}
    </section>
  );
}

// The stations of the branch and the one switch they carry: whether a batch made there must be signed off by an overseer
// (PATCH /stations/{id}, people.manage). The switch moves at once and goes back if the server refuses.
function StationsTable() {
  const t = useTranslations("people");
  const tc = useTranslations("common");
  const errorText = useErrorText();
  const stations = useStations();
  const [saved, setSaved] = useState<string | null>(null);

  return (
    <TableCard caption={t("stations")} title={t("stations")} intro={t("stations_intro")} action={<Status>{saved}</Status>}>
      <thead>
        <tr>
          <th scope="col">{t("col_station")}</th>
          <th scope="col" className="text-center">
            {t("col_overseer")}
          </th>
          <th scope="col">{t("col_state")}</th>
        </tr>
      </thead>
      <tbody>
        {stations.isPending ? (
          <LoadingRows span={3} rows={3} />
        ) : stations.isError ? (
          <tr>
            <td colSpan={3} className="space-y-3 py-4">
              <Alert>{errorText.message(stations.error)}</Alert>
              <Button variant="secondary" size="md" onClick={() => void stations.refetch()}>
                {tc("retry")}
              </Button>
            </td>
          </tr>
        ) : (
          stations.data.map((station) => <StationRow key={station.id} station={station} onSaved={setSaved} />)
        )}
      </tbody>
    </TableCard>
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
    <tr>
      <td>
        <span className="block font-semibold text-neutral-900">{name}</span>
        <span className="block text-[13px] text-neutral-600" translate="no">
          {station.code}
        </span>
      </td>
      <td className="text-center">
        <input
          type="checkbox"
          className={ROW_CHECK}
          aria-label={t("require_overseer", { name })}
          checked={required}
          disabled={patch.isPending}
          onChange={(event) => toggle(event.target.checked)}
        />
        {patch.isError ? (
          <span role="alert" className="mt-1 block text-[13px] font-medium text-red-700">
            {errorText.message(patch.error)}
          </span>
        ) : null}
      </td>
      <td>
        <Chip tone={required ? "blue" : "neutral"}>{required ? t("overseer_required") : t("overseer_not_required")}</Chip>
      </td>
    </tr>
  );
}
