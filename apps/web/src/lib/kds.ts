// The kitchen and bar displays: the one place for their thresholds, the timer text and the chime.

/** A ticket turns amber after this many seconds since it was sent, red after the second (one constant, one place). */
export const TICKET_AGE_SECONDS = { amber: 300, red: 600 } as const;

export type TicketAge = "fresh" | "amber" | "red";

export function ageOf(seconds: number): TicketAge {
  return seconds >= TICKET_AGE_SECONDS.red ? "red" : seconds >= TICKET_AGE_SECONDS.amber ? "amber" : "fresh";
}

/** Seconds since an ISO instant, never negative (a device clock a little behind the server must not show -0:03). */
export function secondsSince(iso: string | null, now: number): number {
  if (!iso) return 0;
  const sent = Date.parse(iso);
  return Number.isNaN(sent) ? 0 : Math.max(0, Math.floor((now - sent) / 1000));
}

/** 75 -> "1:15", 3725 -> "62:05": minutes can pass an hour, a rail ticket that old needs to look old. */
export function clock(seconds: number): string {
  const minutes = Math.floor(seconds / 60);
  return `${minutes}:${String(seconds % 60).padStart(2, "0")}`;
}

// --- the chime: a generated tone (Web Audio), no audio file; muted or not is remembered per device ---------------

const MUTE_KEY = "bakery-ops.kds.muted";

export function storedMuted(): boolean {
  try {
    return window.localStorage.getItem(MUTE_KEY) === "1";
  } catch {
    return false;
  }
}

export function storeMuted(muted: boolean): void {
  try {
    if (muted) window.localStorage.setItem(MUTE_KEY, "1");
    else window.localStorage.removeItem(MUTE_KEY);
  } catch {
    // storage blocked: the choice lasts until the page is closed
  }
}

let context: AudioContext | null = null;

function audio(): AudioContext | null {
  if (context) return context;
  const Ctor = window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;
  if (!Ctor) return null;
  try {
    context = new Ctor();
  } catch {
    context = null;
  }
  return context;
}

/** Browsers start audio only after a tap: call this from the first pointer or key event on the screen. */
export function unlockAudio(): void {
  const ctx = audio();
  if (ctx && ctx.state === "suspended") void ctx.resume().catch(() => {});
}

/** Two short rising notes. Silent (and harmless) when audio is blocked or not yet unlocked. */
export function playChime(): void {
  const ctx = audio();
  if (!ctx || ctx.state !== "running") return;
  const start = ctx.currentTime;
  [660, 880].forEach((frequency, index) => {
    const oscillator = ctx.createOscillator();
    const gain = ctx.createGain();
    const at = start + index * 0.18;
    oscillator.type = "sine";
    oscillator.frequency.value = frequency;
    gain.gain.setValueAtTime(0.0001, at);
    gain.gain.exponentialRampToValueAtTime(0.25, at + 0.02);
    gain.gain.exponentialRampToValueAtTime(0.0001, at + 0.3);
    oscillator.connect(gain).connect(ctx.destination);
    oscillator.start(at);
    oscillator.stop(at + 0.32);
  });
}
