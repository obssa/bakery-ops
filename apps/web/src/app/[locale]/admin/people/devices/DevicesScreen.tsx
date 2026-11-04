"use client";

import { useTranslations } from "next-intl";
import { useId, useState, type FormEvent, type ReactNode } from "react";
import { ACTIONS_CELL, CARD, EmptyRow, TableCard } from "@/components/admin/kit";
import { EnrolThisDevice } from "@/components/devices/EnrolThisDevice";
import { RequireAuth } from "@/components/RequireAuth";
import { Alert, Button, Chip, Field, Modal, PageHeader, Skeleton, Status, TextInput } from "@/components/ui";
import { useDevices, useEnrolDevice, useRenameDevice, useRevokeDevice, useThisDevice } from "@/lib/api/devices";
import { useIdempotencyKey, uuid } from "@/lib/api/idempotency";
import type { Device, NewDevice } from "@/lib/api/types";
import { stampText } from "@/lib/dates";
import { deviceId, shortDeviceId } from "@/lib/device";
import { useErrorText } from "@/lib/errors";
import { useDateFormat } from "@/lib/useDates";

// Admin > People > Devices (Phase 5 step 5.1b; ADR 0017): every computer and tablet of the branch with when it was last seen, this
// device's own state with "enrol this device" in place, rename, revoke with a confirmation, and the print bridges, whose token is shown
// once with a copy button. devices.manage (the admin).
//
// Step 6.4d (fresh-counter.md 4.4, the boards' cards and table): the title row with its Amharic twin; this device and the print bridges
// as two white cards side by side from 1024 px; the enrolled devices a table card under them (the state a chip with its dot: Active in
// brand teal, Revoked red; the row's Rename and Revoke at its end, Revoke the danger button, as its confirmation is).

export function DevicesScreen({ titleAm }: { titleAm?: string }) {
  const t = useTranslations("devices");
  return (
    <RequireAuth permission="devices.manage">
      <PageHeader title={t("title")} intro={t("intro")} twin={titleAm ? { text: titleAm, lang: "am" } : undefined} />
      <div className="flex flex-col gap-6">
        <div className="grid items-start gap-4 lg:grid-cols-2">
          <ThisDeviceCard />
          <BridgeCard />
        </div>
        <DevicesTable />
      </div>
    </RequireAuth>
  );
}

/** One of the page's white cards: a region named by its 17 / 600 title. */
function SideCard({ title, children }: { title: string; children: ReactNode }) {
  const id = useId();
  return (
    <section aria-labelledby={id} className={`${CARD} space-y-4 p-5`}>
      <h2 id={id} className="text-[17px] font-semibold text-neutral-900">
        {title}
      </h2>
      {children}
    </section>
  );
}

function ThisDeviceCard() {
  const t = useTranslations();
  const errorText = useErrorText();
  const here = useThisDevice();
  const [enrolled, setEnrolled] = useState<string | null>(null);

  return (
    <SideCard title={t("devices.this_device")}>
      {here.isPending ? (
        <div className="space-y-2" aria-hidden="true">
          <Skeleton className="h-6 w-2/3" />
          <Skeleton className="h-5 w-1/2" />
        </div>
      ) : here.isError ? (
        <Alert>{errorText.message(here.error)}</Alert>
      ) : (
        <div className="space-y-3">
          <div className="flex flex-wrap items-center gap-2">
            {here.data.enrolled || here.data.revoked ? (
              <Chip tone={here.data.enrolled ? "brand" : "red"} dot>
                {here.data.enrolled ? t("devices.active") : t("devices.revoked")}
              </Chip>
            ) : null}
            <p className="text-[15px] font-semibold text-neutral-900">
              {here.data.enrolled && here.data.device
                ? t("devices.this_enrolled", { name: here.data.device.name })
                : here.data.revoked
                  ? t("devices.this_revoked")
                  : t("devices.this_unknown")}
            </p>
          </div>
          <p className="text-[15px] text-neutral-700">{t("devices.this_code", { code: shortDeviceId(deviceId()) })}</p>
          <p className="text-[13px] text-neutral-600">{here.data.enforcement ? t("devices.enforcement_on") : t("devices.enforcement_off")}</p>
          <Status>{enrolled ? t("devices.enrolled_ok", { name: enrolled }) : null}</Status>
          {here.data.enrolled ? null : (
            <div className="border-t border-neutral-200 pt-4">
              <EnrolThisDevice renew={here.data.revoked} onEnrolled={(device) => setEnrolled(device.name)} />
            </div>
          )}
        </div>
      )}
    </SideCard>
  );
}

