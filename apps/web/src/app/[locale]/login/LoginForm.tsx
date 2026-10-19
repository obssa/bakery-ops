"use client";

import { useTranslations } from "next-intl";
import { useState, type FormEvent } from "react";
import { Alert, Button, Field, TextInput } from "@/components/ui";
import { useRouter } from "@/i18n/navigation";
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

export function LoginForm() {
  const t = useTranslations();
  const router = useRouter();
  const errorText = useErrorText();
  const signIn = useSession((s) => s.signIn);
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
      signIn(login);
      const admin = login.permissions.includes("catalog.manage") || login.permissions.includes("people.manage");
      const fallback = admin ? "/admin" : "/";
      const next = safeNext(new URLSearchParams(window.location.search).get("next")) ?? fallback;
      // the user's own language from now on (GET /me and the login answer carry it)
      router.replace(next, { locale: login.locale });
    } catch (e) {
      setError(e);
      setPending(false);
    }
  }

  return (
    <form onSubmit={onSubmit} className="space-y-5 rounded-lg border border-neutral-200 bg-white p-5">
      {expired && !error ? <Alert>{t("errors.session_expired")}</Alert> : null}
      {error ? <Alert>{errorText.message(error)}</Alert> : null}
      <Field label={t("login.phone")} error={errorText.field(error, "phone")}>
        {(props) => (
          <TextInput
            {...props}
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
            type="password"
            autoComplete="current-password"
            required
            value={password}
            onChange={(e) => setPassword(e.target.value)}
          />
        )}
      </Field>
      <Button type="submit" className="w-full" disabled={pending}>
        {pending ? t("login.submitting") : t("login.submit")}
      </Button>
    </form>
  );
}
