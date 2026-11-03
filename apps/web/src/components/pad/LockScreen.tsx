"use client";

import { LockKeyhole } from "lucide-react";
import { useTranslations } from "next-intl";
import { useCallback, useEffect, useState } from "react";
import { useActing } from "@/lib/acting";
import { useErrorText } from "@/lib/errors";
import { Alert, Button } from "../ui";
import { focusRing } from "../styles";

// The pad when nobody has it (fresh-counter.md 3.1's keypad, placed here by phase-6 plan 10.2 Q1): a 3 x 4 keypad of 72 px keys takes
// the waiter's short code (4 to 6 digits), shown as dots, never as digits. A wrong code is refused with the API message in the page
// language; after five wrong codes the API answers 423 and the pad says so. Nothing of the last waiter is shown here, only why the pad
// locked.

const MAX_DIGITS = 6;
const MIN_DIGITS = 4;
const DIGITS = ["1", "2", "3", "4", "5", "6", "7", "8", "9"] as const;

// 72 px keys (fresh-counter.md 3.1), the digits at 28 px
const key = `inline-flex h-18 items-center justify-center rounded-2xl border border-neutral-200 bg-white font-semibold text-neutral-900 hover:bg-neutral-50 active:bg-neutral-100 disabled:cursor-not-allowed disabled:text-neutral-400 motion-safe:transition-colors motion-safe:duration-150 ${focusRing}`;
const digitKey = `${key} text-[28px] tabular-nums`;

export function LockScreen() {
  const t = useTranslations("pad");
  const errorText = useErrorText();
  const unlock = useActing((s) => s.unlock);
  const lockedBy = useActing((s) => s.lockedBy);
  const [code, setCode] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);

  const press = useCallback((digit: string) => setCode((current) => (current.length < MAX_DIGITS ? current + digit : current)), []);

  const submit = useCallback(async () => {
    if (busy || code.length < MIN_DIGITS) return;
    setBusy(true);
    setError(null);
    try {
      await unlock(code);
    } catch (failure) {
      setError(failure);
    } finally {
      // a code is never kept on screen after it was tried
      setCode("");
      setBusy(false);
    }
  }, [busy, code, unlock]);

  // a keyboard on the pad (or a test) types the same code
  useEffect(() => {
    function onKey(event: KeyboardEvent) {
      if (event.target instanceof HTMLElement && ["INPUT", "TEXTAREA", "SELECT"].includes(event.target.tagName)) return;
      if (/^\d$/.test(event.key)) press(event.key);
      else if (event.key === "Backspace") setCode((current) => current.slice(0, -1));
      else if (event.key === "Enter" && event.target instanceof HTMLElement && event.target.tagName !== "BUTTON") void submit();
    }
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [press, submit]);

  // a dot per digit typed, and empty ones up to the shortest code
  const slots = Math.max(MIN_DIGITS, code.length);

  return (
    <section aria-labelledby="pad-lock-title" className="mx-auto w-full max-w-sm space-y-5 rounded-[20px] border border-neutral-200 bg-white p-6 sm:p-8">
      <div className="flex flex-col items-center gap-3 text-center">
        <span aria-hidden="true" className="inline-flex size-12 items-center justify-center rounded-full bg-brand-50 text-brand-700">
          <LockKeyhole className="size-6" strokeWidth={1.75} aria-hidden="true" />
        </span>
        <h2 id="pad-lock-title" className="text-2xl font-bold tracking-tight text-neutral-900">
          {t("enter_code")}
        </h2>
      </div>
      {lockedBy === "idle" || lockedBy === "expired" ? (
        <p role="status" className="rounded-xl bg-neutral-100 px-4 py-3 text-center text-[15px] text-neutral-700">
          {lockedBy === "idle" ? t("lock_idle") : t("lock_expired")}
        </p>
      ) : null}
      <output aria-label={t("code_label")} aria-live="polite" className="flex min-h-10 items-center justify-center gap-3.5">
        <span className="sr-only">{t("code_entered", { count: code.length })}</span>
        {Array.from({ length: slots }, (_, index) => (
          <span
            key={index}
            aria-hidden="true"
            className={`size-3.5 rounded-full motion-safe:transition-colors motion-safe:duration-150 ${index < code.length ? "bg-neutral-900" : "border-2 border-neutral-300"}`}
          />
        ))}
      </output>
      {error ? <Alert>{errorText.message(error)}</Alert> : null}
      <div className="grid grid-cols-3 gap-3">
        {DIGITS.map((digit) => (
          <button key={digit} type="button" className={digitKey} onClick={() => press(digit)} disabled={busy}>
            {digit}
          </button>
        ))}
        <button type="button" aria-label={t("clear_aria")} className={`${key} text-base`} onClick={() => setCode("")} disabled={busy}>
          {t("clear")}
        </button>
        <button type="button" className={digitKey} onClick={() => press("0")} disabled={busy}>
          0
        </button>
        <Button type="button" size="xl" compact className="h-18" onClick={() => void submit()} disabled={busy || code.length < MIN_DIGITS}>
          {busy ? t("checking") : t("unlock")}
        </Button>
      </div>
    </section>
  );
}