function DevicesTable() {
  const t = useTranslations();
  const errorText = useErrorText();
  const fmt = useDateFormat();
  const devices = useDevices();
  const mine = deviceId();
  const [renaming, setRenaming] = useState<Device | null>(null);
  const [revoking, setRevoking] = useState<Device | null>(null);
  const [done, setDone] = useState<string | null>(null);

  return (
    <>
      <TableCard caption={t("devices.list")} title={t("devices.list")} action={<Status>{done}</Status>} minWidth="min-w-[820px]">
        <thead>
          <tr>
            <th scope="col">{t("devices.col_name")}</th>
            <th scope="col">{t("devices.col_kind")}</th>
            <th scope="col">{t("devices.col_code")}</th>
            <th scope="col">{t("devices.col_last_seen")}</th>
            <th scope="col">{t("devices.col_status")}</th>
            <th scope="col">
              <span className="sr-only">{t("devices.col_actions")}</span>
            </th>
          </tr>
        </thead>
        <tbody>
          {devices.isPending ? (
            [0, 1, 2].map((i) => (
              <tr key={i} aria-hidden="true">
                <td colSpan={6}>
                  <Skeleton className="h-6" />
                </td>
              </tr>
            ))
          ) : devices.isError ? (
            <tr>
              <td colSpan={6} className="space-y-3 py-4">
                <Alert>{errorText.message(devices.error)}</Alert>
                <Button variant="secondary" size="md" onClick={() => void devices.refetch()}>
                  {t("common.retry")}
                </Button>
              </td>
            </tr>
          ) : devices.data.length === 0 ? (
            <EmptyRow span={6}>{t("devices.none")}</EmptyRow>
          ) : (
            devices.data.map((device) => (
              <tr key={device.id}>
                <td>
                  <span className="flex flex-wrap items-center gap-2">
                    <span className="font-semibold text-neutral-900">{device.name}</span>
                    {device.fingerprint === mine ? <Chip tone="blue">{t("devices.this_badge")}</Chip> : null}
                  </span>
                  <span className="block text-[13px] text-neutral-600">{t("devices.enrolled_at", { at: stampText(device.enrolled_at, fmt) })}</span>
                </td>
                <td className="text-neutral-700">{t(`devices.kinds.${device.kind}`)}</td>
                <td className="whitespace-nowrap font-mono text-sm tabular-nums text-neutral-700">{shortDeviceId(device.fingerprint)}</td>
                <td className="whitespace-nowrap tabular-nums text-neutral-700">{device.last_seen_at ? stampText(device.last_seen_at, fmt) : t("devices.never_seen")}</td>
                <td>
                  {device.revoked_at ? (
                    <Chip tone="red" dot>
                      {t("devices.revoked")}
                    </Chip>
                  ) : (
                    <Chip tone="brand" dot>
                      {t("devices.active")}
                    </Chip>
                  )}
                </td>
                <td className={ACTIONS_CELL}>
                  {device.revoked_at ? null : (
                    <span className="inline-flex gap-2">
                      <Button variant="secondary" size="md" compact onClick={() => setRenaming(device)}>
                        {t("devices.rename")}
                      </Button>
                      <Button variant="danger" size="md" compact onClick={() => setRevoking(device)}>
                        {t("devices.revoke")}
                      </Button>
                    </span>
                  )}
                </td>
              </tr>
            ))
          )}
        </tbody>
      </TableCard>
      {renaming ? (
        <RenameDialog
          device={renaming}
          onClose={() => setRenaming(null)}
          onDone={(name) => {
            setRenaming(null);
            setDone(t("devices.renamed", { name }));
          }}
        />
      ) : null}
      {revoking ? (
        <RevokeDialog
          device={revoking}
          onClose={() => setRevoking(null)}
          onDone={(name) => {
            setRevoking(null);
            setDone(t("devices.revoked_ok", { name }));
          }}
        />
      ) : null}
    </>
  );
}

function RenameDialog({ device, onClose, onDone }: { device: Device; onClose: () => void; onDone: (name: string) => void }) {
  const t = useTranslations("devices");
  const errorText = useErrorText();
  const rename = useRenameDevice();
  const idempotency = useIdempotencyKey();
  const [name, setName] = useState(device.name);

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const body = { name: name.trim() };
    rename.mutate(
      { id: device.id, body, idempotencyKey: idempotency.keyFor({ id: device.id, ...body }) },
      {
        onSuccess: (renamed) => {
          idempotency.done();
          onDone(renamed.name);
        },
      },
    );
  }

  return (
    <Modal label={t("rename_title", { name: device.name })} onClose={onClose}>
      <form onSubmit={onSubmit} className="space-y-4">
        <h2 className="text-lg font-semibold text-neutral-900">{t("rename_title", { name: device.name })}</h2>
        <Field label={t("field_name")} error={errorText.field(rename.error, "name")}>
          {(props) => <TextInput {...props} controlSize="md" value={name} onChange={(event) => setName(event.target.value)} required maxLength={120} autoComplete="off" />}
        </Field>
        {rename.isError ? <Alert>{errorText.message(rename.error)}</Alert> : null}
        <div className="flex flex-wrap justify-end gap-2">
          <Button type="button" variant="secondary" size="md" onClick={onClose}>
            {t("cancel")}
          </Button>
          <Button type="submit" size="md" disabled={rename.isPending || name.trim() === ""}>
            {t("save")}
          </Button>
        </div>
      </form>
    </Modal>
  );
}

