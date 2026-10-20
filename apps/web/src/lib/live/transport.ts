import { API_BASE } from "../api/client";
import { useSession } from "../session";
import type { LiveTransport, TransportController, TransportHandle } from "./feed";

// How a feed learns that it is time to look again (ADR 0009).
//
// poll (default, the live host and CI): a timer. About 2 s while the tab is visible, slower while it is hidden,
// paused while the device is offline and resumed on `online`, exponential back-off after errors, an immediate look on
// focus and on the screen own write (feed.refresh()).
//
// reverb / pusher: Laravel Echo on the private channels of the plan (section 5) through POST /broadcasting/auth. Every
// event only kicks a look; the conditional GET stays the source of truth. A slow poll runs underneath as a safety net
// (a socket that silently died costs 30 s, not a ticket). Echo and pusher-js are imported lazily, so the default bundle
// does not carry them; if they cannot start, the screen simply keeps the slow poll.

export interface PollOptions {
  intervalMs: number;
  /** the interval while the tab is hidden */
  hiddenMs: number;
  /** the longest wait after errors */
  maxBackoffMs: number;
}

export const DEFAULT_POLL: PollOptions = { intervalMs: 2000, hiddenMs: 10_000, maxBackoffMs: 10_000 };

/** The wait after `failures` failed looks in a row: the interval doubled each time, up to the cap. */
export function backoffDelay(failures: number, options: PollOptions): number {
  return Math.min(options.maxBackoffMs, options.intervalMs * 2 ** failures);
}

export function pollTransport(options: PollOptions = DEFAULT_POLL): LiveTransport {
  return {
    start(controller: TransportController): TransportHandle {
      let timer: ReturnType<typeof setTimeout> | null = null;
      let stopped = false;
      let failures = 0;

      const online = () => typeof navigator === "undefined" || navigator.onLine !== false;
      const hidden = () => typeof document !== "undefined" && document.visibilityState === "hidden";
      const clear = () => {
        if (timer !== null) clearTimeout(timer);
        timer = null;
      };

      const schedule = () => {
        clear();
        if (stopped || !online()) return; // paused offline; the `online` event resumes
        const delay = failures > 0 ? backoffDelay(failures, options) : hidden() ? options.hiddenMs : options.intervalMs;
        timer = setTimeout(() => void look(), delay);
      };

      const look = async () => {
        clear();
        if (stopped) return;
        const result = await controller.poll();
        if (stopped) return;
        failures = result === "failed" ? failures + 1 : 0;
        schedule();
      };

      const kick = () => {
        failures = 0;
        void look();
      };
      const onOnline = () => kick();
      const onOffline = () => clear();
      const onVisible = () => {
        if (!hidden()) kick();
      };

      window.addEventListener("online", onOnline);
      window.addEventListener("offline", onOffline);
      document.addEventListener("visibilitychange", onVisible);
      void look();

      return {
        kick,
        stop() {
          stopped = true;
          clear();
          window.removeEventListener("online", onOnline);
          window.removeEventListener("offline", onOffline);
          document.removeEventListener("visibilitychange", onVisible);
        },
      };
    },
  };
}

// --- reverb / pusher -------------------------------------------------------------------------------------------

/** The slice of Laravel Echo this adapter uses (its generic option types are not worth importing). */
interface EchoLike {
  private(name: string): { listenToAll(callback: (event: string, data: unknown) => void): unknown };
  connector?: { pusher?: { connection?: { bind(event: string, callback: () => void): void } } };
  disconnect(): void;
}

type EchoOptions = Record<string, unknown>;

const SAFETY_POLL: PollOptions = { intervalMs: 30_000, hiddenMs: 60_000, maxBackoffMs: 60_000 };

function authorizer(channel: { name: string }) {
  return {
    authorize(socketId: string, callback: (error: Error | null, data: unknown) => void) {
      const token = useSession.getState().token;
      fetch(`${API_BASE}/broadcasting/auth`, {
        method: "POST",
        headers: { Accept: "application/json", "Content-Type": "application/json", ...(token ? { Authorization: `Bearer ${token}` } : {}) },
        body: JSON.stringify({ socket_id: socketId, channel_name: channel.name }),
        cache: "no-store",
      })
        .then(async (response) => {
          if (!response.ok) throw new Error(`broadcasting/auth ${response.status}`);
          callback(null, await response.json());
        })
        .catch((error: unknown) => callback(error instanceof Error ? error : new Error(String(error)), null));
    },
  };
}

function echoOptions(kind: "reverb" | "pusher"): EchoOptions {
  if (kind === "reverb") {
    const scheme = process.env.NEXT_PUBLIC_REVERB_SCHEME ?? "http";
    return {
      broadcaster: "reverb",
      key: process.env.NEXT_PUBLIC_REVERB_APP_KEY ?? "bakery-ops",
      wsHost: process.env.NEXT_PUBLIC_REVERB_HOST ?? window.location.hostname,
      wsPort: Number(process.env.NEXT_PUBLIC_REVERB_PORT ?? 8080),
      wssPort: Number(process.env.NEXT_PUBLIC_REVERB_PORT ?? 443),
      forceTLS: scheme === "https",
      enabledTransports: ["ws", "wss"],
      authorizer,
    };
  }
  return {
    broadcaster: "pusher",
    key: process.env.NEXT_PUBLIC_PUSHER_KEY ?? "",
    cluster: process.env.NEXT_PUBLIC_PUSHER_CLUSTER ?? "mt1",
    forceTLS: true,
    authorizer,
  };
}

/** Echo on the given bare channel names ("station.5"); every event, and every (re)connect, kicks a look. */
export function echoTransport(kind: "reverb" | "pusher", channels: string[]): LiveTransport {
  return {
    start(controller: TransportController): TransportHandle {
      const poller = pollTransport(SAFETY_POLL).start(controller);
      let stopped = false;
      let echo: EchoLike | null = null;

      void (async () => {
        try {
          const [{ default: Echo }, { default: Pusher }] = await Promise.all([import("laravel-echo"), import("pusher-js")]);
          if (stopped) return;
          (window as unknown as { Pusher: unknown }).Pusher = Pusher;
          const create = Echo as unknown as new (options: EchoOptions) => EchoLike;
          echo = new create(echoOptions(kind));
          for (const name of channels) echo.private(name).listenToAll(() => poller.kick());
          echo.connector?.pusher?.connection?.bind("connected", () => poller.kick());
        } catch (error) {
          // the slow poll keeps the screen right; say why the live channel is not there
          console.warn(`live transport "${kind}" could not start; polling only`, error);
        }
      })();

      return {
        kick: poller.kick,
        stop() {
          stopped = true;
          poller.stop();
          echo?.disconnect();
          echo = null;
        },
      };
    },
  };
}

export type TransportName = "poll" | "reverb" | "pusher";

export function configuredTransport(): TransportName {
  const name = process.env.NEXT_PUBLIC_LIVE_TRANSPORT;
  return name === "reverb" || name === "pusher" ? name : "poll";
}

/** The transport this build was configured with, for a feed over the given private channels. */
export function createTransport(channels: string[], name: TransportName = configuredTransport()): LiveTransport {
  return name === "poll" ? pollTransport() : echoTransport(name, channels);
}
