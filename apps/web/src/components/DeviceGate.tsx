"use client";

import { TabletSmartphone } from "lucide-react";
import { useTranslations } from "next-intl";
import { useEffect, useRef, type ReactNode } from "react";
import { usePathname } from "@/i18n/navigation";
import { deviceId, shortDeviceId, useDeviceGate, type DeviceRefusal } from "@/lib/device";
import { useErrorText } from "@/lib/errors";
import { ApiError } from "@/lib/api/client";
import { can, useSession } from "@/lib/session";
import { EnrolThisDevice } from "./devices/EnrolThisDevice";
import { Alert, Button, EmptyState } from "./ui";

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

  // step 6.2b: the screen is an EmptyState (its heading the page's h1) on a white card, with the reason, the device's code and the way on
  return (
    <section aria-labelledby="device-gate-title" className="mx-auto max-w-2xl rounded-[20px] border border-neutral-200 bg-white">
      <EmptyState level={1} headingId="device-gate-title" icon={TabletSmartphone} title={t("title")} body={t("why")} className="pb-6" />
      <div className="space-y-5 border-t border-neutral-200 px-6 py-6">
        <Alert>{reason}</Alert>
        <p className="text-base font-semibold tabular-nums text-neutral-900">{t("code", { code: shortDeviceId(deviceId()) })}</p>
        {admin ? (
          <div className="space-y-3 rounded-2xl border border-neutral-200 bg-neutral-50 p-5">
            <p className="text-base text-neutral-700">{t("admin_here")}</p>
            <EnrolThisDevice renew={refusal.key === "identity.device.other_branch"} onEnrolled={() => window.location.reload()} />
          </div>
        ) : (
          <p className="text-base text-neutral-700">{t("ask")}</p>
        )}
        <Button variant="secondary" onClick={() => window.location.reload()}>
          {t("retry")}
        </Button>
      </div>
    </section>
  );
}
