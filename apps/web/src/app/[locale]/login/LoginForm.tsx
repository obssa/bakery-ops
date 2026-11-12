"use client";

import { useTranslations } from "next-intl";
import { useState, type FormEvent } from "react";
import { Alert, Button, Field, TextInput } from "@/components/ui";
import { useRouter } from "@/i18n/navigation";
import { canOpen, homeOf } from "@/lib/access";
import { request } from "@/lib/api/client";
import { uuid } from "@/lib/api/idempotency";
import type { LoginResponse } from "@/lib/api/types";
import { useErrorText } from "@/lib/errors";
import { useSession } from "@/lib/session";

/** Only a path inside the shell (no scheme, no //host) may be a place to come back to after signing in. */
function safeNext(raw: string | null): string | null {
  if (!raw || !raw.startsWith("/") || raw.startsWith("//") || raw.startsWith("/login")) return null;
  return raw;
}

/**
 * What a sign-in does once the API answers: keep the session, then go back where the person came from when their role opens that page,
 * else to their own screen (ADR 0027, lib/access.ts homeOf: the console for the admin and the manager, the till for a cashier, the pad
 * for a waiter, the kitchen or bar display where a chef or barista works, the production board for the bakers), in the person's own
 * language. The form and the demo buttons (DemoLogins) share it.
 */
export function useFinishSignIn(): (login: LoginResponse) => Promise<void> {
  const router = useRouter();
  const signIn = useSession((s) => s.signIn);
  return async (login) => {
    const me = await signIn(login);
    const next = safeNext(new URLSearchParams(window.location.search).get("next"));
    // the user's own language from now on (GET /me and the login answer carry it)
    router.replace(next && canOpen(me, next) ? next : homeOf(me), { locale: login.locale });
  };
}

export function LoginForm() {
  const t = useTranslations();
  const errorText = useErrorText();
  const finish = useFinishSignIn();
  const expired = useSession((s) => s.expired);
  const [phone, setPhone] = useState("");
  const [password, setPassword] = useState("");
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<unknown>(null);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setPending(true);
    setError(null);
    try {
      const login = await request<LoginResponse>("/login", {
        method: "POST",
        body: { phone: phone.trim(), password },
        idempotencyKey: uuid(),
      });
      await finish(login);
    } catch (e) {
      setError(e);
      setPending(false);
    }
  }

  // step 6.2b (fresh-counter.md 3.1): 52 px fields and the 64 px button; a refusal shows in the form, a field's own error under the field
  return (
    <form onSubmit={onSubmit} className="space-y-5">
      {expired && !error ? <Alert>{t("errors.session_expired")}</Alert> : null}
      {error ? <Alert>{errorText.message(error)}</Alert> : null}
      <Field label={t("login.phone")} error={errorText.field(error, "phone")}>
        {(props) => (
          <TextInput
            {...props}
            controlSize="lg"
            type="tel"
            inputMode="tel"
            autoComplete="username"
            required
            value={phone}
            onChange={(e) => setPhone(e.target.value)}
          />
        )}
      </Field>
      <Field label={t("login.password")} error={errorText.field(error, "password")}>
        {(props) => (
          <TextInput
            {...props}
            controlSize="lg"
            type="password"
            autoComplete="current-password"
            required
            value={password}
            onChange={(e) => setPassword(e.target.value)}
          />
        )}
      </Field>
      <Button type="submit" size="xl" className="w-full" disabled={pending}>
        {pending ? t("login.submitting") : t("login.submit")}
      </Button>
    </form>
  );
}
