"use client";

import { useTranslations } from "next-intl";
import { useState, type FormEvent } from "react";
import { uuid } from "@/lib/api/idempotency";
import { voidOrder } from "@/lib/api/till";
import type { Order } from "@/lib/api/types";
import { useErrorText } from "@/lib/errors";
import { useOrderNo } from "@/lib/orderNo";
import { Alert, Button, Field, Modal, TextInput } from "../ui";

// Voiding an unpaid order that was sent: a reason, and the PIN of a manager or admin (their short code) typed on this
// screen. The API checks the PIN and answers with its own bilingual message when it is refused (403) or locked (423);
// the dialog shows that message and keeps the reason. Used by the till queue and by the admin Orders board.

const PIN = /^\d{4,6}$/;

export function VoidDialog({ order, onClose, onVoided }: { order: Order; onClose: () => void; onVoided: () => void }) {
  const t = useTranslations("till");
  const no = useOrderNo(order);
  const errorText = useErrorText();
  const [reason, setReason] = useState("");
  const [pin, setPin] = useState("");
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<unknown>(null);
  // one key per attempt: a retry of the same request (a double tap) is the same write
  const [attempt, setAttempt] = useState(() => uuid());

  const valid = reason.trim() !== "" && PIN.test(pin);

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (!valid || pending) return;
    setPending(true);
    setError(null);
    try {
      await voidOrder(order.id, { reason: reason.trim(), pin }, attempt);
      onVoided();
      onClose();
    } catch (failure) {
      setError(failure);
      setPin("");
      setAttempt(uuid());
      setPending(false);
    }
  }

  return (
    <Modal label={t("void_title", { no: no })} onClose={onClose}>
      <form onSubmit={submit} className="space-y-4">
        <h2 className="text-lg font-semibold text-neutral-900">{t("void_title", { no: no })}</h2>
        <p className="text-base text-neutral-700">{t("void_intro")}</p>
        {error ? <Alert>{errorText.message(error)}</Alert> : null}
        <Field label={t("void_reason")}>
          {(props) => <TextInput {...props} value={reason} maxLength={255} autoFocus onChange={(event) => setReason(event.target.value)} />}
        </Field>
        <Field label={t("void_pin")} hint={t("void_pin_hint")}>
          {(props) => (
            <TextInput
              {...props}
              type="password"
              inputMode="numeric"
              autoComplete="off"
              maxLength={6}
              value={pin}
              onChange={(event) => setPin(event.target.value.replace(/\D/g, ""))}
            />
          )}
        </Field>
        <div className="flex flex-wrap gap-2">
          <Button type="submit" disabled={!valid || pending}>
            {pending ? t("voiding") : t("void_submit")}
          </Button>
          <Button type="button" variant="secondary" onClick={onClose}>
            {t("void_back")}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
