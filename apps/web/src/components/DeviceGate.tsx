"use client";

import { useTranslations } from "next-intl";
import { useEffect, useRef, type ReactNode } from "react";
import { usePathname } from "@/i18n/navigation";
import { deviceId, shortDeviceId, useDeviceGate, type DeviceRefusal } from "@/lib/device";
import { useErrorText } from "@/lib/errors";
import { ApiError } from "@/lib/api/client";
import { can, useSession } from "@/lib/session";
import { EnrolThisDevice } from "./devices/EnrolThisDevice";
import { Alert, Button } from "./ui";

/**
 * The "not enrolled" screen (step 5.1b; ADR 0017 and its 2026-10-29 note). When a call of the page came back 403 identity.device.unknown,
 * revoked or other_branch (api/client.ts reports it), the page is replaced by this screen: the station screens on a tablet nobody enrolled,
 * and the admin pages that read the same routes (the order list, a shift, a count) on a computer nobody enrolled, alike. Going to another
 * page clears it; that page shows it again only if its own calls are refused. Someone who holds devices.manage enrols the device right
 * here, and the page starts again.
 */
export function DeviceGate({ children }: { children: ReactNode }) {
  const pathname = usePathname();
  const refusal = useDeviceGate((s) => s.refusal);
  const clear = useDeviceGate((s) => s.clear);
  const shownFor = useRef(pathname);

  useEffect(() => {
    if (shownFor.current === pathname) return;
    shownFor.current = pathname;
    clear();
  }, [pathname, clear]);

  return refusal ? <NotEnrolled refusal={refusal} /> : <>{children}</>;
}

function NotEnrolled({ refusal }: { refusal: DeviceRefusal }) {
  const t = useTranslations("deviceGate");
  const errorText = useErrorText();
  const me = useSession((s) => s.me);
  const admin = can(me, "devices.manage");
  const reason = errorText.message(new ApiError(403, { message_key: refusal.key, message_en: refusal.messages.en, message_am: refusal.messages.am }));

  return (
    <section aria-labelledby="device-gate-title" className="max-w-2xl space-y-5">
      <header className="space-y-2">
        <h1 id="device-gate-title" className="text-2xl font-semibold tracking-tight text-neutral-900">
          {t("title")}
        </h1>
        <p className="text-base text-neutral-700">{t("why")}</p>
      </header>
      <Alert>{reason}</Alert>
      <p className="text-base text-neutral-800">
        {t("code", { code: shortDeviceId(deviceId()) })}
      </p>
      {admin ? (
        <div className="space-y-3 rounded-lg border border-neutral-200 bg-white p-5">
          <p className="text-base text-neutral-700">{t("admin_here")}</p>
          <EnrolThisDevice renew={refusal.key === "identity.device.other_branch"} onEnrolled={() => window.location.reload()} />
        </div>
      ) : (
        <p className="text-base text-neutral-700">{t("ask")}</p>
      )}
      <Button variant="secondary" onClick={() => window.location.reload()}>
        {t("retry")}
      </Button>
    </section>
  );
}
