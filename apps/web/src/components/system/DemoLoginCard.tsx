"use client";

import { LogIn } from "lucide-react";
import { useTranslations } from "next-intl";
import { useId, useState } from "react";
import { Link } from "@/i18n/navigation";
import { useDemoLoginSwitch, useSaveDemoLoginSwitch } from "@/lib/api/demoLoginSwitch";
import { useIdempotencyKey } from "@/lib/api/idempotency";
import { useErrorText } from "@/lib/errors";
import { CARD, Notice, TEXT_LINK } from "../admin/kit";
import { ICON_STROKE } from "../icons";
import { Alert, Chip, Status, Switch } from "../ui";

// TEMPORARY (ADR 0025, Ramin 2026-11-12): Admin > System > Demo sign-in buttons. The demo bakery's admin turns the sign-in page's one-click
// demo buttons on and off here, in the browser (PUT /brand/demo-login), instead of editing the server's .env. Shown only to the admin of
// the demo branch (available). The switch moves at once and goes back if the server refuses it. When the server's BAKERY_DEMO_LOGIN=false
// forces the buttons off, the switch reads off and cannot be moved, and the card says why. Removal: delete this file, its line in
// SystemScreen.tsx, lib/api/demoLoginSwitch.ts and the `system.demo_login` messages, with the rest of the demo sign-in (ADR 0025).

export function DemoLoginCard() {
  const t = useTranslations("system.demo_login");
  const errorText = useErrorText();
  const state = useDemoLoginSwitch();
  const save = useSaveDemoLoginSwitch();
  const key = useIdempotencyKey();
  const titleId = useId();
  const [said, setSaid] = useState<string | null>(null);
  const view = state.data;
  if (!view?.available) return null;
  const on = view.on && !view.forced_off;

  function turn(next: boolean) {
    if (save.isPending) return;
    setSaid(null);
    save.mutate(
      { on: next, idempotencyKey: key.keyFor({ on: next }) },
      {
        onSuccess: () => {
          key.done();
          setSaid(t(next ? "saved_on" : "saved_off"));
        },
      },
    );
  }

  return (
    <section data-testid="system-demo-login" aria-labelledby={titleId} className={`${CARD} flex flex-col gap-4 p-5`}>
      <div className="flex items-start gap-3">
        <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-700" aria-hidden="true">
          <LogIn className="size-5" strokeWidth={ICON_STROKE} />
        </span>
        <div className="min-w-0 flex-1 space-y-1">
          <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
            <h2 id={titleId} className="text-[17px] font-semibold text-neutral-900">
              {t("title")}
            </h2>
            <Chip tone="amber">{t("temporary")}</Chip>
          </div>
          <p className="text-[15px] text-neutral-600">{t("intro")}</p>
        </div>
      </div>

      <div className="rounded-xl border border-neutral-200 bg-neutral-50 px-4 py-3">
        <Switch
          label={t("switch")}
          hint={on ? t("hint_on") : t("hint_off")}
          checked={on}
          disabled={view.forced_off || save.isPending}
          onChange={turn}
        />
      </div>

      {view.forced_off ? <Notice tone="amber">{t("forced_off")}</Notice> : null}

      <Link href="/login" className={`${TEXT_LINK} inline-flex min-h-11 w-fit items-center`}>
        {t("see_login")}
      </Link>

      {save.error ? <Alert>{errorText.message(save.error)}</Alert> : null}
      <Status>{said}</Status>
    </section>
  );
}
