import { subscribePush } from "./api/notifications";
import { uuid } from "./api/idempotency";

// "Enable notifications": asks the browser for permission, subscribes the shell worker (public/push-sw.js shows what the
// API pushes) with the API's public VAPID key and gives the subscription to the API. Offered only when the API says push
// is on (GET /notifications/channels); on the live host it is off.

export type PushOutcome = "subscribed" | "denied" | "unsupported";

function keyBytes(base64Url: string): Uint8Array<ArrayBuffer> {
  const padded = base64Url.replace(/-/g, "+").replace(/_/g, "/").padEnd(Math.ceil(base64Url.length / 4) * 4, "=");
  const raw = atob(padded);
  const bytes = new Uint8Array(raw.length);
  for (let i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i);
  return bytes;
}

export async function enablePush(publicKey: string): Promise<PushOutcome> {
  if (typeof window === "undefined" || !("serviceWorker" in navigator) || !("PushManager" in window) || !("Notification" in window)) return "unsupported";
  const base = process.env.NEXT_PUBLIC_BASE_PATH ?? "";
  const registration = await navigator.serviceWorker.getRegistration(`${base}/`);
  if (!registration) return "unsupported";
  if ((await Notification.requestPermission()) !== "granted") return "denied";
  const subscription = (await registration.pushManager.getSubscription()) ?? (await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(publicKey) }));
  const json = subscription.toJSON();
  if (!json.endpoint || !json.keys?.p256dh || !json.keys.auth) return "unsupported";
  await subscribePush({ endpoint: json.endpoint, keys: { p256dh: json.keys.p256dh, auth: json.keys.auth } }, uuid());
  return "subscribed";
}
