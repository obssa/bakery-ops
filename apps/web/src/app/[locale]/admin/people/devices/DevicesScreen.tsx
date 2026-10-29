"use client";

import { useTranslations } from "next-intl";
import { useState, type FormEvent } from "react";
import { EnrolThisDevice } from "@/components/devices/EnrolThisDevice";
import { RequireAuth } from "@/components/RequireAuth";
import { Alert, Badge, Button, Field, Modal, PageHeader, Panel, Status, TextInput } from "@/components/ui";
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

export function DevicesScreen() {
  const t = useTranslations("devices");
  return (
    <RequireAuth permission="devices.manage">
      <PageHeader title={t("title")} intro={t("intro")} />
      <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_26rem]">
        <div className="space-y-6">
          <ThisDevicePanel />
          <DevicesPanel />
        </div>
        <BridgePanel />
      </div>
    </RequireAuth>
  );
}

function ThisDevicePanel() {
  const t = useTranslations();
  const errorText = useErrorText();
  const here = useThisDevice();
  const [enrolled, setEnrolled] = useState<string | null>(null);

  return (
    <Panel title={t("devices.this_device")}>
      {here.isPending ? (
        <p className="text-base text-neutral-600">{t("common.loading")}</p>
      ) : here.isError ? (
        <Alert>{errorText.message(here.error)}</Alert>
      ) : (
        <div className="space-y-4">
          <p className="text-base font-medium text-neutral-900">
            {here.data.enrolled && here.data.device
              ? t("devices.this_enrolled", { name: here.data.device.name })
              : here.data.revoked
                ? t("devices.this_revoked")
                : t("devices.this_unknown")}
          </p>
          <p className="text-base text-neutral-700">{t("devices.this_code", { code: shortDeviceId(deviceId()) })}</p>
          <p className="text-sm text-neutral-600">{here.data.enforcement ? t("devices.enforcement_on") : t("devices.enforcement_off")}</p>
          <Status>{enrolled ? t("devices.enrolled_ok", { name: enrolled }) : null}</Status>
          {here.data.enrolled ? null : <EnrolThisDevice renew={here.data.revoked} onEnrolled={(device) => setEnrolled(device.name)} />}
        </div>
      )}
    </Panel>
  );
}