function RevokeDialog({ device, onClose, onDone }: { device: Device; onClose: () => void; onDone: (name: string) => void }) {
  const t = useTranslations("devices");
  const errorText = useErrorText();
  const revoke = useRevokeDevice();
  const idempotency = useIdempotencyKey();

  return (
    <Modal label={t("revoke_title", { name: device.name })} onClose={onClose}>
      <h2 className="text-lg font-semibold text-neutral-900">{t("revoke_title", { name: device.name })}</h2>
      <p className="text-[15px] text-neutral-700">{t("revoke_body")}</p>
      {revoke.isError ? <Alert>{errorText.message(revoke.error)}</Alert> : null}
      <div className="flex flex-wrap justify-end gap-2">
        <Button variant="secondary" size="md" onClick={onClose}>
          {t("cancel")}
        </Button>
        <Button
          variant="danger"
          size="md"
          disabled={revoke.isPending}
          onClick={() =>
            revoke.mutate(
              { id: device.id, idempotencyKey: idempotency.keyFor({ revoke: device.id }) },
              {
                onSuccess: () => {
                  idempotency.done();
                  onDone(device.name);
                },
              },
            )
          }
        >
          {t("revoke_confirm")}
        </Button>
      </div>
    </Modal>
  );
}

function BridgeCard() {
  const t = useTranslations("devices");
  const errorText = useErrorText();
  const enrol = useEnrolDevice();
  const idempotency = useIdempotencyKey();
  const [name, setName] = useState("");
  const [draft, setDraft] = useState(() => ({ clientUuid: uuid(), fingerprint: `bridge-${uuid()}` }));
  const [shown, setShown] = useState<{ name: string; token: string } | null>(null);

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const body: NewDevice = { name: name.trim(), fingerprint: draft.fingerprint, kind: "bridge", client_uuid: draft.clientUuid };
    enrol.mutate(
      { body, idempotencyKey: idempotency.keyFor(body) },
      {
        onSuccess: (device) => {
          idempotency.done();
          setDraft({ clientUuid: uuid(), fingerprint: `bridge-${uuid()}` });
          setName("");
          if (device.token) setShown({ name: device.name, token: device.token });
        },
      },
    );
  }

  return (
    <SideCard title={t("bridge_title")}>
      <p className="text-[15px] text-neutral-700">{t("bridge_intro")}</p>
      {shown ? (
        <TokenOnce name={shown.name} token={shown.token} onDone={() => setShown(null)} />
      ) : (
        <form onSubmit={onSubmit} className="space-y-4">
          <Field label={t("bridge_name")} error={errorText.field(enrol.error, "name")}>
            {(props) => <TextInput {...props} controlSize="md" value={name} onChange={(event) => setName(event.target.value)} required maxLength={120} autoComplete="off" />}
          </Field>
          {enrol.isError ? <Alert>{errorText.message(enrol.error)}</Alert> : null}
          <Button type="submit" size="md" disabled={enrol.isPending || name.trim() === ""}>
            {t("add_bridge")}
          </Button>
        </form>
      )}
    </SideCard>
  );
}

/** A bridge's token, this once (the API keeps only its hash): a read-only field to select, a copy button, and Done to put it away. */
function TokenOnce({ name, token, onDone }: { name: string; token: string; onDone: () => void }) {
  const t = useTranslations("devices");
  const [copy, setCopy] = useState<"copied" | "failed" | null>(null);

  async function onCopy() {
    try {
      await navigator.clipboard.writeText(token);
      setCopy("copied");
    } catch {
      setCopy("failed");
    }
  }

  return (
    <div className="space-y-3 rounded-xl border border-amber-200 bg-amber-50 p-4">
      <p className="text-[15px] font-semibold text-amber-900">{t("token_once")}</p>
      <input
        readOnly
        value={token}
        aria-label={t("token_label", { name })}
        onFocus={(event) => event.currentTarget.select()}
        className="block min-h-11 w-full rounded-xl border border-neutral-300 bg-white px-3 font-mono text-sm text-neutral-900"
      />
      <div className="flex flex-wrap items-center gap-2">
        <Button type="button" size="md" onClick={() => void onCopy()}>
          {t("copy")}
        </Button>
        <Button type="button" variant="secondary" size="md" onClick={onDone}>
          {t("done")}
        </Button>
      </div>
      <Status>{copy === "copied" ? t("copied") : copy === "failed" ? t("copy_failed") : null}</Status>
    </div>
  );
}
