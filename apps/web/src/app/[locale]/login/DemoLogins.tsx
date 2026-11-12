"use client";

import { useLocale, useTranslations } from "next-intl";
import { useEffect, useState } from "react";
import { ICON_STROKE, ROLE_ICONS } from "@/components/icons";
import { buttonClass } from "@/components/styles";
import { Alert, Chip } from "@/components/ui";
import { request } from "@/lib/api/client";
import { uuid } from "@/lib/api/idempotency";
import { ROLES, type LoginResponse, type Role } from "@/lib/api/types";
import { useErrorText } from "@/lib/errors";
import { useFinishSignIn } from "./LoginForm";

/** One demo person the sign-in page offers (GET /demo-logins). */
export interface DemoPerson {
  role: Role;
  name_en: string;
  name_am: string;
}

/**
 * TEMPORARY (ADR 0025, Ramin 2026-11-12): one button per role that signs its demo person in without a password, so the owner can look
 * at every role's screens. The section is drawn only when GET /demo-logins answers (the server's BAKERY_DEMO_LOGIN is on); any other
 * answer (404 when it is off, a network failure) draws nothing, and the page is the plain sign-in page. Removal: delete this file and its
 * line in page.tsx, the `login.demo` messages and ROLE_ICONS, with the API side (App\Modules\Identity\Demo\DemoLogins).
 */
export function DemoLogins() {
  const t = useTranslations();
  const locale = useLocale();
  const errorText = useErrorText();
  const finish = useFinishSignIn();
  const [people, setPeople] = useState<DemoPerson[]>([]);
  const [pending, setPending] = useState<Role | null>(null);
  const [error, setError] = useState<unknown>(null);

  useEffect(() => {
    const controller = new AbortController();
    request<{ data: DemoPerson[] }>("/demo-logins", { signal: controller.signal, deviceGate: false })
      .then((answer) => setPeople(answer.data.filter((p) => (ROLES as readonly string[]).includes(p.role))))
      .catch(() => setPeople([]));
    return () => controller.abort();
  }, []);

  if (people.length === 0) return null;

  async function signInAs(role: Role) {
    setPending(role);
    setError(null);
    try {
      const login = await request<LoginResponse>("/demo-login", { method: "POST", body: { role }, idempotencyKey: uuid(), deviceGate: false });
      finish(login);
    } catch (e) {
      setError(e);
      setPending(null);
    }
  }

  return (
    <section aria-labelledby="demo-logins-title" className="mt-8 border-t border-neutral-200 pt-6" data-testid="demo-logins">
      <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
        <h2 id="demo-logins-title" className="text-base font-semibold text-neutral-900">
          {t("login.demo.title")}
        </h2>
        <Chip>{t("login.demo.temporary")}</Chip>
      </div>
      <p className="mt-1.5 text-sm leading-relaxed text-neutral-600">{t("login.demo.intro")}</p>
      {error ? (
        <div className="mt-3">
          <Alert>{errorText.message(error)}</Alert>
        </div>
      ) : null}
      <ul className="mt-4 grid grid-cols-2 gap-2.5">
        {people.map((person) => {
          const Icon = ROLE_ICONS[person.role];
          const roleName = t(`people.roles.${person.role}`);
          const name = locale === "am" ? person.name_am : person.name_en;
          const busy = pending === person.role;
          return (
            <li key={person.role} className="min-w-0">
              <button
                type="button"
                onClick={() => void signInAs(person.role)}
                disabled={pending !== null}
                aria-label={`${roleName}, ${name}`}
                aria-busy={busy || undefined}
                data-role={person.role}
                className={`${buttonClass({ variant: "secondary", size: "lg", compact: true })} w-full min-w-0 justify-start! gap-3 py-2 text-left`}
              >
                <span aria-hidden="true" className="inline-flex size-9 shrink-0 items-center justify-center rounded-full bg-ground text-neutral-800">
                  <Icon className="size-[18px]" strokeWidth={ICON_STROKE} />
                </span>
                <span className="flex min-w-0 flex-1 flex-col">
                  <span className="truncate text-sm font-semibold text-neutral-900">{busy ? t("login.submitting") : roleName}</span>
                  <span className="truncate text-[13px] font-normal text-neutral-600">{name}</span>
                </span>
              </button>
            </li>
          );
        })}
      </ul>
    </section>
  );
}