function DevicesPanel() {
  const t = useTranslations();
  const errorText = useErrorText();
  const fmt = useDateFormat();
  const devices = useDevices();
  const mine = deviceId();
  const [renaming, setRenaming] = useState<Device | null>(null);
  const [revoking, setRevoking] = useState<Device | null>(null);
  const [done, setDone] = useState<string | null>(null);

  return (
    <Panel title={t("devices.list")}>
      <Status>{done}</Status>
      {devices.isPending ? (
        <p className="py-6 text-base text-neutral-600">{t("common.loading")}</p>
      ) : devices.isError ? (
        <div className="space-y-3">
          <Alert>{errorText.message(devices.error)}</Alert>
          <Button variant="secondary" onClick={() => void devices.refetch()}>
            {t("common.retry")}
          </Button>
        </div>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full border-collapse text-left text-base">
            <caption className="sr-only">{t("devices.list")}</caption>
            <thead>
              <tr className="border-b border-neutral-200 text-sm text-neutral-600">
                <th scope="col" className="py-2 pr-4 font-medium">{t("devices.col_name")}</th>
                <th scope="col" className="py-2 pr-4 font-medium">{t("devices.col_kind")}</th>
                <th scope="col" className="py-2 pr-4 font-medium">{t("devices.col_code")}</th>
                <th scope="col" className="py-2 pr-4 font-medium">{t("devices.col_last_seen")}</th>
                <th scope="col" className="py-2 pr-4 font-medium">{t("devices.col_status")}</th>
                <th scope="col" className="py-2 font-medium">
                  <span className="sr-only">{t("devices.col_actions")}</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {devices.data.length === 0 ? (
                <tr>
                  <td colSpan={6} className="py-6 text-neutral-600">
                    {t("devices.none")}
                  </td>
                </tr>
              ) : (
                devices.data.map((device) => (
                  <tr key={device.id} className="border-b border-neutral-100 align-top last:border-0">
                    <td className="py-3 pr-4">
                      <span className="font-medium text-neutral-900">{device.name}</span>
                      {device.fingerprint === mine ? (
                        <Badge tone="blue" className="ml-2">
                          {t("devices.this_badge")}
                        </Badge>
                      ) : null}
                      <span className="block text-sm text-neutral-600">{t("devices.enrolled_at", { at: stampText(device.enrolled_at, fmt) })}</span>
                    </td>
                    <td className="py-3 pr-4">{t(`devices.kinds.${device.kind}`)}</td>
                    <td className="py-3 pr-4 font-mono text-sm">{shortDeviceId(device.fingerprint)}</td>
                    <td className="py-3 pr-4">{device.last_seen_at ? stampText(device.last_seen_at, fmt) : t("devices.never_seen")}</td>
                    <td className="py-3 pr-4">
                      {device.revoked_at ? (
                        <Badge tone="red">{t("devices.revoked")}</Badge>
                      ) : (
                        <Badge tone="green">{t("devices.active")}</Badge>
                      )}
                    </td>
                    <td className="py-3">
                      {device.revoked_at ? null : (
                        <div className="flex flex-wrap gap-2">
                          <Button variant="secondary" compact onClick={() => setRenaming(device)}>
                            {t("devices.rename")}
                          </Button>
                          <Button variant="secondary" compact onClick={() => setRevoking(device)}>
                            {t("devices.revoke")}
                          </Button>
                        </div>
                      )}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      )}
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
    </Panel>
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
          {(props) => <TextInput {...props} value={name} onChange={(event) => setName(event.target.value)} required maxLength={120} autoComplete="off" />}
        </Field>
        {rename.isError ? <Alert>{errorText.message(rename.error)}</Alert> : null}
        <div className="flex flex-wrap gap-2">
          <Button type="submit" disabled={rename.isPending || name.trim() === ""}>
            {t("save")}
          </Button>
          <Button type="button" variant="secondary" onClick={onClose}>
            {t("cancel")}
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
      <p className="text-base text-neutral-700">{t("revoke_body")}</p>
      {revoke.isError ? <Alert>{errorText.message(revoke.error)}</Alert> : null}
      <div className="flex flex-wrap gap-2">
        <Button
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
        <Button variant="secondary" onClick={onClose}>
          {t("cancel")}
        </Button>
      </div>
    </Modal>
  );
}

function BridgePanel() {
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
    <Panel title={t("bridge_title")}>
      <div className="space-y-4">
        <p className="text-base text-neutral-700">{t("bridge_intro")}</p>
        {shown ? (
          <TokenOnce name={shown.name} token={shown.token} onDone={() => setShown(null)} />
        ) : (
          <form onSubmit={onSubmit} className="space-y-4">
            <Field label={t("bridge_name")} error={errorText.field(enrol.error, "name")}>
              {(props) => <TextInput {...props} value={name} onChange={(event) => setName(event.target.value)} required maxLength={120} autoComplete="off" />}
            </Field>
            {enrol.isError ? <Alert>{errorText.message(enrol.error)}</Alert> : null}
            <Button type="submit" disabled={enrol.isPending || name.trim() === ""}>
              {t("add_bridge")}
            </Button>
          </form>
        )}
      </div>
    </Panel>
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
    <div className="space-y-3 rounded-md border border-amber-300 bg-amber-50 p-4">
      <p className="text-base font-medium text-amber-900">{t("token_once")}</p>
      <input
        readOnly
        value={token}
        aria-label={t("token_label", { name })}
        onFocus={(event) => event.currentTarget.select()}
        className="block min-h-12 w-full rounded-md border border-neutral-300 bg-white px-3 font-mono text-sm text-neutral-900"
      />
      <div className="flex flex-wrap items-center gap-2">
        <Button type="button" onClick={() => void onCopy()}>
          {t("copy")}
        </Button>
        <Button type="button" variant="secondary" onClick={onDone}>
          {t("done")}
        </Button>
      </div>
      <Status>{copy === "copied" ? t("copied") : copy === "failed" ? t("copy_failed") : null}</Status>
    </div>
  );
}
