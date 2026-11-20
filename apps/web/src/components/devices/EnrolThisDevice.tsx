"use client";

import { useTranslations } from "next-intl";
import { useId, useRef, useState, type FormEvent } from "react";
import { Alert, Button, Field, Select, TextInput } from "@/components/ui";
import { useEnrolDevice } from "@/lib/api/devices";
import { useIdempotencyKey, uuid } from "@/lib/api/idempotency";
import type { DeviceKind, EnrolledDevice, NewDevice } from "@/lib/api/types";
import { adoptDeviceId, deviceId, newDeviceId } from "@/lib/device";
import { useErrorText } from "@/lib/errors";

const KINDS: readonly DeviceKind[] = ["tablet", "till", "kds"];

/**
 * "Enrol this device" (step 5.1b): the admin, signed in on the device itself, names it and enrols the id it sends (POST /devices). Shown
 * on the "not enrolled" screen and on the Devices screen. `renew` enrols a new id instead, for an id that cannot be enrolled here (revoked,
 * or taken by another branch; ADR 0017 decision 6); the device keeps the new id once it is enrolled, never before.
 */
export function EnrolThisDevice({ renew = false, onEnrolled }: { renew?: boolean; onEnrolled: (device: EnrolledDevice) => void }) {
  const t = useTranslations("devices");
  const errorText = useErrorText();
  const enrol = useEnrolDevice();
  const idempotency = useIdempotencyKey();
  const headingId = useId();
  const [clientUuid, setClientUuid] = useState(() => uuid());
  const [name, setName] = useState("");
  const [kind, setKind] = useState<DeviceKind>("tablet");
  // the new id `renew` enrols: made once and sent (as the fingerprint and as X-Device-Id) on every try, and kept as this device's id only
  // once the API enrolled it. A failed try leaves the device its old id: one enrolled in another branch stays enrolled there
  const proposed = useRef<string | null>(null);

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const fingerprint = renew ? (proposed.current ??= newDeviceId()) : deviceId();
    const body: NewDevice = { name: name.trim(), fingerprint, kind, client_uuid: clientUuid };
    enrol.mutate(
      { body, idempotencyKey: idempotency.keyFor(body), device: fingerprint },
      {
        onSuccess: (device) => {
          if (renew) {
            adoptDeviceId(fingerprint);
            proposed.current = null;
          }
          idempotency.done();
          setClientUuid(uuid());
          setName("");
          onEnrolled(device);
        },
        onError: idempotency.failed,
      },
    );
  }

  return (
    <form onSubmit={onSubmit} aria-labelledby={headingId} className="space-y-4">
      <h2 id={headingId} className="text-lg font-semibold text-neutral-900">
        {t("enrol_title")}
      </h2>
      <Field label={t("field_name")} hint={t("name_hint")} error={errorText.field(enrol.error, "name")}>
        {(props) => (
          <TextInput {...props} value={name} onChange={(event) => setName(event.target.value)} required maxLength={120} autoComplete="off" />
        )}
      </Field>
      <Field label={t("field_kind")}>
        {(props) => (
          <Select {...props} value={kind} onChange={(event) => setKind(event.target.value as DeviceKind)}>
            {KINDS.map((k) => (
              <option key={k} value={k}>
                {t(`kinds.${k}`)}
              </option>
            ))}
          </Select>
        )}
      </Field>
      {enrol.isError ? <Alert>{errorText.message(enrol.error)}</Alert> : null}
      <Button type="submit" disabled={enrol.isPending || name.trim() === ""}>
        {enrol.isPending ? t("enrolling") : t("enrol")}
      </Button>
    </form>
  );
}
